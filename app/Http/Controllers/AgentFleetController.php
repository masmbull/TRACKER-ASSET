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
     * How recently an agent must have hit the inbox to count as alive.
     *
     * The daemon polls every 10s by default, so 90s tolerates nine missed
     * polls. Widened from the 2-minute telemetry window because an agent
     * predating the heartbeat still polls, it just never re-reports readings.
     */
    private const POLL_WINDOW_SECONDS = 90;

    /**
     * Live Fleet (PRD §11): the last telemetry snapshot of every deployed
     * device — battery wear, disk space, RAM layout, Wi-Fi and who is logged in.
     */
    public function index()
    {
        $devices = AgentApiToken::query()
            ->with(['telemetry.asset', 'fingerprint'])
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

        // An agent deployed before the heartbeat existed keeps polling the
        // inbox (which refreshes last_used_at) but never refreshes telemetry, so
        // counting on the 2-minute reading window alone would paint every
        // un-updated machine offline. Treat a recent inbox poll as proof of
        // life so a rollout does not look like a mass outage.
        $onlineDevices = $devices->filter(function (AgentApiToken $token) {
            $polling = $token->last_used_at?->gt(now()->subSeconds(self::POLL_WINDOW_SECONDS)) ?? false;

            return $polling || ($token->telemetry?->isOnline() ?? false);
        })->count();

        $stats = [
            'devices' => $devices->count(),
            'online' => $onlineDevices,
            'battery_alerts' => $telemetries->filter(fn ($t) => $t->hasLowBattery() || $t->hasWeakBattery())->count(),
            'disk_alerts' => $telemetries->filter(fn ($t) => $t->hasLowDisk())->count(),
            'avg_battery_health' => $telemetries->whereNotNull('battery_health')->avg('battery_health'),
            'pending_commands' => AgentCommand::where('status', 'pending')->count(),
        ];

        // Token ids with an uninstall still queued: the button turns into a
        // "queued" label so a second click cannot pile up duplicate removals.
        //
        // Split by deliverability. A pending uninstall aimed at a revoked token
        // can never be picked up (the agent middleware answers 401 before the
        // inbox is read), and telling the operator it is "queued" forever is
        // worse than saying plainly that it will not be delivered.
        $queuedUninstalls = AgentCommand::query()
            ->where('command', 'uninstall')
            ->where('status', 'pending')
            ->whereNotNull('agent_token_id')
            ->get(['agent_token_id']);

        $isDeliverable = fn (AgentCommand $c): bool => $devices
            ->firstWhere('id', $c->agent_token_id)?->isUsable() ?? false;

        $pendingUninstalls = $queuedUninstalls
            ->filter($isDeliverable)
            ->pluck('agent_token_id')
            ->flip();

        // Revoked devices are split off: their agent can never authenticate
        // again, so every action column entry for them is dead weight and they
        // only dilute the count of machines an operator actually manages. This
        // also settles the stuck "Uninstall queued" badge at the source — a
        // revoked device is not in the active table at all.
        $activeDevices = $devices->filter(fn (AgentApiToken $t) => $t->isUsable())->values();
        $revokedDevices = $devices->reject(fn (AgentApiToken $t) => $t->isUsable())->values();

        // Security findings, aggregated once here rather than per row in the
        // view: a device can carry several, and the KPI card needs the total
        // across the whole fleet. $findingsByToken keeps the count derivable
        // without re-running findings() for every device twice.
        $findingsByToken = $activeDevices
            ->mapWithKeys(fn (AgentApiToken $t) => [$t->id => $t->fingerprint?->findings() ?? []]);

        $stats['security_alerts'] = $findingsByToken->sum(fn (array $findings) => count($findings));
        $stats['critical_alerts'] = $findingsByToken
            ->flatten(1)
            ->filter(fn (array $finding) => $finding['severity'] === 'danger')
            ->count();
        $stats['fingerprinted'] = $activeDevices->filter(fn (AgentApiToken $t) => $t->fingerprint !== null)->count();

        return view('agent.fleet', compact(
            'devices',
            'activeDevices',
            'revokedDevices',
            'latestAudits',
            'stats',
            'pendingUninstalls',
            'findingsByToken',
        ));
    }

    /**
     * Full security + software detail for one device (PRD §11 deep inventory).
     */
    public function fingerprint(AgentApiToken $token)
    {
        $token->load(['fingerprint.software', 'telemetry.asset']);

        return view('agent.fingerprint', ['token' => $token]);
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
        // A scan aimed at a token the middleware will reject is a command that
        // can never be delivered. The uninstall action refuses it outright; a
        // scan must refuse it too, otherwise the button silently produces work
        // the fleet will never see and the operator is told it was queued.
        if (! $token->isUsable()) {
            return back()->with('error', sprintf(
                "'%s' is revoked or its token has expired, so the agent can no longer be reached and a scan cannot be delivered. ".
                'Issue a new token and redeploy the agent on that machine first.',
                $token->name
            ));
        }

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
     * Broadcast self-update to every agent (the daemon re-pulls the newest
     * binary and restarts itself).
     */
    public function updateAll()
    {
        // The URL points at the binary download, which is the only artifact a
        // deployed agent consumes. It used to hand out `agent.run` (the retired
        // PowerShell script), which no shipped agent ever fetches — so the
        // payload was advertising a file that does not exist in the fleet.
        $this->enqueue(null, 'update', ['url' => URL::route('agent.download.latest')]);

        return back()->with('success', 'Update broadcast to all agents — each re-pulls the latest binary and restarts.');
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
        // A revoked/expired token can never receive the command: the agent
        // middleware answers 401 before the inbox is even reached, so the row
        // would sit `pending` forever and pin the fleet page to a permanent
        // "Uninstall queued" badge for a machine that will never be contacted.
        // Refuse it at the source instead of queueing and cleaning up later.
        if (! $token->isUsable()) {
            return back()->with('error', sprintf(
                "'%s' is already revoked, so the agent can no longer be reached and an uninstall cannot be delivered. ".
                'Remove the program from that machine manually.',
                $token->name
            ));
        }

        $this->enqueue($token->id, 'uninstall', ['requested_by' => $request->user()?->name]);

        return back()->with('success', sprintf(
            "Uninstall queued for '%s'. The agent removes itself at its next poll (within 10 seconds) and the token is revoked then. ".
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
        // Same usability rule as the scheduled `audit:sweep` and the device list
        // above: an expired token answers 401 just like a revoked one, so
        // queueing for it creates a command that can never be delivered.
        $tokens = AgentApiToken::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get();

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
