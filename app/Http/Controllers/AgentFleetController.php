<?php

namespace App\Http\Controllers;

use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use App\Models\AssetAudit;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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
     * Page sizes offered by the "per page" dropdown.
     *
     * Whitelisted rather than trusted from the query string, so a crafted
     * `?per_page=100000` cannot ask Laravel to build a 100k-link paginator.
     */
    private const PAGE_SIZES = [10, 25, 50, 100];

    private const DEFAULT_PAGE_SIZE = 10;

    /**
     * Live Fleet (PRD §11): the last telemetry snapshot of every deployed
     * device — battery wear, disk space, RAM layout, Wi-Fi and who is logged in.
     */
    public function index(Request $request)
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

        // Each device now renders every telemetry and fingerprint field it has,
        // so one device is a tall block rather than one narrow table row. That
        // is far more readable but also far longer per device, so the list is
        // paginated and the page size is user-selectable.
        $perPage = (int) $request->query('per_page', self::DEFAULT_PAGE_SIZE);

        if (! in_array($perPage, self::PAGE_SIZES, true)) {
            $perPage = self::DEFAULT_PAGE_SIZE;
        }

        // A plain Eloquent Collection has no paginate() — that method only
        // exists on the query builder — and the device set is already fully
        // loaded (it has to be, for the findings map and the modals), so the
        // page is sliced here rather than pushed back into the database.
        $page = max(1, (int) $request->query('page', 1));
        $pageItems = $activeDevices->values()->forPage($page, $perPage);
        $total = $activeDevices->count();

        $paginatedDevices = new LengthAwarePaginator(
            $pageItems,
            $total,
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        // Guard a page number past the end after a device is removed, otherwise
        // the user lands on an empty list with no way back except the links.
        if ($pageItems->isEmpty() && $page > 1) {
            return redirect()->route('agent.fleet', array_merge($request->query(), ['page' => 1]));
        }

        // The per-device field list is built here rather than in the template.
        // A `foreach` written inside a Blade @php block shares its braces with
        // the surrounding markup, which produced a template that Blade happily
        // compiled but that then failed to parse as PHP at render time — and the
        // only symptom was a 500 on the fleet page. Formatting a value is the
        // controller's job anyway; the view should only lay it out.
        $rowsByDevice = $paginatedDevices->getCollection()
            ->mapWithKeys(fn (AgentApiToken $device) => [$device->id => $this->detailRows($device)]);

        return view('agent.fleet', [
            'devices' => $devices,
            'activeDevices' => $activeDevices,
            // Paginated for rendering; the full collection is still passed as
            // $activeDevices because the uninstall modals and the "queued"
            // badge have to be able to look up a device on any page.
            'paginatedDevices' => $paginatedDevices,
            'rowsByDevice' => $rowsByDevice,
            'pageSizes' => self::PAGE_SIZES,
            'perPage' => $perPage,
            'revokedDevices' => $revokedDevices,
            'latestAudits' => $latestAudits,
            'stats' => $stats,
            'pendingUninstalls' => $pendingUninstalls,
            'findingsByToken' => $findingsByToken,
        ]);
    }

    /**
     * Every reading one device reports, as label => already-formatted value.
     *
     * A reading that is genuinely absent stays null and renders as a dash: on
     * an asset register the absence of a value is itself information, and a
     * hole in the grid would read as an oversight instead of a fact.
     *
     * @return array<string, string|null>
     */
    private function detailRows(AgentApiToken $device): array
    {
        $t = $device->telemetry;
        $fp = $device->fingerprint;

        $yesNo = fn ($v) => $v === null ? null : ($v ? 'yes' : 'no');
        $onOff = fn ($v) => $v === null ? null : ($v ? 'on' : 'off');

        $telemetry = [
            'User' => $t?->logged_in_user,
            'IP' => $t?->ip_address,
            'OS' => $t?->os,
            'Uptime' => $t?->uptime_hours !== null ? round($t->uptime_hours / 24, 1).' days' : null,
            'Battery' => $t?->battery_percent !== null ? $t->battery_percent.'%' : null,
            'Battery health' => $t?->battery_health !== null ? $t->battery_health.'%' : null,
            'Battery cycles' => $t?->battery_cycle_count,
            'Disk free' => $t?->disk_free_gb !== null ? number_format((float) $t->disk_free_gb, 1).' GB' : null,
            'Disk total' => $t?->disk_total_gb !== null ? number_format((float) $t->disk_total_gb, 1).' GB' : null,
            'RAM' => $t?->ram_config,
            'GPU' => $t?->gpu,
            'Wi-Fi' => $t?->wifi_ssid,
            'Signal' => $t?->wifi_signal !== null ? $t->wifi_signal.'%' : null,
            'Network' => $t?->network_type ? ucfirst($t->network_type).' '.$t->network_adapter : null,
            'Speed' => $t?->network_speed,
            'Reported' => $t?->reported_at?->diffForHumans(),
        ];

        $security = [
            'Antivirus' => $fp?->antivirus_product,
            'AV enabled' => $yesNo($fp?->antivirus_enabled),
            'AV signature' => $fp?->antivirus_signature_age_days !== null ? $fp->antivirus_signature_age_days.'d old' : null,
            'Firewall' => $onOff($fp?->firewall_enabled),
            'UAC' => $onOff($fp?->uac_enabled),
            'Secure boot' => $onOff($fp?->secure_boot_enabled),
            'TPM' => $fp?->tpm_present === null ? null : ($fp->tpm_present ? 'present' : 'absent'),
            'BitLocker' => $fp?->bitlocker_status,
            'RDP' => $onOff($fp?->rdp_enabled),
            'Pending reboot' => $yesNo($fp?->pending_reboot),
            'Windows' => $fp?->windows_edition,
            'Activation' => $fp?->activation_status,
            'Licence' => $fp?->license_channel,
            'OS build' => $fp?->os_build,
            'Domain' => $fp?->domain,
            'Disk type' => $fp?->disk_type,
            'SMART' => $fp?->smart_health,
            'CPU cores' => $fp?->cpu_cores,
            'Monitor' => $fp?->monitor,
            'Programs' => $fp?->software_count,
            'Startup' => $fp?->startup_count,
        ];

        return array_merge($telemetry, $security);
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
