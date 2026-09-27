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

<div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <x-card>
        <p class="text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Devices</p>
        <div class="mt-2 flex items-end justify-between">
            <p class="text-title-sm font-bold text-gray-800 dark:text-white">{{ $stats['devices'] }}</p>
            <x-badge variant="brand">{{ $stats['online'] }} online</x-badge>
        </div>
        <p class="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">Devices installed and registered with this server.</p>
    </x-card>

    <x-card>
        <p class="text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Battery Health</p>
        <div class="mt-2 flex items-end justify-between">
            <p class="text-title-sm font-bold text-gray-800 dark:text-white">
                {{ $stats['avg_battery_health'] !== null ? round($stats['avg_battery_health']).'%' : '—' }}
            </p>
            @if($stats['battery_alerts'] > 0)
                <x-badge variant="warning">{{ $stats['battery_alerts'] }} alert(s)</x-badge>
            @else
                <x-badge variant="success">healthy</x-badge>
            @endif
        </div>
        <p class="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">Average capacity left vs. design capacity across the fleet.</p>
    </x-card>

    <x-card>
        <p class="text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Disk Alerts</p>
        <div class="mt-2 flex items-end justify-between">
            <p class="text-title-sm font-bold text-gray-800 dark:text-white">{{ $stats['disk_alerts'] }}</p>
            @if($stats['disk_alerts'] > 0)
                <x-badge variant="error">below 15% free</x-badge>
            @else
                <x-badge variant="success">all clear</x-badge>
            @endif
        </div>
        <p class="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">Laptops whose C: drive is running out of space.</p>
    </x-card>

    <x-card>
        <p class="text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Pending Commands</p>
        <div class="mt-2 flex items-end justify-between">
            <p class="text-title-sm font-bold text-gray-800 dark:text-white">{{ $stats['pending_commands'] }}</p>
            <x-badge variant="{{ $stats['pending_commands'] > 0 ? 'warning' : 'gray' }}">queue</x-badge>
        </div>
        <p class="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">Waiting for the next agent poll (every 10 seconds).</p>
    </x-card>
</div>

<x-card title="Devices" subtitle="Refreshes automatically every 30 seconds. Telemetry arrives with every agent scan.">
    @if($devices->isEmpty())
        <p class="py-8 text-center text-theme-sm text-gray-400">
            No devices registered yet. Open <a href="{{ route('agent.index') }}" class="font-medium text-brand-500 hover:text-brand-600">Tokens</a> and download the agent to enrol a laptop.
        </p>
    @else
    <x-table :headers="['Device', 'Status', 'Logged-in User', 'Battery', 'Disk (C:)', 'RAM / Network', 'Last Audit', 'Actions']">
        @foreach($devices as $device)
            @php
                $telemetry = $device->telemetry;
                $audit = $telemetry?->asset_id ? ($latestAudits[$telemetry->asset_id] ?? null) : null;
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
                        @if($telemetry?->wifi_ssid)
                            Wi-Fi {{ $telemetry->wifi_ssid }}{{ $telemetry->wifi_signal !== null ? ' · '.$telemetry->wifi_signal.'%' : '' }}
                        @else
                            {{ $telemetry?->gpu ?? 'no adapter data' }}
                        @endif
                    </p>
                </td>
                <td class="py-3 px-4">
                    @if($audit)
                        <x-badge :variant="$auditVariant">{{ $audit->result }}</x-badge>
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">{{ $audit->audit_date?->diffForHumans() }}</p>
                    @else
                        <span class="text-theme-sm text-gray-400">—</span>
                    @endif
                </td>
                <td class="py-3 px-4">
                    <div class="flex items-center gap-3">
                        @if($device->isUsable())
                            <form method="POST" action="{{ route('agent.scan', $device) }}">
                                @csrf
                                <button type="submit" class="text-theme-sm font-medium text-brand-500 hover:text-brand-600">Scan Now</button>
                            </form>
                        @endif
                        <a href="{{ route('agent.download', $device) }}" class="text-theme-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400">Reinstall</a>

                        {{-- Removing an agent deletes files on someone else's
                             machine, so it is the one action that asks twice:
                             once here, once more on the server side. --}}
                        @if($pendingUninstalls->has($device->id))
                            <span class="text-theme-xs text-warning-600 dark:text-warning-500">Uninstall queued…</span>
                        @else
                            <form method="POST" action="{{ route('agent.uninstall', $device) }}"
                                  onsubmit="return confirm(@js('Remove the agent from ' . $device->name . '?\n\nIt deletes its autostart entry and its program folder on that machine within 10 seconds, and revokes the token. This cannot be undone from here.'));">
                                @csrf
                                <button type="submit" class="text-theme-sm font-medium text-error-500 hover:text-error-600">Uninstall</button>
                            </form>
                        @endif
                    </div>
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
