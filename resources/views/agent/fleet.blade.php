@extends('layouts.app')

@section('title', 'Live Fleet')

@section('content')
<x-page-header title="Live Fleet" subtitle="Latest telemetry of every deployed device — battery wear, C: disk space, RAM, Wi-Fi and the logged-in Windows user.">
    <x-slot:actions>
        <div class="flex flex-wrap items-center gap-2">
            <form method="POST" action="{{ route('agent.scanAll') }}">
                @csrf
                <x-button type="submit" variant="secondary" size="sm">Scan All Devices</x-button>
            </form>
            <form method="POST" action="{{ route('agent.updateAll') }}">
                @csrf
                <x-button type="submit" variant="primary" size="sm">Update All Agents</x-button>
            </form>
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

{{-- 4 KPI cards. min-w-0 on the card + flex-wrap on the value row keeps a
     long badge from pushing the big number out of a narrow grid track. --}}
<div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
    <x-card class="min-w-0">
        <p class="text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Devices</p>
        <div class="mt-2 flex flex-wrap items-end justify-between gap-2">
            <p class="text-title-sm font-bold text-gray-800 dark:text-white">{{ $stats['devices'] }}</p>
            <x-badge variant="brand" class="shrink-0">{{ $stats['online'] }} online</x-badge>
        </div>
        <p class="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">Devices installed and registered with this server.</p>
    </x-card>

    <x-card class="min-w-0">
        <p class="text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Battery Health</p>
        <div class="mt-2 flex flex-wrap items-end justify-between gap-2">
            <p class="text-title-sm font-bold text-gray-800 dark:text-white">
                {{ $stats['avg_battery_health'] !== null ? round($stats['avg_battery_health']).'%' : '—' }}
            </p>
            @if($stats['battery_alerts'] > 0)
                <x-badge variant="warning" class="shrink-0">
                    {{ $stats['battery_alerts'] }} {{ $stats['battery_alerts'] === 1 ? 'alert' : 'alerts' }}
                </x-badge>
            @else
                <x-badge variant="success" class="shrink-0">healthy</x-badge>
            @endif
        </div>
        <p class="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">Average capacity left vs. design capacity across the fleet.</p>
    </x-card>

    <x-card class="min-w-0">
        <p class="text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Disk Alerts</p>
        <div class="mt-2 flex flex-wrap items-end justify-between gap-2">
            <p class="text-title-sm font-bold text-gray-800 dark:text-white">{{ $stats['disk_alerts'] }}</p>
            @if($stats['disk_alerts'] > 0)
                <x-badge variant="error" class="shrink-0">below 15% free</x-badge>
            @else
                <x-badge variant="success" class="shrink-0">all clear</x-badge>
            @endif
        </div>
        <p class="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">Laptops whose C: drive is running out of space.</p>
    </x-card>

    <x-card class="min-w-0">
        <p class="text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Pending Commands</p>
        <div class="mt-2 flex flex-wrap items-end justify-between gap-2">
            <p class="text-title-sm font-bold text-gray-800 dark:text-white">{{ $stats['pending_commands'] }}</p>
            <x-badge variant="{{ $stats['pending_commands'] > 0 ? 'warning' : 'gray' }}" class="shrink-0">queue</x-badge>
        </div>
        <p class="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">Waiting for the next agent poll (every 10 seconds).</p>
    </x-card>

    {{-- Security sits last on purpose: it is the newest and least expected
         signal, and an operator scanning for battery/disk trouble should not
         have to look past it. --}}
    <x-card class="min-w-0">
        <p class="text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Security Findings</p>
        <div class="mt-2 flex flex-wrap items-end justify-between gap-2">
            <p class="text-title-sm font-bold text-gray-800 dark:text-white">{{ $stats['security_alerts'] }}</p>
            @if($stats['critical_alerts'] > 0)
                <x-badge variant="error" class="shrink-0">{{ $stats['critical_alerts'] }} critical</x-badge>
            @elseif($stats['security_alerts'] > 0)
                <x-badge variant="warning" class="shrink-0">advisory</x-badge>
            @else
                <x-badge variant="success" class="shrink-0">all clear</x-badge>
            @endif
        </div>
        <p class="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">
            Across {{ $stats['fingerprinted'] }} of {{ $stats['devices'] }} reported devices.
        </p>
    </x-card>
</div>

<x-card title="Active Devices" subtitle="Refreshes automatically every 30 seconds. Telemetry arrives with every agent scan and heartbeat.">
    @if($activeDevices->isEmpty())
        <p class="py-8 text-center text-theme-sm text-gray-400">
            No active devices. Open <a href="{{ route('agent.index') }}" class="font-medium text-brand-500 hover:text-brand-600">Tokens</a> and download the agent to enrol a laptop.
        </p>
    @else
    {{-- min-width stops the browser from crushing seven/eight columns into
         unreadable slivers on a wide screen; the x-table wrapper scrolls
         horizontally instead. --}}
    <x-table
        :headers="['Device', 'Status', 'Logged-in User', 'Battery', 'Disk (C:)', 'RAM / Network', 'Security', 'Last Audit', 'Actions']"
        min-width="1520px"
    >
        @foreach($activeDevices as $device)
            @php
                $telemetry = $device->telemetry;
                $audit = $telemetry?->asset_id ? ($latestAudits[$telemetry->asset_id] ?? null) : null;
                // An agent older than the heartbeat never re-reports readings, but
                // it still polls the inbox, so a recent last_used_at is proof of
                // life. Without this every un-updated machine would show "stale"
                // once the 2-minute telemetry window lapsed.
                $polling = $device->last_used_at?->gt(now()->subSeconds(90)) ?? false;
                $online = $telemetry?->isOnline() ?? false;
                $healthVariant = $telemetry?->battery_health === null ? 'gray' : ($telemetry->battery_health >= 80 ? 'success' : ($telemetry->battery_health >= 60 ? 'warning' : 'error'));
                $auditVariant = match ($audit?->result) {
                    'MATCH' => 'success',
                    'MISMATCH' => 'warning',
                    'NEW DEVICE' => 'brand',
                    default => 'gray',
                };
            @endphp
            <tr class="border-b border-gray-100 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/5">
                <td class="py-3 px-4">
                    <p class="font-medium text-gray-800 dark:text-white">{{ $device->name }}</p>
                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $telemetry?->hostname ?? $device->device_id ?? '—' }}</p>
                    @if($telemetry?->asset)
                        <a href="{{ route('assets.show', $telemetry->asset) }}" class="text-theme-xs font-medium text-brand-500 hover:text-brand-600">{{ $telemetry->asset->asset_id }}</a>
                    @endif
                </td>
                <td class="py-3 px-4">
                    @if(! $device->isUsable())
                        <x-badge variant="error" :dot="true">revoked</x-badge>
                    @elseif($online || $polling)
                        <x-badge variant="success" :dot="true">online</x-badge>
                    @elseif($telemetry === null)
                        <x-badge variant="gray" :dot="true">never scanned</x-badge>
                    @else
                        <x-badge variant="warning" :dot="true">stale</x-badge>
                    @endif
                    <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                        {{ $telemetry?->reported_at ? 'report '.$telemetry->reported_at->diffForHumans() : 'no report yet' }}
                    </p>
                </td>
                <td class="py-3 px-4 text-theme-sm text-gray-700 dark:text-gray-300">
                    {{ $telemetry?->logged_in_user ?? '—' }}
                    @if($telemetry?->uptime_hours !== null)
                        <p class="text-theme-xs text-gray-500 dark:text-gray-400">up {{ round($telemetry->uptime_hours / 24, 1) }} days</p>
                    @endif
                </td>
                <td class="py-3 px-4">
                    @if($telemetry?->battery_percent !== null)
                        <div class="flex items-center gap-2">
                            <div class="h-2 w-20 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                <div class="h-2 rounded-full {{ $telemetry->hasLowBattery() ? 'bg-error-500' : 'bg-success-500' }}" style="width: {{ min(100, max(0, $telemetry->battery_percent)) }}%"></div>
                            </div>
                            <span class="text-theme-sm text-gray-700 dark:text-gray-300">{{ $telemetry->battery_percent }}%</span>
                        </div>
                    @else
                        <span class="text-theme-sm text-gray-400">—</span>
                    @endif
                    <div class="mt-1 flex flex-wrap items-center gap-1">
                        @if($telemetry?->battery_health !== null)
                            <x-badge :variant="$healthVariant">health {{ $telemetry->battery_health }}%</x-badge>
                        @endif
                        @if($telemetry?->battery_cycle_count !== null)
                            <span class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $telemetry->battery_cycle_count }} cycles</span>
                        @endif
                    </div>
                </td>

                <td class="py-3 px-4">
                    @if($telemetry?->disk_free_gb !== null)
                        <p class="text-theme-sm text-gray-700 dark:text-gray-300">
                            {{ number_format($telemetry->disk_free_gb, 1) }} GB free
                            <span class="text-theme-xs text-gray-500 dark:text-gray-400">of {{ number_format((float) $telemetry->disk_total_gb, 1) }} GB</span>
                        </p>
                        <div class="mt-1 h-2 w-24 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                            <div class="h-2 rounded-full {{ $telemetry->hasLowDisk() ? 'bg-error-500' : ($telemetry->disk_free_percent < 30 ? 'bg-warning-500' : 'bg-success-500') }}" style="width: {{ min(100, max(0, $telemetry->disk_free_percent)) }}%"></div>
                        </div>
                    @else
                        <span class="text-theme-sm text-gray-400">—</span>
                    @endif
                </td>
                <td class="py-3 px-4 text-theme-sm text-gray-700 dark:text-gray-300">
                    {{ $telemetry?->ram_config ?? '—' }}
                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                        {{-- An Ethernet laptop has no SSID at all, and falling
                             through to the GPU string made it look like the
                             machine had no network. Report the real link. --}}
                        @if($telemetry?->wifi_ssid)
                            Wi-Fi {{ $telemetry->wifi_ssid }}{{ $telemetry->wifi_signal !== null ? ' · '.$telemetry->wifi_signal.'%' : '' }}
                        @elseif($telemetry?->network_adapter)
                            {{ ucfirst($telemetry->network_type ?: 'network') }} {{ $telemetry->network_adapter }}{{ $telemetry->network_speed ? ' · '.$telemetry->network_speed : '' }}
                        @else
                            {{ $telemetry?->ip_address ? 'Network '.$telemetry->ip_address : 'no adapter data' }}
                        @endif
                    </p>
                </td>
                <td class="py-3 px-4">
                    @php $deviceFindings = $findingsByToken[$device->id] ?? []; @endphp
                    @if($device->fingerprint)
                        @if(count($deviceFindings) > 0)
                            <div class="flex flex-wrap gap-1">
                                @foreach($deviceFindings as $finding)
                                    <x-badge :variant="$finding['severity'] === 'danger' ? 'error' : 'warning'">
                                        {{ $finding['label'] }}
                                    </x-badge>
                                @endforeach
                            </div>
                        @else
                            <x-badge variant="success">clean</x-badge>
                        @endif
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                            {{ $device->fingerprint->software_count ?? 0 }} programs
                            · {{ $device->fingerprint->collected_at?->diffForHumans() }}
                        </p>
                    @else
                        {{-- "Not yet reported" rather than a grey dash: the agent
                             sends its first report on the poll after install, so
                             a blank here is usually just timing. --}}
                        <span class="text-theme-sm text-gray-400">not yet reported</span>
                    @endif
                </td>
                <td class="py-3 px-4">
                    @if($audit)
                        <x-badge :variant="$auditVariant">{{ $audit->result }}</x-badge>
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">{{ $audit->audit_date?->diffForHumans() }}</p>
                    @else
                        <span class="text-theme-sm text-gray-400">—</span>
                    @endif
                </td>
                {{-- flex-wrap + whitespace-nowrap: three actions in one cell used to
                     overflow on narrow screens, and the queued note used to wrap
                     mid-word. --}}
                <td class="py-3 px-4">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        @if($device->isUsable())
                            <form method="POST" action="{{ route('agent.scan', $device) }}">
                                @csrf
                                <button type="submit" class="whitespace-nowrap text-theme-sm font-medium text-brand-500 hover:text-brand-600">Scan Now</button>
                            </form>
                        @endif
                        <a href="{{ route('agent.download', $device) }}" class="whitespace-nowrap text-theme-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400">Reinstall</a>

                        {{-- Removing an agent deletes files on someone else's machine,
                             so it is the one action that asks twice: once in this
                             modal, once more on the server side. Only reachable
                             here: a revoked device lives in its own card, where
                             nothing can be queued for it any more. --}}
                        @if($pendingUninstalls->has($device->id))
                            <x-badge variant="warning" :dot="true">Uninstall queued</x-badge>
                        @else
                            <button type="button"
                                    class="whitespace-nowrap text-theme-sm font-medium text-error-500 hover:text-error-600"
                                    @click="$dispatch('open-modal', 'uninstall-agent-{{ $device->id }}')">Uninstall</button>
                        @endif
                    </div>
                </td>

            </tr>
        @endforeach
    </x-table>
    @endif

    {{-- One confirm dialog per device. The uninstall form lives here rather than
         in the table so the row keeps its compact button-only layout. --}}
    @foreach($activeDevices as $device)
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

                <form method="POST" action="{{ route('agent.uninstall', $device) }}" class="flex justify-end gap-3 pt-2">
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

<script>
    setTimeout(function () { window.location.reload(); }, 30000);
</script>
@endsection
