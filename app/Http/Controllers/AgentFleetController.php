<?php

namespace App\Http\Controllers;

use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Web command center for the agent fleet (PRD §11): 1-click Scan now /
 * Update all agents right from the browser.
 */
class AgentFleetController extends Controller
{
    public function commands(Request $request)
    {
        $commands = AgentCommand::query()
            ->with('token')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $tokens = AgentApiToken::orderBy('name')->get();

        return view('agent.commands', compact('commands', 'tokens'));
    }

    /**
     * Queue a scan for one laptop (identified by its token).
     */
    public function scanNow(AgentApiToken $token)
    {
        $this->enqueue($token->id, 'scan');

        return back()->with('success', "Scan queued for '{$token->name}' — the agent picks it up within a few seconds.");
    }

    /**
     * Broadcast scan to every active agent at their next poll.
     */
    public function scanAll()
    {
        $this->enqueue(null, 'scan');

        return back()->with('success', 'Scan broadcast to all agents — they will pick it up within a few seconds.');
    }

    /**
     * Broadcast self-update to every agent (the script endpoint always serves
     * the latest version, so the daemon just re-pulls and restarts itself).
     */
    public function updateAll()
    {
        $this->enqueue(null, 'update', ['url' => URL::route('agent.run')]);

        return back()->with('success', 'Update broadcast to all agents — each re-pulls the latest script and restarts.');
    }

    /**
     * Manual sweep (same logic as the scheduled `audit:sweep`): queue a scan
     * for every active, non-busy agent, skipping recently-swept ones.
     */
    public function sweep()
    {
        $tokens = AgentApiToken::where('is_active', true)->get();

        $queued = 0;

        foreach ($tokens as $token) {
            $busy = AgentCommand::query()
                ->where('agent_token_id', $token->id)
                ->where('command', 'scan')
                ->where(fn ($q) => $q
                    ->where('status', 'pending')
                    ->orWhere('created_at', '>=', now()->subMinutes(30)))
                ->exists();

            if ($busy) {
                continue;
            }

            $this->enqueue($token->id, 'scan', ['source' => 'manual-sweep']);
            $queued++;
        }

        return back()->with('success', sprintf('Sweep queued %d agent(s).', $queued));
    }

    private function enqueue(?int $tokenId, string $command, array $payload = []): void
    {
        AgentCommand::create([
            'agent_token_id' => $tokenId,
            'command' => $command,
            'payload' => $payload ?: null,
            'status' => 'pending',
        ]);
    }
}
