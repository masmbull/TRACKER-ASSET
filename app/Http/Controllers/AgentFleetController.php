<?php

namespace App\Http\Controllers;

use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use App\Models\AgentFingerprint;
use App\Models\AgentSoftware;
use App\Models\AgentTelemetry;
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
     * Every reading one device reports, grouped into labelled sections.
     *
     * Returned as section => (label => value) rather than one flat list. A flat
     * list of 36 fields reads as a wall of text with no way to tell that
     * "BitLocker: Disabled" belongs under security and not next to the disk size.
     *
     * A reading that is genuinely absent stays null and renders as a dash: on an
     * asset register the absence of a value is itself information, and a hole in
     * the grid would read as an oversight instead of a fact.
     *
     * @return array<string, array<string, string|null>>
     */
    private function detailRows(AgentApiToken $device): array
    {
        $t = $device->telemetry;
        $fp = $device->fingerprint;

        $yesNo = fn ($v) => $v === null ? null : ($v ? 'yes' : 'no');
        $onOff = fn ($v) => $v === null ? null : ($v ? 'on' : 'off');

        return [
            'Device' => [
                'User' => $t?->logged_in_user,
                'IP address' => $t?->ip_address,
                'Operating system' => $t?->os,
                'Uptime' => $t?->uptime_hours !== null ? round($t->uptime_hours / 24, 1).' days' : null,
                'Last report' => $t?->reported_at?->diffForHumans(),
            ],
            'Hardware' => [
                'CPU cores' => $fp?->cpu_cores,
                'RAM' => $t?->ram_config,
                'RAM slots' => $this->ramSlotsLabel($fp),
                'GPU' => $t?->gpu,
                'Monitor' => $fp?->monitor,
                'Disk type' => $fp?->disk_type,
                'SMART' => $fp?->smart_health,
            ],
            'Storage & battery' => [
                'Disk free' => $t?->disk_free_gb !== null ? number_format((float) $t->disk_free_gb, 1).' GB' : null,
                'Disk total' => $t?->disk_total_gb !== null ? number_format((float) $t->disk_total_gb, 1).' GB' : null,
                // The agent sends free and total, never the ratio. Computing it
                // here means a device that reported its disk size is never
                // shown as "—", which would read as an unreadable sensor when
                // both numbers are in fact right there.
                'Disk free %' => $this->diskFreePercent($t),
                'Battery' => $t?->battery_percent !== null ? $t->battery_percent.'%' : null,
                'Battery health' => $t?->battery_health !== null ? $t->battery_health.'%' : null,
                'Battery cycles' => $t?->battery_cycle_count,
            ],
            'Network' => [
                'Network' => $this->networkLabel($t),
                'Speed' => $t?->network_speed,
                'Wi-Fi' => $t?->wifi_ssid,
                'Signal' => $t?->wifi_signal !== null ? $t->wifi_signal.'%' : null,
            ],
            'Windows & licence' => [
                'Edition' => $fp?->windows_edition,
                'Activation' => $fp?->activation_status,
                'Licence' => $fp?->license_channel,
                'OS build' => $fp?->os_build,
                'Domain' => $fp?->domain,
            ],
            'Security' => [
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
            ],
            'Installed software' => [
                'Programs' => $fp?->software_count,
                'Startup items' => $fp?->startup_count,
            ],
        ];
    }

    /**
     * RAM slot occupancy as "2 of 4", degrading to whatever half is known.
     *
     * A laptop that reports two 8GB sticks and a board with four slots reads
     * "2 of 4" - full enough that capacity tells you nothing, and the free
     * slots are the fact worth having. When the firmware only reports one of
     * the two numbers, showing that one beats showing a dash.
     */
    private function ramSlotsLabel(?AgentFingerprint $fp): ?string
    {
        $used = $fp?->ram_modules;
        $total = $fp?->ram_slots;

        if ($used === null && $total === null) {
            return null;
        }

        if ($total !== null) {
            return $used === null ? "{$total} slots" : $used.' of '.$total;
        }

        return $used.' '.($used === 1 ? 'module' : 'modules').', slot count unknown';
    }

    /**
     * Free-space percentage, derived when the column is empty.
     *
     * The column itself is only filled by the heartbeat path, so a device whose
     * last reading came from a full scan has both numbers and no ratio. Showing
     * "—" there would suggest the sensor failed when the answer is arithmetic.
     */
    private function diskFreePercent(?AgentTelemetry $t): ?string
    {
        if ($t?->disk_free_percent !== null) {
            return round((float) $t->disk_free_percent).'%';
        }

        if ($t?->disk_free_gb === null || ! $t->disk_total_gb) {
            return null;
        }

        return round((float) $t->disk_free_gb / (float) $t->disk_total_gb * 100).'%';
    }

    /**
     * One readable network line: "<type> <adapter>", without repeating the type
     * when the adapter description already contains it.
     *
     * The agent reports them separately (network_type = "ethernet", adapter =
     * "Ethernet Realtek PCIe GbE Family Controller"), so a plain concatenation
     * produced "Ethernet Ethernet Realtek..." on every wired machine.
     */
    private function networkLabel(?AgentTelemetry $t): ?string
    {
        if (! $t) {
            return null;
        }

        $type = $t->network_type ? ucfirst($t->network_type) : null;
        $adapter = $t->network_adapter;

        if ($type && $adapter) {
            return str_starts_with(strtolower($adapter), strtolower($type))
                ? $adapter
                : $type.' '.$adapter;
        }

        return $type ?? $adapter;
    }

    /**
     * Page sizes offered by the software table's "per page" dropdown.
     */
    private const SOFTWARE_PAGE_SIZES = [25, 50, 100, 200];

    private const DEFAULT_SOFTWARE_PAGE_SIZE = 50;

    /**
     * Full security + software detail for one device (PRD §11 deep inventory).
     */
    public function fingerprint(Request $request, AgentApiToken $token)
    {
        $token->load('fingerprint.software', 'telemetry.asset');

        $fingerprint = $token->fingerprint;

        // Software is paginated and filtered on the server. It used to be one
        // scrollable block holding every row at once, which is a 66-row laptop
        // now and a 400-row one on a machine that has been imaged for years.
        // A device with hundreds of programs also made the page unusable on the
        // machines that most need it.
        $perPage = (int) $request->query('per_page', self::DEFAULT_SOFTWARE_PAGE_SIZE);

        if (! in_array($perPage, self::SOFTWARE_PAGE_SIZES, true)) {
            $perPage = self::DEFAULT_SOFTWARE_PAGE_SIZE;
        }

        $search = trim((string) $request->query('q', ''));

        $softwareQuery = $fingerprint
            ? $fingerprint->software()
            : AgentSoftware::query()->whereRaw('1 = 0');

        if ($search !== '') {
            // Escaped: a program name is attacker-influenced data, and "%" or "_"
            // typed into the box would otherwise become a wildcard and return
            // everything. The wildcards belong only around the user's term.
            $term = '%'.addcslashes($search, '%_\\').'%';
            $softwareQuery->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('publisher', 'like', $term)
                    ->orWhere('version', 'like', $term);
            });
        }

        $software = $softwareQuery
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        return view('agent.fingerprint', [
            'token' => $token,
            'software' => $software,
            'softwareTotal' => $fingerprint?->software()->count() ?? 0,
            'search' => $search,
            'pageSizes' => self::SOFTWARE_PAGE_SIZES,
            'perPage' => $perPage,
            // Formatted here, not in the view: ramSlotsLabel() is private to this
            // controller, and the fleet view already reuses the same helper so the
            // two pages cannot drift apart on how a slot count is worded.
            'ramSlots' => $this->ramSlotsLabel($fingerprint),
        ]);
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
