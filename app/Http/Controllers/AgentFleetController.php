<?php

namespace App\Http\Controllers;

use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use App\Models\AssetAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Web command center for the agent fleet (PRD §11): 1-click Scan now /
 * Update all agents right from the browser.
 */
class AgentFleetController extends Controller
{
    /**
     * Live Fleet (PRD §11): the last telemetry snapshot of every deployed
     * device — battery wear, disk space, RAM layout, Wi-Fi and who is logged in.
     */
    public function index()
    {
        $devices = AgentApiToken::query()
            ->with(['telemetry.asset'])
            ->orderBy('name')
            ->get();

        $assetIds = $devices->map(fn (AgentApiToken $token) => $token->telemetry?->asset_id)
            ->filter()
            ->all();

        // Latest audit per asset (ordered desc, then unique keeps the newest).
        $latestAudits = AssetAudit::query()
            ->whereIn('asset_id', $assetIds)
            ->orderByDesc('audit_date')
            ->get()
            ->unique('asset_id')
            ->keyBy('asset_id');

        $telemetries = $devices->map(fn (AgentApiToken $token) => $token->telemetry)->filter();

        $stats = [
            'devices' => $devices->count(),
            'online' => $telemetries->filter(fn ($t) => $t->isOnline())->count(),
            'battery_alerts' => $telemetries->filter(fn ($t) => $t->hasLowBattery() || $t->hasWeakBattery())->count(),
            'disk_alerts' => $telemetries->filter(fn ($t) => $t->hasLowDisk())->count(),
            'avg_battery_health' => $telemetries->whereNotNull('battery_health')->avg('battery_health'),
            'pending_commands' => AgentCommand::where('status', 'pending')->count(),
        ];

        // Token ids with an uninstall still queued: the button turns into a
        // "queued" label so a second click cannot pile up duplicate removals.
        $pendingUninstalls = AgentCommand::query()
            ->where('command', 'uninstall')
            ->where('status', 'pending')
            ->pluck('agent_token_id')
            ->flip();

        return view('agent.fleet', compact('devices', 'latestAudits', 'stats', 'pendingUninstalls'));
    }

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
     * Remove the agent from one machine, from the browser.
     *
     * The server has no access to the user's disk, so this queues an
     * `uninstall` command: the resident daemon picks it up on its next poll,
     * acks it, then deletes autostart, device identity and its own folder.
     *
     * The token is deliberately NOT revoked here. AuthenticateAuditAgent rejects
     * inactive tokens, so revoking now would make the very next poll return 401
     * and an online machine would never receive the command it was just sent.
     * Revocation happens in AgentCommandApiController::ack, i.e. only after the
     * agent has confirmed it tore itself down. A machine that is offline keeps
     * its token and therefore still executes the removal the moment it comes
     * back, instead of going permanently deaf.
     */
    public function uninstall(Request $request, AgentApiToken $token)
    {
        $this->enqueue($token->id, 'uninstall', ['requested_by' => $request->user()?->name]);

        return back()->with('success', sprintf(
            "Uninstall queued for '%s'. The agent removes itself at its next poll (within 10 seconds) and the token is revoked then. " .
            'A machine that is currently offline will do this the next time it comes back online.',
            $token->name
        ));
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
