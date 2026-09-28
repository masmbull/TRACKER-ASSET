<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentRegistrationController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'device_id' => 'required|string',
            'name' => 'nullable|string|max:255',
            'platform' => 'nullable|string|max:255',
        ]);

        $deviceId = $request->string('device_id')->toString();

        // Check if device already registered
        $existing = AgentApiToken::where('device_id', $deviceId)->first();
        if ($existing) {
            // A revoked/expired device stays revoked. Re-registering must not be
            // a way around the Revoke button on the Tokens page, otherwise
            // "revoke" is meaningless: the machine would mint itself a new
            // active token on its very next poll.
            if (! $existing->isUsable()) {
                return response()->json([
                    'message' => 'This device was revoked. Re-enable it on the Tokens page first.',
                ], 403);
            }

            // token_plain is an encrypted cast, so a populated column can be
            // handed back as-is.
            if (filled($existing->token_plain)) {
                return response()->json([
                    'token' => $existing->token_plain,
                    'message' => 'Device already registered',
                ]);
            }

            // Active, but the plaintext is gone: the row was created by
            // `audit:token`, which deliberately never stores it. The SHA-256
            // hash is one-way, so the old token cannot be recovered - but the
            // device needs *a* token to keep reporting. Rotate instead of
            // returning '', which used to leave the agent polling with an empty
            // bearer and silently never reaching the server again.
            $rotated = bin2hex(random_bytes(32));
            $existing->update([
                'token_hash' => hash('sha256', $rotated),
                'token_plain' => $rotated,
            ]);

            return response()->json([
                'token' => $rotated,
                'rotated' => true,
                'message' => 'Device re-registered (previous token could not be recovered)',
            ]);
        }

        // Create new token for this device
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $baseName = $request->string('name')->toString() ?: 'Device-'.substr($deviceId, 0, 8);
        $name = $baseName;
        $counter = 1;
        // Ensure unique name
        while (AgentApiToken::where('name', $name)->exists()) {
            $name = $baseName.'-'.$counter++;
        }

        $agentToken = AgentApiToken::create([
            'device_id' => $deviceId,
            'name' => $name,
            'platform' => $request->string('platform')->toString(),
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
            'message' => 'Device registered successfully',
        ]);
    }
}
