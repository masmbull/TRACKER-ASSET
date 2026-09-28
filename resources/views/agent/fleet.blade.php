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

{{-- One summary line instead of five KPI cards. Each card held a big number
     and a sentence explaining it, which is a lot of ink for facts that are
     only glanceable; the numbers an operator acts on are still here, just
     without the card scaffolding. --}}
<div class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-theme-sm text-gray-600 dark:text-gray-300">
    <span><span class="font-semibold text-gray-800 dark:text-white">{{ $stats['online'] }}</span>/{{ $stats['devices'] }} online</span>
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
    @if($stats['pending_commands'] > 0)
        <x-badge variant="brand">{{ $stats['pending_commands'] }} queued</x-badge>
    @endif
    @if($stats['battery_alerts'] === 0 && $stats['disk_alerts'] === 0 && $stats['security_alerts'] === 0)
        <x-badge variant="success">all clear</x-badge>
    @endif
</div>
<x-card title="Active Devices" subtitle="Every reading the agent reports. Refreshes every 30 seconds.">
    @if($activeDevices->isEmpty())
        <p class="py-8 text-center text-theme-sm text-gray-400">
            No active devices. Open <a href="{{ route('agent.index') }}" class="font-medium text-brand-500 hover:text-brand-600">Tokens</a> and download the agent to enrol a laptop.
        </p>
    @else

    {{-- Was a 1520px-wide table with nine columns, which meant horizontal
         scrolling and cells too narrow to hold a hostname, an SSID or a GPU
         string. Each device is now a block that wraps, so every value the
         agent sends is actually readable. --}}
    <div class="space-y-4">
        @foreach($paginatedDevices as $device)
            @php
                $telemetry = $device->telemetry;
                $fp = $device->fingerprint;
                $audit = $telemetry?->asset_id ? ($latestAudits[$telemetry->asset_id] ?? null) : null;
                // An agent older than the heartbeat never re-reports readings, but
                // it still polls the inbox, so a recent last_used_at is proof of
                // life. Without this every un-updated machine would show "stale"
                // once the 2-minute telemetry window lapsed.
                $polling = $device->last_used_at?->gt(now()->subSeconds(90)) ?? false;
                $online = $telemetry?->isOnline() ?? false;
                $deviceFindings = $findingsByToken[$device->id] ?? [];
                $auditVariant = match ($audit?->result) {
                    'MATCH' => 'success',
                    'MISMATCH' => 'warning',
                    'NEW DEVICE' => 'brand',
                    default => 'gray',
                };
            @endphp

            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                {{-- Header: identity + status + actions. --}}
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <a href="{{ route('agent.fingerprint', $device) }}" class="break-all text-theme-sm font-semibold text-gray-800 hover:text-brand-600 dark:text-white">
                                {{ $device->name }}
                            </a>
                            @if(! $device->isUsable())
                                <x-badge variant="error" :dot="true">revoked</x-badge>
                            @elseif($online || $polling)
                                <x-badge variant="success" :dot="true">online</x-badge>
                            @elseif($telemetry === null)
                                <x-badge variant="gray" :dot="true">never scanned</x-badge>
                            @else
                                <x-badge variant="warning" :dot="true">stale</x-badge>
                            @endif
                        </div>
                        <p class="mt-0.5 break-all text-theme-xs text-gray-500 dark:text-gray-400">
                            {{ $telemetry?->hostname ?? '—' }}
                            @if($telemetry?->asset)
                                · <a href="{{ route('assets.show', $telemetry->asset) }}" class="font-medium text-brand-500 hover:text-brand-600">{{ $telemetry->asset->asset_id }}</a>
                            @endif
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        @if($device->isUsable())
                            <form method="POST" action="{{ route('agent.scan', $device) }}">
                                @csrf
                                <button type="submit" class="whitespace-nowrap text-theme-sm font-medium text-brand-500 hover:text-brand-600">Scan Now</button>
                            </form>
                        @endif
                        <a href="{{ route('agent.download', $device) }}" class="whitespace-nowrap text-theme-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400">Reinstall</a>

                        {{-- Removing an agent deletes files on someone else's
                             machine, so it is the one action that asks twice:
                             once in this modal, once more on the server side.
                             Only reachable here: a revoked device lives in its own
                             card, where nothing can be queued any more. --}}
                        @if($pendingUninstalls->has($device->id))
                            <x-badge variant="warning" :dot="true">Uninstall queued</x-badge>
                        @else
                            <button type="button"
                                    class="whitespace-nowrap text-theme-sm font-medium text-error-500 hover:text-error-600"
                                    @click="$dispatch('open-modal', 'uninstall-agent-{{ $device->id }}')">Uninstall</button>
                        @endif
                    </div>
                </div>

                {{-- Every telemetry and fingerprint reading the agent reports, in a
                     wrapping label/value grid. `break-words` on the values is what
                     keeps a long SSID or GPU string from stretching the column, and
                     the list is built by the controller so the template only lays
                     it out. --}}
                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-theme-xs sm:grid-cols-3 lg:grid-cols-4">
                    @foreach($rowsByDevice[$device->id] ?? [] as $label => $value)
                        <div class="min-w-0">
                            <dt class="text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                            <dd class="break-words text-gray-800 dark:text-gray-200">
                                {{ $value !== null && $value !== '' ? $value : '—' }}
                            </dd>
                        </div>
                    @endforeach
                </dl>

                <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3 text-theme-xs dark:border-gray-800">
                    @if($deviceFindings)
                        @foreach($deviceFindings as $finding)
                            <x-badge :variant="$finding['severity'] === 'danger' ? 'error' : 'warning'">{{ $finding['label'] }}</x-badge>
                        @endforeach
                    @elseif($fp)
                        <x-badge variant="success">security clean</x-badge>
                    @else
                        {{-- "Not yet reported" rather than a grey dash: the agent
                             sends its first report on the poll after install, so
                             a blank here is usually just timing. --}}
                        <span class="text-gray-400">security not yet reported</span>
                    @endif

                    @if($audit)
                        <x-badge :variant="$auditVariant">audit {{ $audit->result }}</x-badge>
                        <span class="text-gray-500 dark:text-gray-400">{{ $audit->audit_date?->diffForHumans() }}</span>
                    @endif

                    @if($fp?->collected_at)
                        <span class="text-gray-500 dark:text-gray-400">inventory {{ $fp->collected_at->diffForHumans() }}</span>
                    @endif

                    <a href="{{ route('agent.fingerprint', $device) }}" class="ml-auto font-medium text-brand-500 hover:text-brand-600">Detail →</a>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Page size is a dropdown rather than a bare number field: the useful
         values are a short fixed set, and a free-text box would invite
         nonsense the controller then has to reject. --}}
    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-4 text-theme-xs dark:border-gray-800">
        <form method="GET" class="flex items-center gap-2">
            <label for="per_page" class="text-gray-500 dark:text-gray-400">Show</label>
            <select id="per_page" name="per_page" onchange="this.form.submit()"
                    class="rounded-lg border border-gray-300 bg-white px-2 py-1 text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
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
    @endif
    {{-- One confirm dialog per device, rendered only for the devices on this
         page. Iterating the full collection here (as the table version did)
         would put every laptop's name in the HTML regardless of pagination,
         which both bloats the response and makes the page say a machine is
         present when it is not. --}}
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
