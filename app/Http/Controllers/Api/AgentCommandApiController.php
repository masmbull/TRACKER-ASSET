<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
    public function inbox(Request $request)
    {
        $tokenId = (int) $request->attributes->get('agent_token_id');

        $commands = AgentCommand::query()
            ->where('status', 'pending')
            ->where(function ($query) use ($tokenId) {
                $query->whereNull('agent_token_id')
                    ->orWhere('agent_token_id', $tokenId);
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

        return response()->json(['ok' => true]);
    }
}
