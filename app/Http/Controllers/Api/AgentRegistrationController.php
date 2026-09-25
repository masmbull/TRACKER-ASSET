<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentApiToken;
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
            return response()->json([
                'token' => $existing->token_plain ?? '',
                'message' => 'Device already registered',
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

        return response()->json([
            'token' => $token,
            'message' => 'Device registered successfully',
        ]);
    }
}
