@extends('layouts.app')

@section('title', 'Live Fleet')

@section('content')
<x-page-header title="Live Fleet" subtitle="Latest telemetry of every deployed device — battery wear, C: disk space, RAM, Wi-Fi and the logged-in Windows user.">
    <x-slot:actions>
        <div class="flex flex-wrap items-center gap-2">
            <form method="POST" action="{{ route('agent.scanAll') }}" data-modal-action>
                @csrf
                <x-button type="submit" variant="secondary" size="sm">Scan All Devices</x-button>
            </form>
            <x-button type="button" variant="primary" size="sm" @click="$dispatch('open-modal', 'confirm-update-all-agents')">Update All Agents</x-button>
        </div>
    </x-slot:actions>
</x-page-header>

<x-card class="mb-6">
    <div class="flex flex-wrap gap-2 text-theme-sm">
        <a href="{{ route('agent.index') }}" class="rounded-lg bg-gray-100 px-3 py-1.5 font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">Tokens</a>
        <a href="{{ route('agent.commands') }}" class="rounded-lg bg-gray-100 px-3 py-1.5 font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">Commands</a>
        <a href="{{ route('agent.fleet') }}" class="rounded-lg bg-brand-600 px-3 py-1.5 font-medium text-white">Live Fleet</a>
    </div>

    @if(session('success'))
        <div class="mt-4 rounded-xl border border-success-200 bg-success-50 px-4 py-3 text-theme-sm text-success-700 dark:border-success-500/20 dark:bg-success-500/10 dark:text-success-300">
            {{ session('success') }}
        </div>
    @endif
</x-card>

{{-- One summary line instead of five KPI cards. Each card held a big number
     and a sentence explaining it, which is a lot of ink for facts that are
     only glanceable; the numbers an operator acts on are still here, just
     without the card scaffolding. --}}
<div class="mb-4 flex flex-col items-start gap-2 text-theme-sm text-gray-600 sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-4 sm:gap-y-2 dark:text-gray-300">
    <span><span class="font-semibold text-gray-800 dark:text-white" data-fleet-online>{{ $stats['online'] }}</span>/{{ $stats['devices'] }} online</span>
    @if($stats['battery_alerts'] > 0)
        <x-badge variant="warning">{{ $stats['battery_alerts'] }} battery</x-badge>
    @endif
    @if($stats['disk_alerts'] > 0)
        <x-badge variant="error">{{ $stats['disk_alerts'] }} low disk</x-badge>
    @endif
    @if($stats['critical_alerts'] > 0)
        <x-badge variant="error">{{ $stats['critical_alerts'] }} critical</x-badge>
    @elseif($stats['security_alerts'] > 0)
        <x-badge variant="warning">{{ $stats['security_alerts'] }} findings</x-badge>
    @endif
    @if($stats['unregistered'] > 0)
        {{-- The one number that is work waiting on a person rather than on a
             machine. Placed here rather than in a KPI card because it is the
             only line on this page that implies an action. --}}
        <a href="{{ route('agent.fleet', ['unregistered' => 1]) }}"
           class="inline-flex items-center gap-1 rounded-lg bg-warning-50 px-2 py-1 font-medium text-warning-700 hover:bg-warning-100 dark:bg-warning-500/10 dark:text-warning-300">
            {{ $stats['unregistered'] }} not on the register
        </a>
    @endif
    @if($stats['pending_commands'] > 0)
        <x-badge variant="brand">{{ $stats['pending_commands'] }} queued</x-badge>
    @endif
    @if($stats['battery_alerts'] === 0 && $stats['disk_alerts'] === 0 && $stats['security_alerts'] === 0)
        <x-badge variant="success">all clear</x-badge>
    @endif
</div>
<x-card title="Active Devices" subtitle="Click any device to see everything it reports. Updates live — no need to refresh." data-fleet-refresh-url="{{ route('agent.fleet.refresh') }}">
    @if($activeDevices->isEmpty())
        <p class="py-8 text-center text-theme-sm text-gray-400">
            No active devices. Open <a href="{{ route('agent.index') }}" class="font-medium text-brand-500 hover:text-brand-600">Tokens</a> and download the agent to enrol a laptop.
        </p>
    @else

    {{-- A compact table, because 36 readings per device inline turned the page
         into a wall of text. The row carries the three things an operator scans
         a fleet for - is it up, is the battery dying, is the disk full - and
         everything else waits one click away in the detail modal. --}}
    <div class="overflow-x-auto -mx-1 px-1 sm:mx-0 sm:px-0">
        <table class="min-w-[760px] w-full">
            <thead>
                <tr class="border-b border-gray-100 dark:border-gray-800">
                    <th class="py-2.5 px-3 text-left text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Device</th>
                    <th class="py-2.5 px-3 text-left text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Status</th>
                    <th class="py-2.5 px-3 text-left text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Battery</th>
                    <th class="py-2.5 px-3 text-left text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Disk</th>
                    <th class="py-2.5 px-3 text-left text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Remote</th>
                    <th class="py-2.5 px-3 text-left text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Security</th>
                    <th class="py-2.5 px-3 text-left text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Register</th>
                    <th class="py-2.5 px-3 text-right text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($paginatedDevices as $device)
                    @php
                        $telemetry = $device->telemetry;
                        $fp = $device->fingerprint;
                        $audit = $telemetry?->asset_id ? ($latestAudits[$device->id] ?? null) : null;
                        // Computed by the controller and shared with the 15-second
                        // refresh: this page used to derive its own copy of the same
                        // rule, so a device could load as "stale" and flip to "online"
                        // a quarter of a minute later without anyone touching it.
                        $state = $deviceStates[$device->id]
                            ?? ['status' => 'revoked', 'reason' => null, 'agent_mode' => 'unknown'];
                        $status = $state['status'];
                        $deviceFindings = $findingsByToken[$device->id] ?? [];
                        $deviceOperation = $activeOperations->get($device->id);

                        // Remote readiness, from what actually decides it.
                        //
                        // Computed once per device here because two places use it:
                        // the Remote column and the Remote action. It used to show
                        // Windows RDP, which has nothing to do with our remote
                        // access - RustDesk dials its own rendezvous server on 21116
                        // and never touches RDP. A device reading "RDP Off" could be
                        // perfectly reachable, and one reading "RDP On" could have no
                        // RustDesk id at all.
                        //
                        // Three facts decide it: the agent has an id (RustDesk is
                        // installed and registered), it is elevated (the client can
                        // accept a session), and it has reported recently.
                        $remoteId = $device->rustdesk_id;
                        $remoteElevated = ($telemetry?->is_elevated ?? $fp?->is_elevated) === true;
                        $remoteOnline = $telemetry?->reported_at !== null
                            && $telemetry->reported_at->gt(now()->subMinutes(3));

                        $remoteReady = filled($remoteId) && $remoteElevated && $remoteOnline;
                        $remoteReason = ! filled($remoteId)
                            ? 'RustDesk belum melaporkan ID'
                            : (! $remoteElevated
                                ? 'Agent belum elevated'
                                : (! $remoteOnline ? 'Perangkat offline' : 'Siap'));
                    @endphp
                    <tr class="border-b border-gray-100 bg-white/60 hover:bg-gray-50 dark:border-gray-800 dark:bg-[#1d2939]/50 dark:hover:bg-white/5" data-fleet-device="{{ $device->id }}">
                        <td class="py-3 px-3">
                            <button type="button"
                                    class="text-left"
                                    @click="$dispatch('open-modal', 'device-{{ $device->id }}')">
                                <span class="block break-words text-theme-sm font-medium text-gray-800 hover:text-brand-600 dark:text-white">
                                    {{ $device->name }}
                                </span>
                                <span class="mt-0.5 block break-all text-theme-xs text-gray-500 dark:text-gray-400">
                                    {{ $telemetry?->hostname ?? $device->device_id ?? '—' }}
                                    @if($telemetry?->logged_in_user)
                                        · {{ $telemetry->logged_in_user }}
                                    @endif
                                </span>
                                {{-- Admin capability. A machine-level script
                                     (USB disable, HKLM write) only runs from an
                                     elevated/SYSTEM agent, so the operator needs
                                     to see which laptops can and which cannot
                                     before pushing one - null means the agent
                                     never reported it (pre-1.4.3 build). --}}
                                @if($telemetry?->is_elevated === true)
                                    <span class="mt-1 inline-flex items-center gap-1 rounded-full bg-success-50 px-2 py-0.5 text-theme-xs font-medium text-success-700 dark:bg-success-500/10 dark:text-success-300" title="Agent runs with administrator rights — can run system-level scripts">
                                        <x-icon name="access" class="h-2.5 w-2.5" />
                                        admin
                                    </span>
                                @elseif($telemetry?->is_elevated === false)
                                    <span class="mt-1 inline-flex items-center gap-1 rounded-full bg-warning-50 px-2 py-0.5 text-theme-xs font-medium text-warning-700 dark:bg-warning-500/10 dark:text-warning-300" title="Agent runs as a standard user — system-level scripts will fail until it is elevated">
                                        <x-icon name="settings" class="h-2.5 w-2.5" />
                                        no admin
                                    </span>
                                @endif
                            </button>
                        </td>
                        <td class="py-3 px-3">
                            {{-- Flat for the same reason as the actions column: a
                                 conditional nested in another one, holding a Blade
                                 component, does not compile to balanced PHP. --}}
                            @php
                                $isSettingUp = $deviceOperation !== null
                                    && in_array($deviceOperation->command, ['install', 'update'], true);
                            @endphp

                            @if($status === 'revoked')
                                <x-badge variant="error" :dot="true" data-fleet-status-badge>revoked</x-badge>
                            @elseif($status === 'online')
                                <x-badge variant="success" :dot="true" data-fleet-status-badge>online</x-badge>
                            @elseif($status === 'service')
                                {{-- A SYSTEM daemon answered, so the box is up but no
                                     per-user tray is running on it. Said plainly rather
                                     than as "online": the operator's next question is
                                     "is anybody signed in to that laptop?", and a green
                                     dot hides it. --}}
                                <x-badge variant="brand" :dot="true" data-fleet-status-badge>service active · tray not detected</x-badge>
                            @elseif(in_array($status, ['installing', 'updating'], true))
                                {{-- A machine mid-install has no telemetry yet, and
                                     "never scanned" reads as a fault with the machine
                                     rather than an install still running on it. --}}
                                <x-badge variant="gray" :dot="true" data-fleet-status-badge>{{ $status }}</x-badge>
                            @elseif($status === 'never scanned')
                                <x-badge variant="gray" :dot="true" data-fleet-status-badge>never scanned</x-badge>
                            @else
                                <x-badge variant="warning" :dot="true" data-fleet-status-badge>stale</x-badge>
                            @endif
                            <span class="mt-1 block text-theme-xs text-gray-500 dark:text-gray-400" data-fleet-reported-at>
                                {{ $telemetry?->reported_at ? $telemetry->reported_at->diffForHumans(['locale' => 'en']) : 'no report yet' }}
                            </span>

                            {{-- Why an offline device is offline, when the server can
                                 say. The controller only produces a reason for a
                                 device nobody has heard from, so a healthy machine -
                                 and a SYSTEM daemon that is reporting fine - gains no
                                 extra line. --}}
                            <span @class([
                                'mt-1 block max-w-[16rem] text-theme-xs text-warning-600 dark:text-warning-300',
                                'hidden' => ($state['reason'] ?? null) === null,
                            ]) data-fleet-reason>{{ $state['reason'] ?? '' }}</span>

                            {{-- Install and update progress. Without this a rollout is
                                 a blank wait: there is no way to tell a machine working
                                 through its first inventory from one that hung
                                 halfway, and the first inventory is the slow step. --}}
                            @if($isSettingUp)
                                <div class="mt-2 w-full max-w-[16rem]">
                                    <div class="mb-1 text-theme-xs break-words {{ $deviceOperation->isStalled() ? 'font-medium text-error-500' : 'text-gray-500 dark:text-gray-400' }}">
                                        {{ $deviceOperation->progressLabel() }}
                                    </div>
                                    <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                        <div class="h-full rounded-full transition-all duration-500 {{ $deviceOperation->isStalled() ? 'bg-error-500' : 'bg-brand-500' }}" style="width: {{ max($deviceOperation->progress ?? 2, 2) }}%"></div>
                                    </div>
                                </div>
                            @endif
                        </td>
                        <td class="py-3 px-3 text-theme-sm text-gray-700 dark:text-gray-300" data-fleet-battery>
                            @if($telemetry?->battery_percent !== null)
                                <span @class([
                                    'font-medium',
                                    'text-error-500' => $telemetry->hasLowBattery() || $telemetry->hasWeakBattery(),
                                    'text-gray-700 dark:text-gray-300' => ! $telemetry->hasLowBattery() && ! $telemetry->hasWeakBattery(),
                                ])>{{ $telemetry->battery_percent }}%</span>
                                <span class="block text-theme-xs text-gray-500 dark:text-gray-400">health {{ $telemetry->battery_health ?? '—' }}%</span>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="py-3 px-3 text-theme-sm text-gray-700 dark:text-gray-300" data-fleet-disk>
                            @if($telemetry?->disk_free_percent !== null)
                                <span @class([
                                    'font-medium',
                                    'text-error-500' => $telemetry->hasLowDisk(),
                                    'text-gray-700 dark:text-gray-300' => ! $telemetry->hasLowDisk(),
                                ])>{{ round((float) $telemetry->disk_free_percent) }}%</span>
                                <span class="block text-theme-xs text-gray-500 dark:text-gray-400">
                                    {{ number_format((float) $telemetry->disk_free_gb, 0) }} GB free
                                </span>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="py-3 px-3 text-theme-sm text-gray-700 dark:text-gray-300" data-fleet-remote title="{{ $remoteReason }}">
                            @if($remoteReady)
                                <span class="inline-flex items-center gap-1 font-medium text-success-600 dark:text-success-400"><x-icon name="remote" class="h-3.5 w-3.5" /> Remote Ready</span>
                            @elseif(filled($remoteId))
                                <span class="font-medium text-warning-600 dark:text-warning-400">Remote Idle</span>
                                <span class="block text-theme-xs text-gray-400">{{ $remoteReason }}</span>
                            @else
                                <span class="text-gray-400" title="RustDesk belum terpasang atau belum mendaftar">Remote —</span>
                            @endif
                        </td>
                        <td class="py-3 px-3 text-theme-sm text-gray-700 dark:text-gray-300" data-fleet-admin-elevated>
                            @php
                                $elevated = $telemetry?->is_elevated ?? $fp?->is_elevated;
                            @endphp
                            {{-- Elevation is reported on every heartbeat, so the
                                 telemetry row is the live answer. This column used
                                 to read the fingerprint's is_elevated, a column the
                                 fingerprints table never had: the value was always
                                 null, so a device that had just been elevated still
                                 showed "Standard User" and kept offering "Make
                                 admin" after the command had already succeeded. --}}
                            @if($elevated === true)
                                <span class="inline-flex items-center gap-1 font-medium text-success-600 dark:text-success-400"><x-icon name="access" class="h-3.5 w-3.5" /> Admin Elevated</span>
                            @elseif($elevated === false)
                                <span class="font-medium text-gray-500 dark:text-gray-400">👤 Standard User</span>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="py-3 px-3">
                            @if($deviceFindings)
                                <x-badge :variant="collect($deviceFindings)->contains('severity', 'danger') ? 'error' : 'warning'">
                                    {{ count($deviceFindings) }} {{ count($deviceFindings) === 1 ? 'issue' : 'issues' }}
                                </x-badge>
                            @elseif($fp)
                                <x-badge variant="success">clean</x-badge>
                            @else
                                <span class="text-theme-sm text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="py-3 px-3">
                            {{-- Whether this machine is a row on the register, and if
                                 not, the way to fix that. Until auto-creation was
                                 removed a scan made an asset by itself, so every
                                 device here had one and this column would be
                                 decoration; now it is the answer to "which
                                 laptops am I actually tracking?". --}}
                            {{-- Issue #1: use asset_id column directly instead of
                                 loading the asset relationship, to avoid an extra
                                 query per device on every page paint. --}}
                            @if($telemetry?->asset_id !== null)
                                <a href="{{ route('assets.show', $telemetry->asset) }}"
                                   class="text-theme-sm font-medium text-brand-500 hover:text-brand-600">
                                    {{ $telemetry->asset->asset_id }}
                                </a>
                            @elseif($device->isUsable())
                                {{-- Issue #3: explicitly gate on usability AND no
                                     asset so a device that cannot be promoted
                                     (revoked, expired) gets "—" rather than
                                     "Add to register". --}}
                                <a href="{{ route('agent.promote', $device) }}"
                                   class="whitespace-nowrap text-theme-sm font-medium text-warning-600 hover:text-warning-700">
                                    Add to register
                                </a>
                                <span class="mt-0.5 block text-theme-xs text-gray-500 dark:text-gray-400">
                                    {{ $unmatchedScans[$device->id] ?? 0 }} scan{{ ($unmatchedScans[$device->id] ?? 0) === 1 ? '' : 's' }} waiting
                                </span>
                            @else
                                <span class="text-theme-sm text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="py-3 px-3">
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                <x-row-action variant="brand" icon="eye" @click="$dispatch('open-modal', 'device-{{ $device->id }}')">Detail</x-row-action>

                                @if($device->isUsable())
                                    @if($device->canBeWoken())
                                        <form method="POST" action="{{ route('agent.wake', $device) }}" data-modal-action class="inline-flex">
                                            @csrf
                                            <x-row-action type="submit" icon="wake" title="Send Wake on LAN magic packet">Wake</x-row-action>
                                        </form>
                                    @endif
                                    {{-- One Remote button. RDP and the .rdp
                                         download were two doors to the same room,
                                         and neither worked on a machine with RDP
                                         off or behind a NAT. RustDesk works in
                                         both cases, and it is what the installer
                                         provisions, so it is the only door left.
                                         Enabled only when the id is known and the
                                         agent is elevated: without either, the
                                         session would fail after the click. --}}
                                    @if(filled($device->rustdesk_id))
                                        <x-row-action variant="info" icon="remote"
                                                      x-on:click.prevent="$dispatch('open-remote', { id: {{ $device->id }} })"
                                                      title="Buka sesi remote RustDesk">Remote</x-row-action>
                                    @endif
                                    <x-row-action icon="power" @click="$dispatch('open-modal', 'power-agent-{{ $device->id }}')">Power</x-row-action>
                                    <form method="POST" action="{{ route('agent.scan', $device) }}" data-modal-action class="inline-flex">
                                        @csrf
                                        <x-row-action type="submit" icon="laptop-pulse">Scan</x-row-action>
                                    </form>
                                    {{-- Per-device update. Until this existed, moving one
                                         laptop onto a new build meant Update All, which
                                         restarts every agent in the office to fix the
                                         machine in front of you. Shown only when the
                                         device is actually behind: offering it on a
                                         current device invites a pointless restart. --}}
                                    @if($device->isOutdated() === true)
                                        <x-row-action variant="warning" icon="refresh" @click="$dispatch('open-modal', 'confirm-update-agent-{{ $device->id }}')">Update</x-row-action>
                                    @endif
                                    {{-- Promote to admin. Only shown when the agent
                                         has reported it is a standard user: asking an
                                         already-elevated agent would pop a pointless
                                         UAC prompt. Null (never reported) shows
                                         nothing, since we do not know its state. --}}
                                    @if($telemetry?->is_elevated === false)
                                        <x-row-action icon="shield-plus" @click="$dispatch('open-modal', 'confirm-elevate-agent-{{ $device->id }}')">Make admin</x-row-action>
                                    @endif
                                @endif

                                {{-- Removing an agent deletes files on someone else's
                                     machine, so it is the one action that asks twice:
                                     once in this modal, once more on the server side.
                                     Only reachable here: a revoked device lives in its own
                                     card, where nothing can be queued any more. --}}
                                {{-- Conditions here are deliberately flat, never nested.

                                     Two Blade traps are being avoided here, and both
                                     cost a long debugging session. A single-line php
                                     block written with the short form compiles to an
                                     unterminated open tag, which swallows the rest of
                                     the template into PHP and leaves every following
                                     conditional uncompiled. And a component rendered
                                     inside a conditional nested in another conditional
                                     produces an if/endif pair that does not balance.
                                     Both failures point somewhere else entirely - an
                                     undefined variable, or a parse error on a line
                                     that has nothing to do with this edit.

                                     Note: this comment deliberately spells no
                                     directives out. Naming one inside a Blade comment
                                     is enough for it to be compiled as real markup,
                                     which is exactly the imbalance this comment is
                                     warning about. --}}
                                @php
                                    $operation = $activeOperations->get($device->id);
                                    $isUninstallRunning = $operation !== null
                                        && $operation->command === 'uninstall'
                                        && $operation->isRunning();
                                    $isUninstallQueued = $operation !== null
                                        && $operation->command === 'uninstall'
                                        && ! $operation->isRunning();
                                @endphp

                                {{-- Everything above is routine; this is the one action
                                     that changes someone else's machine, so it is set
                                     apart by a rule rather than being one more chip in
                                     the same row. Colour alone is not enough separation
                                     when the cell wraps. --}}
                                <span class="mx-0.5 h-6 w-px shrink-0 bg-gray-200 dark:bg-gray-700" aria-hidden="true"></span>

                                @if($isUninstallRunning)
                                    {{-- Red, and labelled "stalled", when the agent has
                                         stopped reporting mid-operation. A confident
                                         45% for an hour is the exact failure this
                                         progress bar exists to expose. --}}
                                    <div class="min-w-[10rem]">
                                        <div class="mb-1 text-xs {{ $operation->isStalled() ? 'font-medium text-error-500' : 'text-gray-500 dark:text-gray-400' }}">
                                            {{ $operation->progressLabel() }}
                                        </div>
                                        <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                            <div class="h-full rounded-full transition-all duration-500 {{ $operation->isStalled() ? 'bg-error-500' : 'bg-brand-500' }}" style="width: {{ max($operation->progress ?? 5, 5) }}%"></div>
                                        </div>
                                    </div>
                                @elseif($isUninstallQueued)
                                    <x-badge variant="warning" :dot="true">uninstall queued</x-badge>
                                @else
                                    <x-row-action variant="danger" icon="trash" @click="$dispatch('open-modal', 'uninstall-agent-{{ $device->id }}')">Uninstall</x-row-action>
                                @endif
                                @if($device->rustdesk_id)
                                    <x-row-action variant="info" icon="remote" href="rustdesk://{{ $device->rustdesk_id }}" target="_blank" rel="noopener" title="Remote via RustDesk (self-hosted)">Remote</x-row-action>
                                @endif
                                <x-row-action variant="danger" icon="trash" @click.stop="$dispatch('open-modal', 'delete-agent-{{ $device->id }}')">Delete</x-row-action>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Page size is a dropdown rather than a bare number field: the useful
         values are a short fixed set, and a free-text box would invite
         nonsense the controller then has to reject. --}}
    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-4 text-theme-xs dark:border-gray-800">
        <form method="GET" class="flex items-center gap-2">
            <label for="per_page" class="text-gray-500 dark:text-gray-400">Show</label>
            {{-- min-w, not px-2: a three-digit option plus the native dropdown
                 arrow does not fit in the padding this had, and the browser
                 clipped the last digit under the arrow. --}}
            <select id="per_page" name="per_page" onchange="this.form.submit()"
                    class="min-w-[4.5rem] rounded-lg border border-gray-300 bg-white py-1 pl-3 pr-8 text-gray-700 focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
                @foreach($pageSizes as $size)
                    <option value="{{ $size }}" @selected($perPage === $size)>{{ $size }}</option>
                @endforeach
            </select>
            <span class="text-gray-500 dark:text-gray-400">per page</span>
            @if(request()->query('per_page'))
                <a href="{{ route('agent.fleet') }}" class="text-brand-500 hover:text-brand-600">reset</a>
            @endif
        </form>

        <p class="text-gray-500 dark:text-gray-400">
            Showing {{ $paginatedDevices->firstItem() ?? 0 }}–{{ $paginatedDevices->lastItem() ?? 0 }}
            of {{ $paginatedDevices->total() }}
        </p>
    </div>

    <div class="mt-3">
        {{ $paginatedDevices->links() }}
    </div>

    {{-- One detail modal per device on this page. Everything the agent reports
         is here, in a wrapping grid, so the table above stays scannable without
         hiding a single reading. Rendered only for the current page: looping the
         whole collection would put every laptop's name in the HTML regardless of
         pagination. --}}
    @foreach($paginatedDevices as $device)
        @php
            $t = $device->telemetry;
            $fp = $device->fingerprint;
            $deviceFindings = $findingsByToken[$device->id] ?? [];
            $audit = $t?->asset_id ? ($latestAudits[$device->id] ?? null) : null;
        @endphp
        {{-- 5xl on desktop: three balanced columns of readings. On smaller
             screens the columns collapse to two, then one. --}}
        <x-modal id="device-{{ $device->id }}" title="{{ $device->name }}" maxWidth="full">
            <div class="pr-1">
                <div class="mb-3 flex flex-wrap items-center gap-2">
                @if($t?->asset_id !== null)
                    <a href="{{ route('assets.show', $t->asset) }}" class="text-theme-sm font-medium text-brand-500 hover:text-brand-600">
                        {{ $t->asset->asset_id }}
                    </a>
                @elseif($device->isUsable())
                    {{-- Also offered in the detail modal: an operator who opened a
                         device to look at its readings is exactly the person who
                         then decides whether it belongs on the register. --}}
                    <a href="{{ route('agent.promote', $device) }}"
                       class="text-theme-sm font-medium text-warning-600 hover:text-warning-700">
                        Add to register <x-icon name="arrow-right" class="h-4 w-4" />
                    </a>
                @endif
                    @if($t?->hostname)
                        <span class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $t->hostname }}</span>
                    @endif
                </div>

                @if($deviceFindings)
                    <div class="mb-4 flex flex-wrap gap-1">
                        @foreach($deviceFindings as $finding)
                            <x-badge :variant="$finding['severity'] === 'danger' ? 'error' : 'warning'">{{ $finding['label'] }}</x-badge>
                        @endforeach
                    </div>
                @endif

                {{-- Balanced newspaper columns: sections flow in reading order
                     (Device, Hardware, Storage...) and the browser equalises the
                     column heights. `break-inside-avoid` keeps a section from
                     splitting across a break, which is what the previous plain
                     CSS-columns attempt got wrong. --}}
                <div class="columns-1 gap-8 sm:columns-2 xl:columns-3">
                    @foreach(($rowsByDevice[$device->id] ?? []) as $section => $fields)
                        <section class="mb-5 break-inside-avoid">
                            <h4 class="mb-2 border-b border-gray-100 pb-1.5 text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                {{ $section }}
                            </h4>
                            <dl class="space-y-1 text-theme-xs">
                                @foreach($fields as $label => $value)
                                    <div class="flex items-baseline gap-3">
                                        <dt class="w-24 shrink-0 text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                                        <dd class="min-w-0 flex-1 break-words text-gray-800 dark:text-gray-200">
                                            {{ $value !== null && $value !== '' ? $value : '—' }}
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        </section>
                    @endforeach
                </div>

                {{-- Printers, as a compact list rather than a full table.
                     The inventory page has the detail; here an operator wants
                     only to know whether the machine can still reach its
                     printers, which is the count and the offline flag. --}}
                @if(($printersByFingerprint[$fp?->id] ?? collect())->isNotEmpty())
                    @php
                        $devicePrinters = $printersByFingerprint[$fp->id];
                    @endphp
                    <section class="mt-5 border-t border-gray-100 pt-3 dark:border-gray-800">
                        <h4 class="mb-2 text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            Printers
                            @if($devicePrinters->where('is_offline', true)->isNotEmpty())
                                <span class="ml-1 rounded-full bg-warning-50 px-2 py-0.5 text-warning-600 dark:bg-warning-500/15 dark:text-warning-500">
                                    {{ $devicePrinters->where('is_offline', true)->count() }} offline
                                </span>
                            @endif
                        </h4>
                        <ul class="space-y-1 text-theme-xs">
                            @foreach($devicePrinters->sortBy('name') as $printer)
                                <li class="flex items-baseline gap-3">
                                    <span class="min-w-0 flex-1 break-words text-gray-800 dark:text-gray-200">
                                        {{ $printer->name }}
                                        @if($printer->is_default)
                                            <span class="ml-1 text-gray-400">(default)</span>
                                        @endif
                                    </span>
                                    <span class="shrink-0 text-gray-500 dark:text-gray-400">
                                        {{ $printer->is_network ? 'network' : 'local' }}
                                        @if($printer->is_offline)
                                            · offline
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if($audit)
                    {{-- The agent reports a difference and stops there; it never
                         writes to the register, because a compromised laptop
                         could otherwise edit its own record. Accepting the scan
                         is a person's decision, and it is the second half of
                         "match / no match" that the fleet page was missing.

                         Only offered on MISMATCH. On a MATCH there is nothing to
                         apply, and on an INCOMPLETE scan the missing sensors are
                         not evidence of anything. --}}
                    <div class="mt-4 border-t border-gray-100 pt-3 dark:border-gray-800">
                        <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                            Last audit {{ $audit->result }} · {{ $audit->audit_date?->diffForHumans(['locale' => 'en']) }}
                            @if($fp?->collected_at)
                                · inventory {{ $fp->collected_at->diffForHumans(['locale' => 'en']) }}
                            @endif
                        </p>

                        @if($audit->result === 'MISMATCH' && $audit->mismatches && $device->isUsable())
                            @can('update', $audit->asset)
                                <div class="mt-2 rounded-lg bg-warning-50 p-2.5 dark:bg-warning-500/10">
                                    <p class="text-theme-xs font-medium text-warning-800 dark:text-warning-300">
                                        The register disagrees with this machine
                                    </p>
                                    <ul class="mt-1 space-y-0.5">
                                        @foreach($audit->mismatches as $field => $values)
                                            <li class="text-theme-xs text-warning-700 dark:text-warning-300/90">
                                                <span class="font-medium">{{ str_replace('_', ' ', $field) }}:</span>
                                                {{ $values['registered'] }} → {{ $values['actual'] }}
                                            </li>
                                        @endforeach
                                    </ul>
                                    <form method="POST" action="{{ route('audit.apply-scan', $audit) }}" class="mt-2">
                                        @csrf
                                        <button type="submit" class="mt-2 inline-flex items-center justify-center rounded-lg px-2.5 py-1.5 text-theme-xs font-bold text-warning-700 transition-all duration-200 hover:bg-warning-100 active:scale-95 dark:text-warning-300 dark:hover:bg-warning-500/20">
                                            Update {{ $audit->asset->asset_id }} from this scan <x-icon name="arrow-right" class="h-4 w-4" />
                                        </button>
                                    </form>
                                </div>
                            @else
                                <p class="mt-2 text-theme-xs text-warning-700 dark:text-warning-300">
                                    {{ count($audit->mismatches) }} field(s) differ.
                                    <a href="{{ route('assets.edit', $audit->asset) }}" class="font-medium underline">Edit the asset</a> to correct it.
                                </p>
                            @endcan
                        @endif
                    </div>
                @elseif($fp?->collected_at)
                    <p class="mt-4 border-t border-gray-100 pt-3 text-theme-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
                        Inventory {{ $fp->collected_at->diffForHumans(['locale' => 'en']) }}
                    </p>
                @endif
            </div>

            <x-slot:footer>
                <a href="{{ route('agent.fingerprint', $device) }}" class="text-theme-sm font-medium text-brand-500 hover:text-brand-600">
                    Full inventory & software
                </a>
                <a href="{{ route('agent.download', $device) }}" class="text-theme-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400">
                    Reinstall
                </a>
            </x-slot:footer>
        </x-modal>
    @endforeach
    @endif

    {{-- Uninstall stays a separate dialog rather than living inside the detail
         modal: it deletes files on someone else's machine, and a confirmation
         that shares a surface with 36 read-only fields makes the one dangerous
         action easy to hit by accident. --}}
    @foreach($paginatedDevices as $device)
        @continue($pendingUninstalls->has($device->id))

        <x-modal id="uninstall-agent-{{ $device->id }}" title="Uninstall agent" maxWidth="md">
            <div class="space-y-4">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-error-50 text-error-500 dark:bg-error-500/15">
                        <x-icon name="warning" class="h-5 w-5" />
                    </span>
                    <p class="text-theme-sm text-gray-600 dark:text-gray-300">
                        Remove the agent from <span class="font-semibold text-gray-800 dark:text-white">{{ $device->name }}</span>?
                    </p>
                </div>

                <p class="text-theme-sm text-gray-600 dark:text-gray-300">
                    On its next poll — within 10 seconds — the agent deletes its autostart entry and its
                    program folder on that machine, then the token is revoked.
                </p>

                <div class="rounded-xl border border-warning-200 bg-warning-50 px-4 py-3 text-theme-sm text-warning-700 dark:border-warning-500/20 dark:bg-warning-500/10 dark:text-warning-300">
                    A machine that is currently offline will do this the next time it comes back online.
                    This cannot be undone from here.
                </div>

                <form method="POST" action="{{ route('agent.uninstall', $device) }}" class="flex justify-end gap-3 pt-2" data-modal-action data-close-modal="uninstall-agent-{{ $device->id }}">
                    @csrf
                    <button type="button" @click="$dispatch('close-modal', 'uninstall-agent-{{ $device->id }}')" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</button>
                    <x-button type="submit" variant="danger">Uninstall agent</x-button>
                </form>
            </div>
        </x-modal>

        <x-modal id="delete-agent-{{ $device->id }}" title="Delete device" maxWidth="md">
            <div class="space-y-4">
                <p class="text-theme-sm text-gray-600 dark:text-gray-300">Permanently remove <strong>{{ $device->name }}</strong> from the fleet? This cannot be undone.</p>
                <form method="POST" action="{{ route('agent.destroy', $device) }}" class="flex justify-end gap-3 pt-2" data-modal-action data-close-modal="delete-agent-{{ $device->id }}">
                    @csrf
                    <button type="button" @click="$dispatch('close-modal', 'delete-agent-{{ $device->id }}')" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</button>
                    <x-button type="submit" variant="danger">Delete</x-button>
                </form>
            </div>
        </x-modal>
    @endforeach
</x-card>

{{-- Revoked devices live on their own card. Their token can never authenticate
     again, so every action in the main table is dead for them: keeping them
     interleaved only diluted the machines an operator actually manages. --}}
<x-card class="mt-6" title="Revoked Devices" subtitle="These agents can no longer reach the server. Nothing can be queued for them.">
    @if($revokedDevices->isEmpty())
        <p class="py-8 text-center text-theme-sm text-gray-400">No revoked devices.</p>
    @else
        <x-table
            :headers="['Device', 'Hostname', 'Asset', 'Last Seen', 'Status', 'Actions']"
            empty-text="No revoked devices."
            min-width="720px"
        >
            @foreach($revokedDevices as $device)
                @php
                    $telemetry = $device->telemetry;
                    $asset = $telemetry?->asset;
                @endphp
                <tr class="border-b border-gray-100 dark:border-gray-800">
                    <td class="py-3 px-4">
                        <p class="font-medium text-gray-700 dark:text-gray-300">{{ $device->name }}</p>
                        <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $device->device_id ?? '—' }}</p>
                    </td>
                    <td class="py-3 px-4 text-theme-sm text-gray-600 dark:text-gray-400">
                        {{ $telemetry?->hostname ?? '—' }}
                    </td>
                    <td class="py-3 px-4 text-theme-sm">
                        @if($asset)
                            <a href="{{ route('assets.show', $asset) }}" class="font-medium text-brand-500 hover:text-brand-600">{{ $asset->asset_id }}</a>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="py-3 px-4 text-theme-sm text-gray-600 dark:text-gray-400">
                        {{ $device->last_used_at?->diffForHumans(['locale' => 'en']) ?? 'never' }}
                    </td>
                    <td class="py-3 px-4">
                        <x-badge variant="error" :dot="true">revoked</x-badge>
                    </td>
                    <td class="py-3 px-4">
                        @if($device->rustdesk_id)
                            <x-row-action variant="info" icon="remote" href="rustdesk://{{ $device->rustdesk_id }}" title="Remote via RustDesk (self-hosted)">Remote</x-row-action>
                        @endif
                        <x-row-action variant="danger" icon="trash" @click.stop="$dispatch('open-modal', 'delete-agent-{{ $device->id }}')">Delete</x-row-action>
                    </td>
                </tr>
            @endforeach
        </x-table>
    @endif
</x-card>

@foreach($revokedDevices as $device)
    <x-modal id="delete-agent-{{ $device->id }}" title="Delete device" maxWidth="md">
        <div class="space-y-4">
            <p class="text-theme-sm text-gray-600 dark:text-gray-300">Permanently remove <strong>{{ $device->name }}</strong> from the fleet? This cannot be undone.</p>
            <form method="POST" action="{{ route('agent.destroy', $device) }}" class="flex justify-end gap-3 pt-2" data-modal-action data-close-modal="delete-agent-{{ $device->id }}">
                @csrf
                <button type="button" @click="$dispatch('close-modal', 'delete-agent-{{ $device->id }}')" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</button>
                <x-button type="submit" variant="danger">Delete</x-button>
            </form>
        </div>
    </x-modal>
@endforeach

<x-modal id="confirm-update-all-agents" title="Update all agents" maxWidth="md">
    <div class="space-y-4">
        <p class="text-theme-sm text-gray-600 dark:text-gray-300">Outdated agents will download the update and restart. Devices already on the current release are skipped.</p>
        <form method="POST" action="{{ route('agent.updateAll') }}" class="flex justify-end gap-3" data-modal-action data-close-modal="confirm-update-all-agents">
            @csrf
            <button type="button" @click="$dispatch('close-modal', 'confirm-update-all-agents')" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</button>
            <x-button type="submit" variant="primary">Update agents</x-button>
        </form>
    </div>
</x-modal>

@foreach($paginatedDevices as $device)
    @if($device->isUsable())
        <x-modal id="power-agent-{{ $device->id }}" title="Power — {{ $device->name }}" maxWidth="md">
            <form method="POST" action="{{ route('agent.power', $device) }}" class="space-y-4" data-modal-action data-close-modal="power-agent-{{ $device->id }}">
                @csrf
                <p class="text-theme-sm text-gray-600 dark:text-gray-300">
                    Ask <span class="font-semibold text-gray-800 dark:text-white">{{ $device->name }}</span> to restart, shut down, or log out the signed-in user.
                </p>

                <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                    <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 hover:border-brand-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
                        <input type="radio" name="action" value="restart" class="h-4 w-4 text-brand-600 focus:ring-brand-500" required>
                        <span>Restart</span>
                    </label>
                    <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 hover:border-brand-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
                        <input type="radio" name="action" value="shutdown" class="h-4 w-4 text-brand-600 focus:ring-brand-500">
                        <span>Shut down</span>
                    </label>
                    <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 hover:border-brand-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
                        <input type="radio" name="action" value="logoff" class="h-4 w-4 text-brand-600 focus:ring-brand-500">
                        <span>Log off</span>
                    </label>
                </div>

                <label for="delay-{{ $device->id }}" class="block text-theme-sm font-medium text-gray-700 dark:text-gray-300">
                    Delay (seconds)
                    <input type="number" name="delay_seconds" id="delay-{{ $device->id }}"
                           value="60" min="0" max="3600"
                           class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-theme-sm text-gray-900 focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                </label>
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                    Default 60 seconds, so anyone at the machine sees the Windows warning before it happens. Set 0 for immediate.
                </p>

                <div class="flex justify-end gap-3">
                    <button type="button" @click="$dispatch('close-modal', 'power-agent-{{ $device->id }}')" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</button>
                    <x-button type="submit" variant="warning">Apply</x-button>
                </div>
            </form>
        </x-modal>
    @endif
@endforeach

@foreach($paginatedDevices as $device)
    @if($device->isUsable() && $device->isOutdated() === true)
        <x-modal id="confirm-update-agent-{{ $device->id }}" title="Update agent" maxWidth="md">
            <div class="space-y-4">
                <p class="text-theme-sm text-gray-600 dark:text-gray-300">Update <span class="font-semibold text-gray-800 dark:text-white">{{ $device->name }}</span>? It will download the latest agent and restart.</p>
                <form method="POST" action="{{ route('agent.updateOne', $device) }}" class="flex justify-end gap-3" data-modal-action data-close-modal="confirm-update-agent-{{ $device->id }}">
                    @csrf
                    <button type="button" @click="$dispatch('close-modal', 'confirm-update-agent-{{ $device->id }}')" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</button>
                    <x-button type="submit" variant="warning">Update agent</x-button>
                </form>
            </div>
        </x-modal>
    @endif
@endforeach

@foreach($paginatedDevices as $device)
    @if($device->isUsable() && $device->telemetry?->is_elevated === false)
        <x-modal id="confirm-elevate-agent-{{ $device->id }}" title="Make this agent an administrator" maxWidth="md">
            <div class="space-y-4">
                <p class="text-theme-sm text-gray-600 dark:text-gray-300">
                    Ask <span class="font-semibold text-gray-800 dark:text-white">{{ $device->name }}</span>
                    to become a system service, so it can run machine-level scripts (USB disable, registry writes).
                </p>
                <p class="rounded-lg bg-warning-50 px-3 py-2 text-theme-xs text-warning-700 dark:bg-warning-500/10 dark:text-warning-300">
                    Someone must be at that machine to approve the Windows administrator prompt. Until it is approved, the agent stays a standard user.
                </p>
                <form method="POST" action="{{ route('agent.elevate', $device) }}" class="flex justify-end gap-3" data-modal-action data-close-modal="confirm-elevate-agent-{{ $device->id }}">
                    @csrf
                    <button type="button" @click="$dispatch('close-modal', 'confirm-elevate-agent-{{ $device->id }}')" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</button>
                    <x-button type="submit" variant="primary">Request admin</x-button>
                </form>
            </div>
        </x-modal>
    @endif
@endforeach

{{--
    Remote session launcher.

    One modal for the whole page rather than one per row: the session is a single
    global action, and duplicating the markup N times would put N copies of the
    polling script in the document.

    The flow is deliberately short - click Remote, a password is issued, RustDesk
    opens with everything filled in. The operator never types or copies anything.
--}}
<div x-data="remoteSession()"
     x-on:open-remote.window="start($event.detail.id)"
     x-on:keydown.escape.window="close()">

    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-[9998] flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-gray-950/70 backdrop-blur-sm"
                 x-show="open"
                 x-transition:enter="transition duration-200 ease-out"
                 x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition duration-150 ease-in"
                 x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 @click="close()"></div>

            <div class="relative w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-black/10 dark:bg-gray-900 dark:ring-white/10"
                 x-show="open"
                 x-transition:enter="transition duration-300 ease-[cubic-bezier(0.16,1,0.3,1)]"
                 x-transition:enter-start="opacity-0 translate-y-4 scale-[0.97]"
                 x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                 x-transition:leave="transition duration-200 ease-in"
                 x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                 x-transition:leave-end="opacity-0 translate-y-2 scale-[0.98]">

                <div class="flex items-start gap-4 border-b border-gray-100 px-6 py-5 dark:border-gray-800">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
                        <x-icon name="remote" class="h-5 w-5" />
                    </div>
                    <div class="min-w-0">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Sesi Remote</h3>
                        <p class="mt-0.5 truncate text-theme-xs text-gray-500 dark:text-gray-400" x-text="device"></p>
                    </div>
                </div>

                <div class="px-6 py-5">
                    <div x-show="stage === 'working'" class="flex items-center gap-3">
                        <div class="h-5 w-5 animate-spin rounded-full border-2 border-gray-200 border-t-brand-500"></div>
                        <p class="text-theme-sm text-gray-600 dark:text-gray-300" x-text="step"></p>
                    </div>

                    <p x-show="stage === 'error'" class="text-theme-sm text-error-600 dark:text-error-400" x-text="message"></p>

                    <div x-show="stage === 'ready'" class="space-y-3">
                        <p class="text-theme-sm text-gray-600 dark:text-gray-300">
                            RustDesk akan terbuka dan langsung terhubung. Jika browser menanyakan izin
                            membuka aplikasi, pilih <strong>Buka</strong>.
                        </p>
                        <a :href="uri" class="flex w-full items-center justify-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-theme-sm font-semibold text-white transition hover:bg-brand-600 active:scale-[0.99]">
                            <x-icon name="remote" class="h-4 w-4" /> Buka Remote Desktop
                        </a>
                        <p class="text-center text-theme-xs text-gray-400">
                            ID <span class="font-mono" x-text="id"></span> · password baru dibuat untuk sesi ini
                        </p>
                    </div>
                </div>

                <div class="flex justify-end border-t border-gray-100 px-6 py-4 dark:border-gray-800">
                    <button type="button" @click="close()"
                            class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
                        Tutup
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>

<script>
    function remoteSession() {
        return {
            open: false,
            stage: 'working',
            step: '',
            message: '',
            device: '',
            id: '',
            uri: '',

            async start(deviceId) {
                this.open = true;
                this.stage = 'working';
                this.step = 'Menyiapkan sesi...';
                this.message = '';
                document.body.style.overflow = 'hidden';

                try {
                    const response = await fetch(`/agent/${deviceId}/remote`, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                            Accept: 'application/json',
                        },
                        credentials: 'same-origin',
                    });

                    const data = await response.json();

                    if (!response.ok || !data.ok) {
                        this.stage = 'error';
                        this.message = data.message ?? `Gagal memulai sesi (HTTP ${response.status}).`;
                        return;
                    }

                    this.device = data.device;
                    this.id = data.id;
                    this.uri = data.uri;
                    this.stage = 'ready';

                    // The command has to reach the device before the password is
                    // valid there. Watch it instead of guessing a sleep: the
                    // operator sees exactly when the machine is ready.
                    this.step = 'Menunggu perangkat menerapkan password...';
                    this.watch(data.command_id);
                } catch (e) {
                    this.stage = 'error';
                    this.message = 'Tidak bisa menghubungi server.';
                    console.error(e);
                }
            },

            async watch(commandId) {
                for (let attempt = 0; attempt < 40; attempt++) {
                    await new Promise((resolve) => setTimeout(resolve, 1500));

                    try {
                        const response = await fetch(`/agent/commands/${commandId}/status`, {
                            headers: { Accept: 'application/json' },
                            credentials: 'same-origin',
                        });

                        if (!response.ok) {
                            continue;
                        }

                        const data = await response.json();

                        if (data.done) {
                            this.step = 'Siap.';
                            // Opened from the click's own stack, so the browser
                            // still treats it as user-initiated and does not block
                            // the protocol handler.
                            window.location.href = this.uri;
                            return;
                        }

                        if (data.failed) {
                            this.stage = 'error';
                            this.message = data.message ?? 'Perangkat gagal menerapkan password.';
                            return;
                        }
                    } catch (e) {
                        // Transient: keep polling until the loop runs out.
                    }
                }

                this.stage = 'error';
                this.message = 'Perangkat tidak merespons. Pastikan laptop menyala dan agent berjalan.';
            },

            close() {
                this.open = false;
                document.body.style.overflow = '';
            },
        };
    }
</script>
@endsection


