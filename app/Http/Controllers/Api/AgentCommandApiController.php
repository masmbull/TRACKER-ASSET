<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Command inbox for resident agents (PRD §11).
 *
 * The agent polls GET /api/agent/inbox with its Bearer token, executes the
 * pending command, then acks it. The dashboard enqueues commands via the web
 * controllers (scan now / update all).
 */
class AgentCommandApiController extends Controller
{
    /**
     * Exact wording the agent sends in its uninstall ack. Only this string
     * revokes the token, so an agent too old to know the command (it acks
     * 'done' with no result at all) leaves the token alive for a real retry.
     */
    public const UNINSTALL_DONE_MARKER = 'Agent removed itself after receiving the uninstall command.';

    public function inbox(Request $request)
    {
        $tokenId = (int) $request->attributes->get('agent_token_id');

        $commands = AgentCommand::query()
            ->where('status', 'pending')
            ->where(function ($query) use ($tokenId) {
                $query->whereNull('agent_token_id')
                    ->orWhere('agent_token_id', $tokenId);
            })
            // A targeted "remove this machine" command must never go out as a
            // broadcast. `agent_token_id` is nullOnDelete, so deleting a token
            // row silently rewrites its pending uninstall into a fleet-wide one
            // and every agent removes itself. Uninstall is only ever created
            // per device, so a null target is always corruption, not an intent.
            ->where(function ($query) {
                $query->where('command', '!=', 'uninstall')
                    ->orWhereNotNull('agent_token_id');
            })
            ->limit(10)
            ->get(['id', 'command', 'payload']);

        return response()->json(['commands' => $commands]);
    }

    public function ack(Request $request, AgentCommand $command)
    {
        $tokenId = (int) $request->attributes->get('agent_token_id');

        abort_unless($command->agent_token_id === null || $command->agent_token_id === $tokenId, 403);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['done', 'failed'])],
            'result' => 'nullable|array',
        ]);

        $command->update([
            'status' => $validated['status'],
            'result' => $validated['result'] ?? null,
            'executed_at' => now(),
        ]);

        // Uninstall: burn the token, but only once the agent has actually
        // confirmed it removed itself. Revoking earlier (when the command is
        // queued) would break the very poll that has to deliver the command,
        // because AuthenticateAuditAgent rejects inactive tokens. The marker
        // keeps old agents - which ack 'done' without a result - from being
        // treated as a successful removal.
        if ($command->command === 'uninstall' && $tokenId !== 0) {
            $confirmed = ($validated['result']['message'] ?? null) === self::UNINSTALL_DONE_MARKER;

            if ($confirmed) {
                AgentApiToken::whereKey($tokenId)->update(['is_active' => false]);
            }
        }

        return response()->json(['ok' => true]);
    }
}
