<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use App\Models\AgentFingerprint;
use App\Models\AgentPrinter;
use App\Models\AgentSoftware;
use App\Models\AgentTelemetry;
use App\Models\AssetAudit;
use App\Services\ScriptPayload;
use App\Services\WakeOnLanService;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

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
     * polls. It is the fallback half of "is this device alive": an agent
     * predating the heartbeat still polls, it just never re-reports readings,
     * and calling those machines offline during a rollout is how a firmware
     * push gets read as a fleet-wide outage (PRD §11, Telemetry Heartbeat).
     */
    private const POLL_WINDOW_SECONDS = 90;

    /**
     * How long ago a device was last seen that still counts as pending.
     *
     * A queued command and a device that stopped answering are two different
     * things. Counting every pending scan painted "12 queued" on a fleet where a
     * single offline laptop had been left something days ago, which made the
     * number noise - the one thing on this page that is supposed to mean "work is
     * on its way" said nothing at all.
     */
    private const PENDING_WINDOW_MINUTES = 5;

    /**
     * Page sizes offered by the "per page" dropdown.
     *
     * Whitelisted rather than trusted from the query string, so a crafted
     * `?per_page=100000` cannot ask Laravel to build a 100k-link paginator.
     */
    private const PAGE_SIZES = [10, 25, 50, 100];

    private const DEFAULT_PAGE_SIZE = 10;

    /**
     * The power actions the fleet page can ask a device to perform.
     *
     * A closed list, not a free-text field: the value ends up in a command
     * line on the device, and the agent only understands these three.
     */
    private const POWER_ACTIONS = ['restart', 'shutdown', 'logoff'];

    /**
     * The magic-packet sender.
     *
     * Injected rather than built inline so the wake action can be exercised
     * without touching the network - the test asserts on the packet bytes, and
     * a real UDP broadcast from a test run is not something a test suite
     * should be doing to the network it runs on.
     */
    public function __construct(protected WakeOnLanService $wakeOnLan) {}

    /**
     * Return only the fields that change while the fleet page is open.
     */
    public function refresh(): JsonResponse
    {
        $devices = AgentApiToken::query()
            ->with('telemetry', 'fingerprint')
            ->where('is_active', true)
            ->get();

        $activeOperations = AgentCommand::query()
            ->whereIn('status', ['pending', 'in_progress'])
            ->whereIn('command', ['install', 'update'])
            ->whereIn('agent_token_id', $devices->pluck('id'))
            ->orderByDesc('id')
            ->get(['agent_token_id', 'command'])
            ->unique('agent_token_id')
            ->keyBy('agent_token_id');

        // The last command each device actually finished, to explain an offline
        // state. A machine that went quiet right after telling the server it had
        // downloaded an update did not simply wink out - its restart failed, which
        // is a fixable, known cause rather than an unexplained absence. Without
        // this the page said only "stale", and an operator had no way to tell a
        // broken self-update from a laptop that was switched off.
        $lastSettled = AgentCommand::query()
            ->whereIn('agent_token_id', $devices->pluck('id'))
            ->whereIn('status', ['done', 'failed'])
            ->orderByDesc('executed_at')
            ->get(['agent_token_id', 'command', 'status', 'executed_at', 'result'])
            ->unique('agent_token_id')
            ->keyBy('agent_token_id');

        $onlineCount = 0;
        $deviceStates = [];

        foreach ($devices as $device) {
            if (! $device->isUsable()) {
                continue;
            }

            $state = $this->deviceState(
                $device,
                $activeOperations->get($device->id),
                $lastSettled->get($device->id),
            );

            if ($state['status'] === 'online') {
                $onlineCount++;
            }

            $telemetry = $device->telemetry;

            $deviceStates[$device->id] = [
                ...$state,
                'reported_at' => $telemetry?->reported_at?->diffForHumans() ?? 'no report yet',
                'is_elevated' => $telemetry?->is_elevated,
                'battery_percent' => $telemetry?->battery_percent,
                'battery_health' => $telemetry?->battery_health,
                'battery_alert' => $telemetry !== null
                    && ($telemetry->hasLowBattery() || $telemetry->hasWeakBattery()),
                'disk_free_percent' => $telemetry?->disk_free_percent === null
                    ? null
                    : (int) round((float) $telemetry->disk_free_percent),
                'disk_free_gb' => $telemetry?->disk_free_gb === null
                    ? null
                    : number_format((float) $telemetry->disk_free_gb, 0),
                'rdp_enabled' => $fp?->rdp_enabled,
            ];
        }

        return response()->json([
            'online' => $onlineCount,
            'devices' => $deviceStates,
        ]);
    }

    /**
     * What one device's Status cell says, why it says it, and who reported.
     *
     * The single definition of the fleet's status vocabulary, shared by this
     * refresh and by the first paint in `index()`. Two copies of the rule is
     * how they drifted: the refresh had moved onto tray/SYSTEM modes while the
     * page still painted every heartbeat green, so a device changed status
     * fifteen seconds after loading with nobody touching it.
     *
     * - `online` — the agent is alive and is not a SYSTEM daemon reporting on
     *   its own. Alive means a fresh heartbeat *or* a recent inbox poll; the
     *   poll half is what keeps an agent predating the heartbeat from reading
     *   offline mid-rollout (PRD §11).
     * - `service` — a SYSTEM daemon reported within the window, so the machine
     *   is up but no per-user tray is running on it. Deliberately not counted
     *   as online: "online" is the number of laptops somebody can walk up to,
     *   and a box at the login screen is not one.
     * - `never scanned` / `installing` / `updating` / `stale` — the rest, in
     *   the order the page has always rendered them.
     *
     * @return array{status: string, reason: ?string, agent_mode: string}
     */
    private function deviceState(AgentApiToken $device, ?AgentCommand $operation, ?AgentCommand $lastSettled): array
    {
        $telemetry = $device->telemetry;
        $agentMode = $telemetry?->agentMode() ?? 'unknown';

        if (! $device->isUsable()) {
            return ['status' => 'revoked', 'reason' => null, 'agent_mode' => $agentMode];
        }

        $hasFreshReport = $telemetry?->isOnline() ?? false;
        $serviceActive = $hasFreshReport && $agentMode === 'service';

        $isAlive = $hasFreshReport
            || ($device->last_used_at?->gt(now()->subSeconds(self::POLL_WINDOW_SECONDS)) ?? false);

        // A machine being installed has no telemetry row yet, so "alive" would
        // otherwise paint it green and hide the one thing happening on it. The
        // install wins the status, and stays alive enough to gain no offline
        // reason underneath it.
        $installing = $telemetry === null
            && $operation !== null
            && in_array($operation->command, ['install', 'update'], true);

        $status = match (true) {
            $installing => $operation->command.'ing',
            $serviceActive => 'service',
            $isAlive => 'online',
            $telemetry === null => 'never scanned',
            default => 'stale',
        };

        return [
            'status' => $status,
            // When installing/updating, suppress the offline reason: the progress
            // bar already explains the state, and "No contact..." under an
            // "installing" badge reads as a contradiction (docblock promises this).
            'reason' => ($isAlive || $installing)
                ? null
                : $this->offlineReason($device, $operation, $lastSettled),
            'agent_mode' => $agentMode,
        ];
    }

    /**
     * Live Fleet (PRD §11): the last telemetry snapshot of every deployed
     * device — battery wear, disk space, RAM layout, Wi-Fi and who is logged in.
     */
    public function index(Request $request)
    {
        $devices = AgentApiToken::query()
            ->where('is_active', true)
            ->select([
                'id', 'name', 'is_active', 'expires_at', 'last_used_at',
                'device_id', 'agent_version',
                // The Wake button is gated on canBeWoken(), which reads this
                // column. A column left out of the select is simply null on the
                // hydrated model, so omitting it made the button vanish for
                // every device that had a perfectly good address recorded.
                'mac_address',
            ])
            ->with([
                'telemetry' => fn ($query) => $query->select(array_map(
                    fn (string $column): string => $query->getModel()->qualifyColumn($column),
                    [
                        'id', 'agent_token_id', 'asset_id', 'hostname', 'logged_in_user',
                        'is_elevated',
                        'os', 'ip_address', 'battery_percent', 'battery_health',
                        'battery_cycle_count', 'disk_free_gb', 'disk_total_gb',
                        'disk_free_percent', 'ram_config', 'gpu', 'wifi_ssid', 'wifi_signal',
                        'network_type', 'network_adapter', 'network_speed', 'uptime_hours',
                        // payload carries agent_mode, which deviceState() reads for
                        // every status cell on this page. Leaving it out of the
                        // select made that access lazy-load: one extra query per
                        // device, per page view.
                        'payload',
                        'reported_at',
                    ],
                )),
                'fingerprint' => fn ($query) => $query->select(array_map(
                    fn (string $column): string => $query->getModel()->qualifyColumn($column),
                    [
                        'id', 'agent_token_id', 'asset_id', 'device_model', 'cpu_cores',
                        'cpu_architecture', 'monitor', 'screen_resolution', 'chassis_type',
                        'bios_version', 'bios_date', 'disk_type', 'smart_health',
                        'smart_predicted_failure', 'windows_edition', 'activation_status',
                        'license_channel', 'domain', 'antivirus_product', 'antivirus_enabled',
                        'antivirus_signature_age_days', 'firewall_enabled', 'firewall_profiles_off',
                        'uac_enabled', 'secure_boot_enabled', 'tpm_present', 'bitlocker_status',
                        'bitlocker_method', 'rdp_enabled', 'auto_logon_enabled',
                        'screen_lock_enabled', 'lock_timeout_minutes', 'guest_account_enabled',
                        'defender_realtime_enabled', 'defender_last_scan_days', 'pending_reboot',
                        'last_hotfix_installed_at', 'hotfix_count', 'windows_updates_pending',
                        'security_updates_pending', 'updates_failed', 'updates_succeeded',
                        'last_security_update_at', 'security_updates_installed',
                        'local_admin_count', 'software_count', 'startup_count', 'ram_modules',
                        'ram_slots', 'collected_at',
                        // Accessories
                        'monitor_sn', 'keyboard_model', 'mouse_model',
                        'headset_model', 'webcam_model', 'other_accessories',
                    ],
                )),
            ])
            ->orderBy('name')
            ->get();

        $deviceIds = $devices->pluck('id')->all();

        // Latest audit per DEVICE, not per asset.
        //
        // This used to key by asset_id, which is wrong the moment two agents
        // report the same machine - a reinstall mints a new token, and the old and
        // new rows both point at one asset. Every device's detail modal then showed
        // whichever of them scanned last, so opening device A could show device
        // B's readings: different RAM, different serial, and an "update the
        // register" button carrying the wrong machine's values. Keying by the
        // reporting token means a modal only ever shows what *this* device sent.
        $latestAudits = AssetAudit::query()
            ->whereIn('agent_token_id', $deviceIds)
            ->orderByDesc('audit_date')
            ->get()
            ->unique('agent_token_id')
            ->keyBy('agent_token_id');

        // Scans still waiting for a decision, per device.
        //
        // Counted rather than derived from "telemetry has no asset", because those
        // are different questions: a device can have telemetry but no scan (an
        // agent that reports readings without auditing), and it can have three
        // scans while waiting. The number next to "Add to register" has to be the
        // real count of scans to be acted on, or the column is decoration.
        $unmatchedScans = AssetAudit::query()
            ->whereNull('asset_id')
            ->whereNotNull('agent_token_id')
            ->selectRaw('agent_token_id, count(*) as aggregate')
            ->groupBy('agent_token_id')
            ->pluck('aggregate', 'agent_token_id');

        $telemetries = $devices->map(fn (AgentApiToken $token) => $token->telemetry)->filter();

        $stats = [
            'devices' => $devices->count(),
            // Filled in below, once the per-device states exist: the KPI and the
            // status column have to come from one rule or the header says "3
            // online" over a table that shows two.
            'online' => 0,
            'battery_alerts' => $telemetries->filter(fn ($t) => $t->hasLowBattery() || $t->hasWeakBattery())->count(),
            'disk_alerts' => $telemetries->filter(fn ($t) => $t->hasLowDisk())->count(),
            'avg_battery_health' => $telemetries->whereNotNull('battery_health')->avg('battery_health'),
            'pending_commands' => AgentCommand::query()
                // Deliverable only, and recent only. A pending row aimed at a
                // revoked token can never be picked up, and one left on a laptop
                // that has been off for a week is not "queued" in any sense the
                // operator cares about - it just kept the badge lit forever.
                ->where('status', 'pending')
                ->whereNotNull('agent_token_id')
                ->where('created_at', '>=', now()->subMinutes(self::PENDING_WINDOW_MINUTES))
                ->whereHas('token', fn ($q) => $q
                    ->where('is_active', true)
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())))
                ->count(),
            // Devices seen by an agent that the register does not contain yet.
            // Counted as devices, not scans: one laptop waiting is one piece of
            // work, however many times it has checked in since. Read off $devices
            // rather than $activeDevices, which is only split out further down.
            'unregistered' => $devices->filter(
                fn (AgentApiToken $t) => $t->isUsable()
                    && $t->telemetry !== null
                    && $t->telemetry->asset_id === null
            )->count(),
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

        // Every open operation per device, keyed by token id: an install being
        // reported, an update in flight, an uninstall that has started. The
        // table used to show one static "queued" label, which looks the same
        // whether the agent picked the work up a second ago, is 80% through it,
        // or died holding it - and "is it actually working?" is the only
        // question worth asking while a fleet is being changed underneath
        // people.
        //
        // Latest wins per device: a machine re-running its installer adopts the
        // same row rather than stacking a second one, and ordering by id makes
        // that explicit instead of relying on insertion order.
        $activeOperations = AgentCommand::query()
            ->whereIn('status', ['pending', 'in_progress'])
            ->whereNotNull('agent_token_id')
            ->orderByDesc('id')
            ->get()
            ->filter($isDeliverable)
            ->groupBy('agent_token_id')
            ->map(fn ($rows) => $rows->first());

        // Why each device is offline, and what its status cell says, computed
        // once here so the first paint already explains a dark machine instead
        // of waiting for the next 15 s poll - and so the two can never disagree
        // about the same row.
        $lastSettled = AgentCommand::query()
            ->whereIn('agent_token_id', $deviceIds)
            ->whereIn('status', ['done', 'failed'])
            ->orderByDesc('executed_at')
            ->get(['agent_token_id', 'command', 'status', 'executed_at', 'result'])
            ->unique('agent_token_id')
            ->keyBy('agent_token_id');

        $deviceStates = $devices->mapWithKeys(fn (AgentApiToken $device) => [
            $device->id => $this->deviceState(
                $device,
                $activeOperations->get($device->id),
                $lastSettled->get($device->id),
            ),
        ]);

        // Revoked devices are split off first: their agent can never authenticate
        // again, so they must never inflate the "online" KPI on the fleet page.
        $activeDevices = $devices->filter(fn (AgentApiToken $t) => $t->isUsable())->values();
        $revokedDevices = $devices->reject(fn (AgentApiToken $t) => $t->isUsable())->values();

        // Online count only from active (usable) devices. A revoked device may
        // still have fresh telemetry in the table, but it is not "online" for
        // fleet management purposes.
        $activeDeviceIds = $activeDevices->pluck('id')->all();
        $stats['online'] = $deviceStates
            ->filter(fn (array $state) => $state['status'] === 'online')
            ->filter(fn (array $state, int $key) => in_array($key, $activeDeviceIds))
            ->count();

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

        // "N not on the register" links here with ?unregistered=1, so the count
        // on the summary line is a filter rather than a dead end. Read before
        // pagination so the filtered set is what gets sliced, not the whole
        // fleet with the count describing a subset of it.
        if ($request->boolean('unregistered')) {
            $activeDevices = $activeDevices->filter(
                fn (AgentApiToken $t) => $t->telemetry !== null && $t->telemetry->asset_id === null
            )->values();
        }

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

        // Printers, loaded only for the devices on the current page.
        //
        // The printer list is per-device and per-page, not per-fleet, so it is
        // fetched after the paginator is built rather than joined in the
        // eager-load above. Keyed by fingerprint id so the view can look it up
        // without knowing the fingerprint's primary key.
        $printersByFingerprint = AgentPrinter::query()
            ->whereIn('agent_fingerprint_id', $paginatedDevices->getCollection()
                ->pluck('fingerprint')
                ->filter()
                ->pluck('id')
                ->all())
            ->get()
            ->groupBy('agent_fingerprint_id');

        return view('agent.fleet', [
            'devices' => $devices,
            'activeDevices' => $activeDevices,
            // Paginated for rendering; the full collection is still passed as
            // $activeDevices because the uninstall modals and the "queued"
            // badge have to be able to look up a device on any page.
            'paginatedDevices' => $paginatedDevices,
            'rowsByDevice' => $rowsByDevice,
            'printersByFingerprint' => $printersByFingerprint,
            'pageSizes' => self::PAGE_SIZES,
            'perPage' => $perPage,
            'revokedDevices' => $revokedDevices,
            'latestAudits' => $latestAudits,
            'unmatchedScans' => $unmatchedScans,
            'stats' => $stats,
            'pendingUninstalls' => $pendingUninstalls,
            'activeOperations' => $activeOperations,
            'findingsByToken' => $findingsByToken,
            'deviceStates' => $deviceStates,
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
                'Architecture' => $fp?->cpu_architecture,
                'RAM' => $t?->ram_config,
                'RAM slots' => $this->ramSlotsLabel($fp),
                'GPU' => $t?->gpu,
                'Monitor' => $fp?->monitor,
                'Resolution' => $fp?->screen_resolution,
                'Device type' => $fp?->chassis_type,
                'Model' => $fp?->device_model,
                'BIOS' => $fp?->bios_version,
                'BIOS date' => $fp?->bios_date?->format('Y-m-d'),
                'Disk type' => $fp?->disk_type,
                'SMART' => $fp?->smart_health,
            ],
            'Accessories' => [
                'Monitor S/N' => $fp?->monitor_sn,
                'Keyboard' => $fp?->keyboard_model,
                'Mouse' => $fp?->mouse_model,
                'Headset' => $fp?->headset_model,
                'Webcam' => $fp?->webcam_model,
                'Other' => $fp?->other_accessories,
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
                // Which profiles are off matters more than whether the firewall
                // is on: "on" with the Public profile disabled is the machine an
                // operator is actually worried about, and that was only visible
                // on the old duplicate detail page.
                'Firewall profiles off' => $fp?->firewall_profiles_off ?: null,
                'UAC' => $onOff($fp?->uac_enabled),
                'Secure boot' => $onOff($fp?->secure_boot_enabled),
                'TPM' => $fp?->tpm_present === null ? null : ($fp->tpm_present ? 'present' : 'absent'),
                'BitLocker' => $fp?->bitlocker_status,
                'BitLocker method' => $fp?->bitlocker_method,
                'RDP' => $onOff($fp?->rdp_enabled),
                // Autologon is the finding that matters most on this panel: the
                // machine signs itself in after every reboot, so a stolen laptop
                // opens onto a desktop with no barrier - and BitLocker on the disk
                // does nothing about it, because the volume is already unlocked.
                'Auto sign-in' => $fp?->auto_logon_enabled === null ? null : ($fp->auto_logon_enabled ? 'ON - no password needed' : 'off'),
                'Screen lock' => $this->screenLockLabel($fp),
                'Guest account' => $fp?->guest_account_enabled === null ? null : ($fp->guest_account_enabled ? 'ENABLED' : 'disabled'),
                'Defender real-time' => $onOff($fp?->defender_realtime_enabled),
                // Fresh definitions plus an old scan means updates are arriving
                // on a machine that is not actually being checked, which is the
                // combination neither of the two readings exposes alone.
                'Last Defender scan' => $fp?->defender_last_scan_days !== null ? $this->scanAgeLabel($fp->defender_last_scan_days) : null,
                'Pending reboot' => $yesNo($fp?->pending_reboot),
                'Last hotfix' => $fp?->last_hotfix_installed_at?->format('Y-m-d'),
                'Hotfixes' => $fp?->hotfix_count,
                // CVE tracking. The pending counts are null whenever the search
                // timed out, and that is rendered as "not checked" rather than as
                // 0 - "0 pending" is a claim the fleet is fully patched, and it
                // must never be inferred from a search we gave up on.
                'Updates waiting' => $this->pendingUpdatesLabel($fp),
                'Security waiting' => $fp?->security_updates_pending === null ? null : (string) $fp->security_updates_pending,
                // A failed update is not protection. It is invisible to the
                // hotfix count, which lists it as though it applied, so it is
                // surfaced on its own.
                'Updates failed' => $fp?->updates_failed === null ? null : ((int) $fp->updates_failed > 0 ? (string) $fp->updates_failed : 'none'),
                'Updates applied' => $fp?->updates_succeeded,
                'Last security update' => $fp?->last_security_update_at?->format('Y-m-d'),
                'Security updates' => $fp?->security_updates_installed,
                'Local admins' => $fp?->local_admin_count,
                'Domain' => $fp?->domain,
            ],
            'Installed software' => [
                'Programs' => $fp?->software_count,
                'Startup items' => $fp?->startup_count,
            ],
        ];
    }

    /**
     * Updates waiting, or an explicit statement that nobody looked.
     *
     * The search behind this number takes about half a minute and the agent
     * gives it 25 seconds, so on a laptop behind a slow or captive network the
     * field is simply absent. Rendering that as "0" would assert the fleet is
     * fully patched on the exact machines least likely to be - the single most
     * damaging thing this page could do with a null.
     */
    private function pendingUpdatesLabel(?AgentFingerprint $fp): ?string
    {
        $pending = $fp?->windows_updates_pending;

        if ($pending === null) {
            return $fp === null ? null : 'not checked (search timed out)';
        }

        return $pending === 0 ? 'up to date' : (string) $pending;
    }

    /**
     * Screen-lock posture as one phrase rather than two loose numbers.
     *
     * A zero timeout means "lock immediately", which is the best possible
     * setting and must not be rendered as a bare "0" - that reads as "no lock
     * configured" and inverts the meaning. A missing timeout alongside an
     * enabled lock is likewise reported as immediate rather than as unknown,
     * because that is what Windows does with it.
     */
    private function screenLockLabel(?AgentFingerprint $fp): ?string
    {
        if ($fp?->screen_lock_enabled === null) {
            return null;
        }

        if (! $fp->screen_lock_enabled) {
            return 'OFF - never locks';
        }

        $minutes = $fp->lock_timeout_minutes;

        if ($minutes === null || $minutes === 0) {
            return 'immediate';
        }

        return $minutes < 60
            ? $minutes.' min'
            : round($minutes / 60, 1).' h';
    }

    /**
     * Defender scan age, in words an operator can act on.
     *
     * The raw day count is unreadable at a glance - "287" says nothing about
     * whether that is a problem. A machine that has never been scanned is a
     * different finding from one that is merely overdue, so "never" is its own
     * label rather than a large number.
     */
    private function scanAgeLabel(int $days): string
    {
        return match (true) {
            $days <= 0 => 'today',
            $days === 1 => 'yesterday',
            $days <= 7 => $days.' days ago',
            $days <= 60 => round($days / 7).' weeks ago',
            default => round($days / 30.44, 1).' months ago',
        };
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
        $token->load('fingerprint.software', 'fingerprint.printers', 'telemetry.asset');

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

        $tokens = AgentApiToken::with('telemetry')->orderBy('name')->get();

        return view('agent.commands', compact('commands', 'tokens'));
    }

    public function pushScript(Request $request)
    {
        $this->authorize('manage-agents');

        $validated = $request->validate([
            'target' => ['required', 'in:all,device,devices'],
            'agent_token_id' => ['nullable', 'required_if:target,device', 'exists:agent_api_tokens,id'],
            'agent_token_ids' => ['nullable', 'required_if:target,devices', 'array'],
            'agent_token_ids.*' => ['integer', 'exists:agent_api_tokens,id'],
            'script_body' => ['required', 'string'],
            'working_directory' => ['nullable', 'string', 'max:255'],
            'run_as' => ['nullable', 'in:current-user,admin,system'],
            'timeout_seconds' => ['nullable', 'integer', 'min:10', 'max:3600'],
            'trigger' => ['nullable', 'in:once,reboot'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $targetIds = match ($validated['target']) {
            'all' => [null],
            'device' => [(int) $validated['agent_token_id']],
            'devices' => array_map('intval', $validated['agent_token_ids'] ?? []),
            default => [null],
        };

        // The type and the integrity hash are derived from the script itself
        // rather than asked of the operator: a hand-picked type next to a pasted
        // body is a second source of truth that can disagree with the first, and
        // a hand-typed SHA-256 is worse than useless because it is usually
        // wrong. The body is the single input; everything else follows from it.
        $scriptType = ScriptPayload::detectType($validated['script_body']);
        $scriptName = ScriptPayload::defaultName($scriptType);

        $payload = [
            'script_name' => $scriptName,
            'script_type' => $scriptType,
            'script_body' => $validated['script_body'],
            'working_directory' => $validated['working_directory'] ?? null,
            'run_as' => $validated['run_as'] ?? 'current-user',
            'timeout_seconds' => $validated['timeout_seconds'] ?? 300,
            'sha256' => ScriptPayload::hash($validated['script_body']),
            'trigger' => $validated['trigger'] ?? 'once',
            'notes' => $validated['notes'] ?? null,
            'created_by' => $request->user()?->name ?? 'system',
        ];

        $commandIds = [];
        foreach ($targetIds as $targetTokenId) {
            $command = AgentCommand::create([
                'agent_token_id' => $targetTokenId,
                'command' => 'script',
                'payload' => $payload,
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

        $message = "Script queued for {$targetLabel}. The next poll will fetch and execute it.";

        // Same JSON contract as every other fleet action: the browser's action
        // handler watches the queued command(s) when the response carries their
        // ids. `command_ids` (plural) routes a multi-device push through the
        // batch watcher, which is the only shape that can show one bar for
        // several machines; a single target still comes back as a one-element
        // array and the handler treats it as a single command.
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
                'command_ids' => $commandIds,
            ]);
        }

        return back()->with('success', $message);
    }

    /**
     * Queue a scan for one laptop (identified by its token).
     */
    public function scanNow(Request $request, AgentApiToken $token): JsonResponse|RedirectResponse
    {
        // A scan aimed at a token the middleware will reject is a command that
        // can never be delivered. The uninstall action refuses it outright; a
        // scan must refuse it too, otherwise the button silently produces work
        // the fleet will never see and the operator is told it was queued.
        if (! $token->isUsable()) {
            return $this->actionResponse($request, sprintf(
                "'%s' is revoked or its token has expired, so the agent can no longer be reached and a scan cannot be delivered. ".
                'Issue a new token and redeploy the agent on that machine first.',
                $token->name
            ), false);
        }

        $command = $this->enqueue($token->id, 'scan');

        return $this->actionResponse($request, "Scan queued for '{$token->name}' — the agent picks it up within a few seconds.", commandId: $command->id);
    }

    /**
     * Scan every reachable device.
     *
     * One targeted command per device, not a broadcast row.
     *
     * A broadcast (agent_token_id null) is a single shared row that no agent can
     * ever settle honestly: the fleet page kept showing "queued" while every
     * machine had already scanned, and per-device progress and outcome had
     * nowhere to land. One row per device makes the page match reality, and it is
     * what lets the agent acknowledge the scan it was asked for.
     */
    public function scanAll(Request $request): JsonResponse|RedirectResponse
    {
        $pending = AgentCommand::query()
            ->where('command', 'scan')
            ->where('status', 'pending')
            ->whereNotNull('agent_token_id')
            ->select('agent_token_id');

        $tokens = AgentApiToken::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            // Already waiting for this device to answer: queueing again would
            // hand it two identical scans and show the page a second pending row
            // for work that is already on its way.
            ->whereNotIn('id', $pending)
            ->get();

        $commandIds = [];

        foreach ($tokens as $token) {
            $commandIds[] = $this->enqueue($token->id, 'scan', ['source' => 'scan-all'])->id;
        }

        return $this->actionResponse($request, sprintf(
            'Scan queued for %d device(s) — each agent picks it up within a few seconds.',
            $tokens->count()
        ), commandIds: $commandIds);
    }

    /**
     * Broadcast self-update to every agent (the daemon re-pulls the newest
     * binary and restarts itself).
     *
     * Devices already on the release are skipped, per device rather than by
     * cancelling the broadcast. A broadcast row with no target reaches every
     * agent that asks, including the ones just restarted onto the new build, so
     * "skip the current ones" has to be a decision made when the command is
     * handed out. Getting this wrong is expensive rather than merely noisy: each
     * agent pulls a ~160 MB binary, and a company-wide update that re-downloaded
     * to machines already carrying that exact build would restart the whole
     * office's antivirus mid-scan for nothing.
     */
    public function updateAll(Request $request): JsonResponse|RedirectResponse
    {
        $tokens = AgentApiToken::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get();

        // One command per device, not one broadcast row.
        //
        // A broadcast is a single row every agent shares: each agent reports its
        // download progress onto that one row and acks it, so the first agent to
        // finish settled it "done" while the rest were still pulling 160 MB - the
        // progress dialog closed on a fleet that was still updating, or sat on a
        // progress figure belonging to some other machine. Per-device rows are
        // what the batch watcher can track honestly, one bar over N rows.
        $commandIds = [];

        foreach ($tokens as $token) {
            if ($token->isOutdated() === false) {
                continue;
            }

            $command = $this->enqueue($token->id, 'update', ['url' => URL::route('agent.download.latest')]);
            $commandIds[] = $command->id;
        }

        $current = $tokens->count() - count($commandIds);

        $note = $current > 0
            ? " {$current} already on the release will be left alone."
            : '';

        return $this->actionResponse($request, "Update queued for every agent that is behind{$note} — each re-pulls the binary and restarts in the background.", commandIds: $commandIds);
    }

    /**
     * Update one laptop, from the browser.
     *
     * Update All was the only way to move a single machine onto a new build,
     * which meant pushing a change to every device in the office to fix the one
     * in front of you. This is the same enqueue with a target, and it travels the
     * same inbox as every other command - the agent has no second path for
     * being told what to do.
     *
     * No version check here on purpose: the fleet page already marks which
     * devices are behind, and refusing a manual update because the operator
     * disagrees with the badge would be worse than the badge being wrong.
     */
    public function updateOne(Request $request, AgentApiToken $token): JsonResponse|RedirectResponse
    {
        // If there's already a pending/in-progress update for this device, return it
        // instead of creating a duplicate. The agent processes commands sequentially,
        // and a targeted update will run after any in-progress bulk update anyway.
        $existing = AgentCommand::where('agent_token_id', $token->id)
            ->where('command', 'update')
            ->whereIn('status', ['pending', 'in_progress'])
            ->latest('id')
            ->first();

        if ($existing) {
            return $this->actionResponse($request,
                "Update already queued/in-progress for {$token->name}. The agent will install the latest build after the current operation completes.",
                commandId: $existing->id);
        }

        $command = $this->enqueue($token->id, 'update', ['url' => URL::route('agent.download.latest')]);

        return $this->actionResponse($request, "Update queued for {$token->name} — it re-pulls the latest binary and restarts.", commandId: $command->id);
    }

    /**
     * Ask one agent to become an administrator, from the browser.
     *
     * A machine-level script (USB disable, HKLM write) only runs from an
     * elevated/SYSTEM agent. Pushing such a script makes the agent offer this
     * once, but an operator who can already see the device is "no admin" should
     * not have to push a script just to trigger the prompt. This queues an
     * `elevate` command: the tray agent raises a UAC prompt, registers the
     * boot-time SYSTEM task, and is admin from then on.
     *
     * It needs someone at the machine to approve the prompt - Windows will not
     * elevate silently - so the message says so instead of promising it is done.
     */
    public function elevate(Request $request, AgentApiToken $token): JsonResponse|RedirectResponse
    {
        if (! $token->isUsable()) {
            return $this->actionResponse($request, sprintf(
                "'%s' is revoked, so the agent can no longer be reached and the elevation cannot be delivered.",
                $token->name
            ), false);
        }

        $command = $this->enqueue($token->id, 'elevate');

        return $this->actionResponse($request, sprintf(
            "Elevation requested for '%s'. Approve the Windows admin prompt on that machine; "
            .'the agent becomes a system service and can then run machine-level scripts.',
            $token->name
        ), commandId: $command->id);
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
    public function uninstall(Request $request, AgentApiToken $token): JsonResponse|RedirectResponse
    {
        // A revoked/expired token can never receive the command: the agent
        // middleware answers 401 before the inbox is even reached, so the row
        // would sit `pending` forever and pin the fleet page to a permanent
        // "Uninstall queued" badge for a machine that will never be contacted.
        // Refuse it at the source instead of queueing and cleaning up later.
        if (! $token->isUsable()) {
            return $this->actionResponse($request, sprintf(
                "'%s' is already revoked, so the agent can no longer be reached and an uninstall cannot be delivered. ".
                'Remove the program from that machine manually.',
                $token->name
            ), false);
        }

        $command = $this->enqueue($token->id, 'uninstall', ['requested_by' => $request->user()?->name]);

        return $this->actionResponse($request, sprintf(
            "Uninstall queued for '%s'. The agent removes itself at its next poll (within 10 seconds) and the token is revoked then. ".
            'A machine that is currently offline will do this the next time it comes back online.',
            $token->name
        ), commandId: $command->id);
    }

    /**
     * Wake a sleeping machine over the network (Wake on LAN).
     *
     * This is the one fleet action that does NOT go through the agent, and it
     * cannot: a machine that is asleep has no agent running, so the agent is
     * precisely the thing that is not there to do the waking. The server sends
     * the magic packet itself, straight onto the LAN.
     *
     * The device's MAC is the target. It has to be known before a wake can be
     * attempted, and the agent is the only party that can report it - so a
     * device that has never checked in cannot be woken, and says so plainly
     * rather than pretending to have tried.
     */
    public function wake(Request $request, AgentApiToken $token): JsonResponse|RedirectResponse
    {
        if (! $token->isUsable()) {
            return $this->actionResponse($request, sprintf(
                "'%s' is revoked or its token has expired. A magic packet does not need a token, "
                .'but a revoked device is one that should be left alone.',
                $token->name
            ), false);
        }

        $mac = $token->wakeMacAddress();

        if ($mac === null) {
            return $this->actionResponse($request, sprintf(
                "'%s' has never reported its MAC address, so there is nothing to aim a wake packet at. "
                .'Wake on LAN needs the network address of the adapter, and only the agent can report it. '
                .'Let the machine check in once, then try again.',
                $token->name
            ), false);
        }

        // A subnet broadcast reaches the machine without needing the global
        // broadcast to be allowed through the network, which most enterprise
        // switches filter. It can only be derived when the device's own IP is
        // known; otherwise the global broadcast is the only option.
        $ip = $token->telemetry?->ip_address;
        $broadcast = WakeOnLanService::subnetBroadcast($ip) ?? '255.255.255.255';

        $sent = $this->wakeOnLan->send($mac, $broadcast);

        ActivityLog::create([
            'actor_id' => $request->user()?->id,
            'action' => 'WAKE',
            'entity_type' => 'agent_tokens',
            'entity_id' => $token->id,
            'entity_identifier' => $token->name,
            'source' => 'Web UI',
            'old_value' => null,
            'new_value' => $mac,
            'metadata' => ['broadcast' => $broadcast, 'sent' => $sent],
        ]);

        if (! $sent) {
            return $this->actionResponse($request, sprintf(
                "The wake packet for '%s' could not be sent from this server. "
                .'Check that the app container is allowed to broadcast on the network.',
                $token->name
            ), false);
        }

        return $this->actionResponse($request, sprintf(
            "Wake packet sent to '%s' (%s). The machine powers on if Wake on LAN is enabled in its BIOS and NIC settings.",
            $token->name,
            $mac
        ));
    }

    /**
     * Restart, shut down or log the current user out of a device.
     *
     * The agent has to do this: the server has no way to reach into a running
     * Windows session, and a power action is not something a browser can do on
     * its own. So the command is queued and the resident daemon carries it out.
     *
     * A delay is offered because "restart this laptop" is almost always a
     * request to restart it *soon*, and a machine that is mid-save needs a
     * moment. The agent acks the command before the machine goes down, so the
     * fleet page records the action as accepted rather than as a device that
     * stopped answering.
     */
    public function power(Request $request, AgentApiToken $token): JsonResponse|RedirectResponse
    {
        if (! $token->isUsable()) {
            return $this->actionResponse($request, sprintf(
                "'%s' is revoked or its token has expired, so the agent can no longer be reached and a power action cannot be delivered.",
                $token->name
            ), false);
        }

        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(self::POWER_ACTIONS)],
            'delay_seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
        ]);

        $action = $validated['action'];
        $delay = (int) ($validated['delay_seconds'] ?? 0);

        $command = $this->enqueue($token->id, 'power', [
            'action' => $action,
            'delay_seconds' => $delay,
            'requested_by' => $request->user()?->name,
        ]);

        ActivityLog::create([
            'actor_id' => $request->user()?->id,
            'action' => 'POWER',
            'entity_type' => 'agent_tokens',
            'entity_id' => $token->id,
            'entity_identifier' => $token->name,
            'source' => 'Web UI',
            'old_value' => null,
            'new_value' => $action,
            'metadata' => ['delay_seconds' => $delay],
        ]);

        $verb = match ($action) {
            'restart' => 'restart',
            'shutdown' => 'shut down',
            'logoff' => 'log out',
        };

        return $this->actionResponse($request, sprintf(
            "%s queued for '%s'%s. The agent picks it up within a few seconds.",
            ucfirst($verb),
            $token->name,
            $delay > 0 ? sprintf(' in %d second(s)', $delay) : ' immediately'
        ), commandId: $command->id);
    }

    /**
     * A ready-to-use Remote Desktop connection file for a device.
     *
     * The browser cannot open an RDP session on its own, but it can hand the
     * operator a file that Windows Remote Desktop opens with one click, with
     * the address already filled in. That is the difference between an
     * operator copying an IP out of a table by hand and actually reaching the
     * machine.
     */
    public function remoteDesktop(AgentApiToken $token): Response
    {
        $ip = $token->telemetry?->ip_address;

        if (blank($ip)) {
            return response('This device has never reported an IP address, so there is nothing to connect to.', 404);
        }

        // The .rdp format is a plain INI file. Only the fields an operator
        // would want to see in the connection bar are written; anything else is
        // left to the client's own defaults.
        $rdp = implode("\r\n", [
            'screen mode id:i:2',
            'desktopwidth:i:1280',
            'desktopheight:i:800',
            'session bpp:i:32',
            'prompt for credentials:i:0',
            'administrative session:i:0',
            'gatewayhostname:',
            'full address:s:'.$ip,
            'audiomode:i:0',
            'username:s:',
        ]);

        $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', $token->name ?: $ip).'.rdp';

        return response($rdp, 200, [
            'Content-Type' => 'application/x-rdp',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Manual sweep (same logic as the scheduled `audit:sweep`): queue a scan
     * for every active, non-busy agent, skipping recently-swept ones.
     */
    public function sweep(Request $request): JsonResponse|RedirectResponse
    {
        // Same usability rule as the scheduled `audit:sweep` and the device list
        // above: an expired token answers 401 just like a revoked one, so
        // queueing for it creates a command that can never be delivered.
        $tokens = AgentApiToken::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get();

        $queued = 0;
        $commandIds = [];

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

            $commandIds[] = $this->enqueue($token->id, 'scan', ['source' => 'manual-sweep'])->id;
            $queued++;
        }

        return $this->actionResponse($request, sprintf('Sweep queued %d agent(s).', $queued), commandIds: $commandIds);
    }

    /**
     * Why a device that is not answering is not answering.
     *
     * "stale" alone tells an operator nothing actionable. The two causes they can
     * act on are worth naming: a machine that went quiet immediately after an
     * update settled is the failed-self-restart case (the update said it finished,
     * the process never came back), and a machine with an open update that never
     * progressed never got to run it. Anything else is just silence, reported as
     * how long it has been quiet.
     */
    private function offlineReason(AgentApiToken $device, ?AgentCommand $operation, ?AgentCommand $lastSettled): ?string
    {
        // An update that was queued but never picked up.
        if ($operation !== null && $operation->command === 'update' && $operation->isStalled()) {
            return 'An update was queued but the agent has not collected it — the agent may not be running.';
        }

        // The device went quiet right after settling an update: the self-restart.
        if ($lastSettled !== null
            && $lastSettled->command === 'update'
            && $device->last_used_at !== null
            && $lastSettled->executed_at !== null
            && abs($device->last_used_at->diffInSeconds($lastSettled->executed_at)) <= 120) {
            return 'Went offline right after an update: the agent downloaded the new build but did not restart. Reinstall the agent on this machine.';
        }

        // Use absolute English duration: "5 minutes" not "5 minutes ago".
        $quiet = $device->last_used_at
            ?->diffForHumans([
                'syntax' => CarbonInterface::DIFF_ABSOLUTE,
                'locale' => 'en',
                'parts' => 1,
            ]);

        return $quiet === null
            ? 'The agent has never reported in.'
            : 'No contact from the agent for '.$quiet.' — the machine may be off, or the agent stopped.';
    }

    private function actionResponse(Request $request, string $message, bool $success = true, ?int $commandId = null, array $commandIds = []): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => $success,
                'message' => $message,
                // Present when the action queued work: the progress modal needs a
                // command to poll. Null for actions that do not queue one.
                'command_id' => $commandId,
                // A multi-device action carries every queued id so the batch
                // watcher can show one bar over N rows, instead of one row whose
                // status any single agent can settle on its own.
                'command_ids' => $commandIds,
            ], $success ? 200 : 422);
        }

        return back()->with($success ? 'success' : 'error', $message);
    }

    private function enqueue(?int $tokenId, string $command, array $payload = []): AgentCommand
    {
        return AgentCommand::create([
            'agent_token_id' => $tokenId,
            'command' => $command,
            'payload' => $payload ?: null,
            'status' => 'pending',
        ]);
    }

    /**
     * Progress of one queued command, for the modal that watches an action run.
     *
     * The fleet table already shows a progress bar inline, but that is built from
     * the page's own poll and only covers install/update. An operator who clicks
     * Scan or Uninstall is looking at a dialog and wants *that* action's state, so
     * this answers per command: status, percentage, the agent's stage sentence,
     * and whether it has settled. Kept deliberately small - it is polled while a
     * modal is open, so it returns four scalars, not a model.
     */
    public function commandStatus(AgentCommand $command): JsonResponse
    {
        $this->authorize('manage-agents');

        return response()->json([
            'id' => $command->id,
            'command' => $command->command,
            // Which machine this row is for. The batch dialog lists one line per
            // command, and "MSI-2: stalled" is the whole reason an operator opens
            // it - a bare count of "0 of 3" says nothing about who is stuck.
            'device' => $command->token?->name,
            'status' => $command->status,
            'progress' => $command->progress,
            // The label already says "stalled at 45%" when the agent has gone
            // quiet, so a dialog left open over a dead agent stops lying without
            // any extra work here.
            'label' => $command->progressLabel(),
            'stalled' => $command->isStalled(),
            'done' => $command->status === 'done',
            'failed' => $command->status === 'failed',
            'message' => $command->result['message'] ?? null,
            // The phases of this command, so the dialog can show the whole
            // journey as a checklist instead of a lone bar with no context.
            'steps' => $command->steps(),
        ]);
    }
}
