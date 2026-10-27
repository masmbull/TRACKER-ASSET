<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentRegistrationController extends Controller
{
    /**
     * Next sequential device number, e.g. MITO-0007.
     *
     * Scans the existing numbers rather than counting rows: a deleted or revoked
     * device must not cause the next laptop to be handed a number that was
     * already in use, and count() would happily reuse MITO-0003 after MITO-0003
     * was removed. Gaps are therefore permanent, which is also what makes a
     * number stable to quote on the phone after a device is retired.
     */
    private static function nextDeviceId(): string
    {
        $prefix = 'MITO-';
        $highest = 0;

        AgentApiToken::query()
            ->where('device_id', 'like', $prefix.'%')
            ->pluck('device_id')
            ->each(function (string $id) use ($prefix, &$highest) {
                $digits = substr($id, strlen($prefix));

                if (ctype_digit($digits)) {
                    $highest = max($highest, (int) $digits);
                }
            });

        return $prefix.str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT);
    }

    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'device_id' => 'required|string',
            'hardware_id' => 'nullable|string|max:191',
            'name' => 'nullable|string|max:255',
            'platform' => 'nullable|string|max:255',
            'agent_version' => 'nullable|string|max:64',
            'force_new' => 'sometimes|boolean',
            'check_only' => 'sometimes|boolean',
        ]);

        $deviceId = $request->string('device_id')->toString();
        $checkOnly = $request->boolean('check_only');

        // force_new: every install run mints a fresh token, even when a device
        // row already exists for this hardware. Revoked devices are re-enabled
        // with a new token — reinstalling must never be blocked by a previous
        // revocation, and every .exe run gets its own credential.
        $forceNew = $request->boolean('force_new');

        // What the agent generated for itself, kept separately so this row stays
        // findable once the server has replaced device_id with a number a human
        // can read. Without it a reinstall would mint a second row for the same
        // laptop - the exact duplication the sequential id exists to prevent.
        $hardwareId = $request->filled('hardware_id')
            ? $request->string('hardware_id')->toString()
            : $deviceId;

        // check_only: dipanggil sebelum menampilkan 10 field audit. Jika device
        // sudah dikenal di master data (hardware_id cocok), skip 10 field karena
        // data sudah ada. Jika belum, tampilkan 10 field untuk diisi user.
        if ($checkOnly) {
            $existing = AgentApiToken::where('hardware_id', $hardwareId)->first()
                ?? AgentApiToken::where('device_id', $deviceId)->first();

            return response()->json([
                'known' => $existing !== null,
                'skip_fields' => $existing !== null,
                'device_id' => $existing?->device_id ?? $deviceId,
                'token' => $existing?->token_plain ?? null,
                'is_active' => $existing?->is_active ?? false,
                'message' => $existing !== null
                    ? 'Device sudah dikenal di master data. Lewati 10 field audit.'
                    : 'Device baru. Tampilkan 10 field audit.',
            ]);
        }

        // Fresh token helper: rotate an existing row (or keep it) and return
        // the plaintext so the agent can store it in config.json.
        $mintFresh = function (AgentApiToken $row) use ($request): array {
            $token = bin2hex(random_bytes(32));
            $row->update([
                'token_hash' => hash('sha256', $token),
                'token_plain' => $token,
                'agent_version' => $request->string('agent_version')->toString() ?: $row->agent_version,
                'last_seen_at' => now(),
                'is_active' => true,
                'expires_at' => null,
            ]);
            AgentCommand::create([
                'agent_token_id' => $row->id,
                'command' => 'scan',
                'payload' => null,
                'status' => 'pending',
            ]);
            return [
                'token' => $token,
                'device_id' => $row->device_id,
                'rotated' => true,
                'message' => 'Token rotated for reinstall',
            ];
        };

// Check if device already registered
        $existing = AgentApiToken::where('hardware_id', $hardwareId)->first()
            ?? AgentApiToken::where('device_id', $deviceId)->first();
        if ($existing) {
            // Revoked device: allow reinstall by rotating token and reactivating.
            // This lets a machine that was revoked come back online after a fresh
            // agent install without manual intervention on the Tokens page.
            if (! $existing->is_active) {
                return response()->json($mintFresh($existing));
            }

            // Active device: force_new rotates the token for reinstall.
            if ($forceNew) {
                return response()->json($mintFresh($existing));
            }

            // token_plain is an encrypted cast, so a populated column can be
            // handed back as-is.
            if (filled($existing->token_plain)) {
                return response()->json([
                    'token' => $existing->token_plain,
                    // Handed back on every path, so an agent that stored a random
                    // id from before this change adopts the readable number the
                    // first time it registers again instead of being stuck with
                    // "Device-k3Jd8fPq" forever.
                    'device_id' => $existing->device_id,
                    'message' => 'Device already registered',
                ]);
            }

            // Active, but the plaintext is gone: the row was created by
            // `audit:token`, which deliberately never stores it. The SHA-256
            // hash is one-way, so the old token cannot be recovered - but the
            // device needs *a* token to keep reporting. Rotate instead of
            // returning '', which used to leave the agent polling with an empty
            // bearer and silently never reaching the server again.
            return response()->json($mintFresh($existing));
        }

        // Create new token for this device
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        // Sequential, human-readable device number: MITO-0001, MITO-0002, ...
        //
        // A random id was unreadable across a room ("Device-k3Jd8fPq") and gave
        // an operator nothing to quote over the phone. Derived from the highest
        // number already issued rather than from a row count, so deleting a
        // device cannot make the next one reuse its number.
        $assignedDeviceId = self::nextDeviceId();

        $baseName = $request->string('name')->toString() ?: 'Device-'.$assignedDeviceId;
        $name = $baseName;
        $counter = 1;
        // Ensure unique name
        while (AgentApiToken::where('name', $name)->exists()) {
            $name = $baseName.'-'.$counter++;
        }

        $agentToken = AgentApiToken::create([
            'device_id' => $assignedDeviceId,
            'hardware_id' => $hardwareId,
            'name' => $name,
            'platform' => $request->string('platform')->toString(),
            'agent_version' => $request->string('agent_version')->toString() ?: null,
            'last_seen_at' => now(),
            'token_hash' => $tokenHash,
            'token_plain' => $token,
            'is_active' => true,
        ]);

        // Queue a scan immediately, so a freshly enrolled device appears in the
        // fleet within seconds instead of waiting for somebody to notice it was
        // missing.
        //
        // This is not a nicety. The heartbeat endpoint deliberately refuses to
        // invent a telemetry row: with no snapshot it answers 202 "run a full
        // scan". Nothing else queues that scan - only the weekly audit sweep and
        // a manual button on the fleet page - so a device that enrolled on a
        // Monday reported nothing at all until the sweep ran days later, and the
        // fleet looked like the deployment had simply failed. The agent then
        // treats 202 as success, so nothing anywhere reported a problem.
        AgentCommand::create([
            'agent_token_id' => $agentToken->id,
            'command' => 'scan',
            'payload' => null,
            'status' => 'pending',
        ]);

        return response()->json([
            'token' => $token,
            // The server-assigned number, which the agent stores and reports
            // from then on. The agent's own random id stays in hardware_id and
            // is never shown to anyone.
            'device_id' => $assignedDeviceId,
            'message' => 'Device registered successfully',
        ]);
    }
}
