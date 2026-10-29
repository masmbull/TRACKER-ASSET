<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use App\Models\Asset;
use App\Models\AssetAudit;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use App\Services\AssetCodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AgentRegistrationController extends Controller
{
    /** Lookup data used by the first-install registration form. */
    public function options(): JsonResponse
    {
        return response()->json([
            'companies' => Company::orderBy('name')->get(['id', 'name', 'code']),
            'categories' => AssetCategory::orderBy('name')->get(['id', 'name']),
            'locations' => Location::orderBy('name')->get(['id', 'name']),
            'statuses' => AssetStatus::orderBy('name')->get(['id', 'name']),
            'users' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }

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
            'serial_number' => 'nullable|string|max:255',
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
            $asset = $this->findKnownAsset($existing, $request->input('serial_number'));

            return response()->json([
                'known' => $asset !== null,
                'skip_fields' => $asset !== null,
                'device_id' => $existing?->device_id ?? $deviceId,
                'token' => $existing?->token_plain ?? null,
                'is_active' => $existing?->is_active ?? false,
                'asset_id' => $asset?->id,
                'asset_code' => $asset?->asset_id,
                'message' => $asset !== null
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
            // Issued now, at enrolment, so the agent can configure RustDesk the
            // moment it is installed.
            //
            // Without this the client started unconfigured: it put its own window
            // on the user's desktop showing an id, a banner offering to install the
            // service and "Not ready. Please check your connection" - the opposite
            // of a silent install - and no remote session was possible until an
            // admin happened to press Remote, which is what pushed a password.
            'rustdesk_password' => AgentApiToken::generateRustDeskPassword(),
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
            // Everything RustDesk needs to come up already pointed at us. Sent
            // with enrolment rather than only on the first Remote click, because
            // the install is unattended: by the time an admin presses Remote the
            // client must already be configured.
            'rustdesk' => [
                'server' => config('services.rustdesk.rendezvous'),
                'key' => config('services.rustdesk.public_key'),
                'password' => $agentToken->rustdesk_password,
            ],
            'message' => 'Device registered successfully',
        ]);
    }

    /** Create the selected asset only after registration has issued an API token. */
    public function bootstrapAsset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'serial_number' => 'required|string|max:255',
            'hardware' => 'required|array',
            'hardware.brand' => 'nullable|string|max:255',
            'hardware.model' => 'nullable|string|max:255',
            'hardware.serial_number' => 'required|string|max:255',
            'hardware.hostname' => 'required|string|max:255',
            'hardware.processor' => 'nullable|string|max:255',
            'hardware.storage' => 'nullable|string|max:255',
            'hardware.ram' => 'nullable|string|max:255',
            'hardware.os_version' => 'nullable|string|max:255',
            'hardware.mac_address' => 'nullable|string|max:255',
            'hardware.static_ip' => 'nullable|string|max:255',
            'audit_fields' => 'required|array',
            'audit_fields.company_id' => 'required|exists:companies,id',
            'audit_fields.category_id' => 'required|exists:asset_categories,id',
            'audit_fields.location_id' => 'required|exists:locations,id',
            'audit_fields.asset_status_id' => 'required|exists:asset_statuses,id',
            'audit_fields.current_user_id' => 'nullable|exists:users,id',
            'audit_fields.admin_it_pic' => 'nullable|exists:users,id',
            'audit_fields.purchase_year' => 'required|integer|min:1900|max:'.(date('Y') + 2),
            'audit_fields.vendor' => 'nullable|string|max:255',
            'audit_fields.purchase_price' => 'nullable|numeric|min:0',
        ]);

        $token = AgentApiToken::findOrFail((int) $request->attributes->get('agent_token_id'));
        $asset = $this->createEnrollmentAsset($request, $token);
        if (! $asset) {
            return response()->json(['message' => 'Serial number matches multiple assets; ask IT to resolve the duplicate before enrollment.'], 409);
        }

        return response()->json([
            'ok' => true,
            'asset_id' => $asset->id,
            'asset_code' => $asset->asset_id,
            'message' => 'Device asset registered successfully.',
        ], 201);
    }

    /**
     * A bootstrap token is considered known only when it resolves to a real
     * master asset, not merely because a token row was created by an old install.
     */
    private function findKnownAsset(?AgentApiToken $token, ?string $serialNumber): ?Asset
    {
        if ($token) {
            $assetId = $token->telemetry?->asset_id
                ?? AssetAudit::query()->where('agent_token_id', $token->id)
                    ->whereNotNull('asset_id')->latest('audit_date')->value('asset_id');

            if ($assetId) {
                return Asset::find($assetId);
            }
        }

        $serial = trim((string) $serialNumber);
        if ($serial === '' || in_array(strtolower($serial), ['unknown', 'none', 'to be filled by o.e.m.'], true)) {
            return null;
        }

        $matches = Asset::query()->where('serial_number', $serial)->limit(2)->get();
        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** Create and register a new master asset from the install form, when given. */
    private function createEnrollmentAsset(Request $request, AgentApiToken $token): ?Asset
    {
        $fields = $request->input('audit_fields');
        if (! is_array($fields)) {
            return null;
        }

        $hardware = (array) $request->input('hardware', []);
        $serial = trim((string) ($hardware['serial_number'] ?? $request->input('serial_number', '')));
        if ($serial === '') {
            return null;
        }
        if (trim((string) $request->input('serial_number', $serial)) !== $serial) {
            throw ValidationException::withMessages([
                'serial_number' => 'The reported and asset serial numbers must match.',
            ]);
        }

        $existing = Asset::query()->where('serial_number', $serial)->limit(2)->get();
        if ($existing->count() === 1) {
            $asset = $existing->first();
            $this->attachEnrollmentRecords($token, $asset);
            return $asset;
        }
        if ($existing->count() > 1) {
            return null;
        }

        return DB::transaction(function () use ($fields, $hardware, $serial, $token) {
            $company = Company::findOrFail($fields['company_id']);
            $category = AssetCategory::findOrFail($fields['category_id']);
            $assetCode = app(AssetCodeGenerator::class)->next(
                $company,
                $category,
                (int) $fields['purchase_year'],
            );

            $asset = Asset::create([
                'asset_id' => $assetCode,
                'company_id' => $company->id,
                'category_id' => $category->id,
                'location_id' => $fields['location_id'],
                'asset_status_id' => $fields['asset_status_id'],
                'current_user_id' => $fields['current_user_id'] ?? null,
                'admin_it_pic' => $fields['admin_it_pic'] ?? null,
                'purchase_year' => $fields['purchase_year'],
                'vendor' => $fields['vendor'] ?? null,
                'purchase_price' => $fields['purchase_price'] ?? null,
                'brand' => trim((string) ($hardware['brand'] ?? '')) ?: 'Unknown',
                'model' => trim((string) ($hardware['model'] ?? '')) ?: 'Unknown',
                'serial_number' => $serial,
                'hostname' => trim((string) ($hardware['hostname'] ?? '')) ?: $token->name,
                'processor' => $hardware['processor'] ?? null,
                'storage' => $hardware['storage'] ?? null,
                'ram' => $hardware['ram'] ?? null,
                'os_version' => $hardware['os_version'] ?? null,
                'mac_address' => $hardware['mac_address'] ?? null,
                'static_ip' => $hardware['static_ip'] ?? null,
            ]);

            if ($asset->current_user_id) {
                $asset->assignments()->create([
                    'user_id' => $asset->current_user_id,
                    'assigned_date' => now(),
                    'created_by' => null,
                    'notes' => 'Initial assignment recorded during TrackerAgent installation.',
                ]);
            }

            $this->attachEnrollmentRecords($token, $asset);

            ActivityLog::create([
                'actor_id' => null,
                'action' => 'CREATE',
                'entity_type' => 'assets',
                'entity_id' => $asset->id,
                'entity_identifier' => $asset->asset_id,
                'source' => 'TrackerAgent Installer',
                'old_value' => null,
                'new_value' => json_encode($asset->only([
                    'company_id', 'category_id', 'location_id', 'asset_status_id',
                    'current_user_id', 'admin_it_pic', 'purchase_year', 'vendor', 'purchase_price',
                ])),
                'metadata' => ['agent_token_id' => $token->id, 'via' => 'first-install'],
            ]);

            return $asset;
        });
    }

    /** Connect any scan/telemetry already collected by this token to its asset. */
    private function attachEnrollmentRecords(AgentApiToken $token, Asset $asset): void
    {
        AssetAudit::query()
            ->where('agent_token_id', $token->id)
            ->whereNull('asset_id')
            ->orderByDesc('audit_date')
            ->orderByDesc('id')
            ->limit(1)
            ->update(['asset_id' => $asset->id]);

        $token->telemetry?->update(['asset_id' => $asset->id]);
        $token->fingerprint?->update(['asset_id' => $asset->id]);
    }
}
