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
                        // An agent older than the heartbeat never re-reports readings, but
                        // it still polls the inbox, so a recent last_used_at is proof of
                        // life. Without this every un-updated machine would show "stale"
                        // once the 2-minute telemetry window lapsed.
                        $polling = $device->last_used_at?->gt(now()->subSeconds(90)) ?? false;
                        $online = $telemetry?->isOnline() ?? false;
                        $deviceFindings = $findingsByToken[$device->id] ?? [];
                        $deviceOperation = $activeOperations->get($device->id);
                    @endphp
                    <tr class="border-b border-gray-100 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/5" data-fleet-device="{{ $device->id }}">
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
                                        <svg width="10" height="10" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 1 3 4v6c0 4.4 3 8 7 9 4-1 7-4.6 7-9V4l-7-3Zm3.7 6.7-4.4 4.4a1 1 0 0 1-1.4 0L6.3 10.5a1 1 0 0 1 1.4-1.4l.9.9 3.7-3.7a1 1 0 1 1 1.4 1.4Z" clip-rule="evenodd"/></svg>
                                        admin
                                    </span>
                                @elseif($telemetry?->is_elevated === false)
                                    <span class="mt-1 inline-flex items-center gap-1 rounded-full bg-warning-50 px-2 py-0.5 text-theme-xs font-medium text-warning-700 dark:bg-warning-500/10 dark:text-warning-300" title="Agent runs as a standard user — system-level scripts will fail until it is elevated">
                                        <svg width="10" height="10" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.5 2h3l.4 2.2 1.6.9 2-1 1.5 2.6-1.7 1.4v1.8l1.7 1.4-1.5 2.6-2-1-1.6.9L11.5 16h-3l-.4-2.2-1.6-.9-2 1-1.5-2.6 1.7-1.4V8.1L3 6.7l1.5-2.6 2 1 1.6-.9L8.5 2ZM10 11a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z" clip-rule="evenodd"/></svg>
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

                            @if(! $device->isUsable())
                                <x-badge variant="error" :dot="true" data-fleet-status-badge>revoked</x-badge>
                            @elseif($online || $polling)
                                <x-badge variant="success" :dot="true" data-fleet-status-badge>online</x-badge>
                            @elseif($telemetry === null && $isSettingUp)
                                {{-- A machine mid-install has no telemetry yet, and
                                     "never scanned" reads as a fault with the machine
                                     rather than an install still running on it. --}}
                                <x-badge variant="gray" :dot="true" data-fleet-status-badge>{{ $deviceOperation->command }}ing</x-badge>
                            @elseif($telemetry === null)
                                <x-badge variant="gray" :dot="true" data-fleet-status-badge>never scanned</x-badge>
                            @else
                                <x-badge variant="warning" :dot="true" data-fleet-status-badge>stale</x-badge>
                            @endif
                            <span class="mt-1 block text-theme-xs text-gray-500 dark:text-gray-400" data-fleet-reported-at>
                                {{ $telemetry?->reported_at ? $telemetry->reported_at->diffForHumans() : 'no report yet' }}
                            </span>

                            {{-- Install and update progress. Without this a rollout is
                                 a blank wait: there is no way to tell a machine working
                                 through its first inventory from one that hung
                                 halfway, and the first inventory is the slow step. --}}
                            @if($isSettingUp)
                                <div class="mt-2 w-full max-w-[12rem]">
                                    <div class="mb-1 truncate text-theme-xs {{ $deviceOperation->isStalled() ? 'font-medium text-error-500' : 'text-gray-500 dark:text-gray-400' }}">
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
                            @if($telemetry?->asset)
                                <a href="{{ route('assets.show', $telemetry->asset) }}"
                                   class="text-theme-sm font-medium text-brand-500 hover:text-brand-600">
                                    {{ $telemetry->asset->asset_id }}
                                </a>
                            @elseif($device->isUsable())
                                <a href="{{ route('agent.promote', $device) }}"
                                   class="whitespace-nowrap text-theme-sm font-medium text-warning-600 hover:text-warning-700">
                                    Add to register
                                </a>
                                <span class="mt-0.5 block text-theme-xs text-gray-500 dark:text-gray-400">
                                    {{ $unmatchedScans[$device->id] ?? 'No' }} scan{{ ($unmatchedScans[$device->id] ?? 0) === 1 ? '' : 's' }} waiting
                                </span>
                            @else
                                <span class="text-theme-sm text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="py-3 px-3">
                            <div class="flex flex-wrap items-center justify-end gap-1">
                                <button type="button"
                                        class="inline-flex items-center justify-center rounded-lg px-2.5 py-1.5 text-theme-xs font-semibold whitespace-nowrap text-brand-600 transition-all duration-200 hover:bg-brand-50 active:scale-95 dark:text-brand-300 dark:hover:bg-brand-500/10"
                                        @click="$dispatch('open-modal', 'device-{{ $device->id }}')">Detail</button>

                                @if($device->isUsable())
                                    <form method="POST" action="{{ route('agent.scan', $device) }}" data-modal-action class="inline-flex">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center justify-center rounded-lg px-2.5 py-1.5 text-theme-xs font-semibold whitespace-nowrap text-gray-600 transition-all duration-200 hover:bg-gray-100 active:scale-95 dark:text-gray-300 dark:hover:bg-white/5">Scan</button>
                                    </form>
                                    {{-- Per-device update. Until this existed, moving one
                                         laptop onto a new build meant Update All, which
                                         restarts every agent in the office to fix the
                                         machine in front of you. Shown only when the
                                         device is actually behind: offering it on a
                                         current device invites a pointless restart. --}}
                                    @if($device->isOutdated() === true)
                                        <button type="button" class="inline-flex items-center justify-center rounded-lg px-2.5 py-1.5 text-theme-xs font-semibold whitespace-nowrap text-warning-600 transition-all duration-200 hover:bg-warning-50 active:scale-95 dark:text-warning-300 dark:hover:bg-warning-500/10" @click="$dispatch('open-modal', 'confirm-update-agent-{{ $device->id }}')">Update</button>
                                    @endif
                                    {{-- Promote to admin. Only shown when the agent
                                         has reported it is a standard user: asking an
                                         already-elevated agent would pop a pointless
                                         UAC prompt. Null (never reported) shows
                                         nothing, since we do not know its state. --}}
                                    @if($telemetry?->is_elevated === false)
                                        <button type="button" class="inline-flex items-center justify-center rounded-lg px-2.5 py-1.5 text-theme-xs font-semibold whitespace-nowrap text-brand-600 transition-all duration-200 hover:bg-brand-50 active:scale-95 dark:text-brand-300 dark:hover:bg-brand-500/10" @click="$dispatch('open-modal', 'confirm-elevate-agent-{{ $device->id }}')">Make admin</button>
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
                                    <button type="button"
                                            class="inline-flex items-center justify-center rounded-lg px-2.5 py-1.5 text-theme-xs font-semibold whitespace-nowrap text-error-500 transition-all duration-200 hover:bg-error-50 active:scale-95 dark:text-error-400 dark:hover:bg-error-500/10"
                                            @click="$dispatch('open-modal', 'uninstall-agent-{{ $device->id }}')">Uninstall</button>
                                @endif
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
                    @if($t?->asset)
                        <a href="{{ route('assets.show', $t->asset) }}" class="text-theme-sm font-medium text-brand-500 hover:text-brand-600">
                            {{ $t->asset->asset_id }}
                        </a>
                    @elseif($device->isUsable())
                        {{-- Also offered in the detail modal: an operator who opened a
                             device to look at its readings is exactly the person who
                             then decides whether it belongs on the register. --}}
                        <a href="{{ route('agent.promote', $device) }}"
                           class="text-theme-sm font-medium text-warning-600 hover:text-warning-700">
                            Add to register →
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
                            Last audit {{ $audit->result }} · {{ $audit->audit_date?->diffForHumans() }}
                            @if($fp?->collected_at)
                                · inventory {{ $fp->collected_at->diffForHumans() }}
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
                                            Update {{ $audit->asset->asset_id }} from this scan →
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
                        Inventory {{ $fp->collected_at->diffForHumans() }}
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
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" />
                        </svg>
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
            :headers="['Device', 'Hostname', 'Asset', 'Last Seen', 'Revoked']"
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
                        {{ $device->last_used_at?->diffForHumans() ?? 'never' }}
                    </td>
                    <td class="py-3 px-4">
                        <x-badge variant="error" :dot="true">revoked</x-badge>
                    </td>
                </tr>
            @endforeach
        </x-table>
    @endif
</x-card>

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
@endsection
