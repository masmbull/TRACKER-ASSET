<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InternalScriptCommandController extends Controller
{
    public function dispatch(Request $request): JsonResponse
    {
        $configuredToken = (string) config('services.internal.script_token', '');
        $providedToken = (string) $request->bearerToken();

        if ($configuredToken === '' || $providedToken === '') {
            return response()->json([
                'message' => 'Internal API token is not configured or was not supplied.',
            ], 503);
        }

        if (! hash_equals($configuredToken, $providedToken)) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 401);
        }

        $validated = $request->validate([
            'target' => ['required', Rule::in(['all', 'device'])],
            'agent_token_id' => ['nullable', 'required_if:target,device', 'integer', 'exists:agent_api_tokens,id'],
            'script_name' => ['required', 'string', 'max:255'],
            'script_type' => ['required', Rule::in(['powershell', 'cmd', 'batch', 'ps1', 'bat', 'exe'])],
            'script_body' => ['required', 'string'],
            'working_directory' => ['nullable', 'string', 'max:255'],
            'run_as' => ['nullable', Rule::in(['current-user', 'admin', 'system'])],
            'timeout_seconds' => ['nullable', 'integer', 'min:10', 'max:3600'],
            'sha256' => ['nullable', 'string', 'max:128'],
            'trigger' => ['nullable', Rule::in(['once', 'reboot'])],
            'notes' => ['nullable', 'string', 'max:1000'],
            'source' => ['nullable', 'string', 'max:255'],
        ]);

        $tokenId = $validated['target'] === 'device'
            ? (int) $validated['agent_token_id']
            : null;

        $command = AgentCommand::create([
            'agent_token_id' => $tokenId,
            'command' => 'script',
            'payload' => [
                'script_name' => $validated['script_name'],
                'script_type' => $validated['script_type'],
                'script_body' => $validated['script_body'],
                'working_directory' => $validated['working_directory'] ?? null,
                'run_as' => $validated['run_as'] ?? 'current-user',
                'timeout_seconds' => $validated['timeout_seconds'] ?? 300,
                'sha256' => $validated['sha256'] ?? null,
                'trigger' => $validated['trigger'] ?? 'once',
                'notes' => $validated['notes'] ?? null,
                'source' => $validated['source'] ?? 'internal-api',
            ],
            'status' => 'pending',
        ]);

        return response()->json([
            'ok' => true,
            'target' => $validated['target'],
            'command_id' => $command->id,
            'message' => $validated['target'] === 'device'
                ? 'Script queued for the selected device.'
                : 'Script queued for all devices.',
        ], 202);
    }
}
