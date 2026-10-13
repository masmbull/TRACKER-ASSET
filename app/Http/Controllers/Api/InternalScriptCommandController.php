<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use App\Services\ScriptPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            'target' => ['required', Rule::in(['all', 'device', 'devices'])],
            'agent_token_id' => ['nullable', 'required_if:target,device', 'integer', 'exists:agent_api_tokens,id'],
            'agent_token_ids' => ['nullable', 'required_if:target,devices', 'array'],
            'agent_token_ids.*' => ['integer', 'exists:agent_api_tokens,id'],
            'script_name' => ['nullable', 'string', 'max:255'],
            'script_type' => ['nullable', Rule::in(['powershell', 'cmd', 'batch', 'ps1', 'bat', 'exe'])],
            'script_body' => ['required', 'string'],
            'working_directory' => ['nullable', 'string', 'max:255'],
            'run_as' => ['nullable', Rule::in(['current-user', 'admin', 'system'])],
            'timeout_seconds' => ['nullable', 'integer', 'min:10', 'max:3600'],
            'sha256' => ['nullable', 'string', 'max:128'],
            'trigger' => ['nullable', Rule::in(['once', 'reboot'])],
            'notes' => ['nullable', 'string', 'max:1000'],
            'source' => ['nullable', 'string', 'max:255'],
        ]);

        $targetIds = match ($validated['target']) {
            'all' => [null],
            'device' => [(int) $validated['agent_token_id']],
            'devices' => array_map('intval', $validated['agent_token_ids'] ?? []),
            default => [null],
        };

        // Same single-source rule as the web form: the body decides the type and
        // the integrity hash. A caller that still posts script_type/sha256 is
        // VERIFIED against the body rather than trusted - honouring them let an
        // API caller skip the binary rejection (only detectType runs it) and
        // hand the agent a hash that did not describe the script, which would
        // have defeated the integrity check the agent performs.
        $scriptType = ScriptPayload::detectType($validated['script_body']);
        $scriptName = $validated['script_name'] ?? ScriptPayload::defaultName($scriptType);
        $sha256 = ScriptPayload::hash($validated['script_body']);

        if (isset($validated['sha256']) && ! hash_equals($sha256, (string) $validated['sha256'])) {
            throw ValidationException::withMessages([
                'sha256' => 'The supplied SHA-256 does not match the script body.',
            ]);
        }

        if (isset($validated['script_type'])
            && self::normaliseType($validated['script_type']) !== $scriptType) {
            throw ValidationException::withMessages([
                'script_type' => 'The supplied script type does not match the script body.',
            ]);
        }

        $commandIds = [];
        foreach ($targetIds as $targetTokenId) {
            $command = AgentCommand::create([
                'agent_token_id' => $targetTokenId,
                'command' => 'script',
                'payload' => [
                    'script_name' => $scriptName,
                    'script_type' => $scriptType,
                    'script_body' => $validated['script_body'],
                    'working_directory' => $validated['working_directory'] ?? null,
                    'run_as' => $validated['run_as'] ?? 'current-user',
                    'timeout_seconds' => $validated['timeout_seconds'] ?? 300,
                    'sha256' => $sha256,
                    'trigger' => $validated['trigger'] ?? 'once',
                    'notes' => $validated['notes'] ?? null,
                    'source' => $validated['source'] ?? 'internal-api',
                ],
                'status' => 'pending',
            ]);

            $commandIds[] = $command->id;
        }

        $targetLabel = match ($validated['target']) {
            'all' => 'all devices',
            'device' => AgentApiToken::findOrFail((int) $validated['agent_token_id'])->name,
            'devices' => count($targetIds).' devices',
            default => 'selected devices',
        };

        return response()->json([
            'ok' => true,
            'target' => $validated['target'],
            'command_ids' => $commandIds,
            'message' => $validated['target'] === 'all'
                ? 'Script queued for all devices.'
                : "Script queued for {$targetLabel}.",
        ], 202);
    }

    /**
     * Collapse the accepted type spellings onto the two names detectType emits
     * before comparing, so an alias like `ps1` is checked as `powershell` rather
     * than rejected for merely being spelled differently.
     */
    private static function normaliseType(string $type): string
    {
        return match ($type) {
            'ps1', 'powershell' => 'powershell',
            'bat', 'batch', 'cmd' => 'cmd',
            default => $type,
        };
    }
}
