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
<x-card title="Active Devices" subtitle="Click any device to see everything it reports.">
    @if($activeDevices->isEmpty())
        <p class="py-8 text-center text-theme-sm text-gray-400">
            No active devices. Open <a href="{{ route('agent.index') }}" class="font-medium text-brand-500 hover:text-brand-600">Tokens</a> and download the agent to enrol a laptop.
        </p>
    @else

    {{-- A compact table, because 36 readings per device inline turned the page
         into a wall of text. The row carries the three things an operator scans
         a fleet for - is it up, is the battery dying, is the disk full - and
         everything else waits one click away in the detail modal. --}}
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr class="border-b border-gray-100 dark:border-gray-800">
                    <th class="py-2.5 px-3 text-left text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Device</th>
                    <th class="py-2.5 px-3 text-left text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Status</th>
                    <th class="py-2.5 px-3 text-left text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Battery</th>
                    <th class="py-2.5 px-3 text-left text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Disk</th>
                    <th class="py-2.5 px-3 text-left text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Security</th>
                    <th class="py-2.5 px-3 text-right text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Actions</th>
                </tr>
            </thead>
            <tbody>
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
                    @endphp
                    <tr class="border-b border-gray-100 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/5">
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
                            </button>
                        </td>
                        <td class="py-3 px-3">
                            @if(! $device->isUsable())
                                <x-badge variant="error" :dot="true">revoked</x-badge>
                            @elseif($online || $polling)
                                <x-badge variant="success" :dot="true">online</x-badge>
                            @elseif($telemetry === null)
                                <x-badge variant="gray" :dot="true">never scanned</x-badge>
                            @else
                                <x-badge variant="warning" :dot="true">stale</x-badge>
                            @endif
                            <span class="mt-1 block text-theme-xs text-gray-500 dark:text-gray-400">
                                {{ $telemetry?->reported_at ? $telemetry->reported_at->diffForHumans() : 'no report yet' }}
                            </span>
                        </td>
                        <td class="py-3 px-3 text-theme-sm text-gray-700 dark:text-gray-300">
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
                        <td class="py-3 px-3 text-theme-sm text-gray-700 dark:text-gray-300">
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
                            <div class="flex items-center justify-end gap-x-3 gap-y-1">
                                <button type="button"
                                        class="whitespace-nowrap text-theme-sm font-medium text-brand-500 hover:text-brand-600"
                                        @click="$dispatch('open-modal', 'device-{{ $device->id }}')">Detail</button>

                                @if($device->isUsable())
                                    <form method="POST" action="{{ route('agent.scan', $device) }}">
                                        @csrf
                                        <button type="submit" class="whitespace-nowrap text-theme-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400">Scan</button>
                                    </form>
                                @endif

                                {{-- Removing an agent deletes files on someone else's
                                     machine, so it is the one action that asks twice:
                                     once in this modal, once more on the server side.
                                     Only reachable here: a revoked device lives in its own
                                     card, where nothing can be queued any more. --}}
                                @if($pendingUninstalls->has($device->id))
                                    <x-badge variant="warning" :dot="true">uninstall queued</x-badge>
                                @else
                                    <button type="button"
                                            class="whitespace-nowrap text-theme-sm font-medium text-error-500 hover:text-error-600"
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
            $audit = $t?->asset_id ? ($latestAudits[$t->asset_id] ?? null) : null;
        @endphp
        {{-- 2xl, not xl: this modal carries two side-by-side columns of readings,
             and at xl a value like the Windows edition wrapped onto three lines
             for every device. The scroll container stays for the very tall case. --}}
        <x-modal id="device-{{ $device->id }}" title="{{ $device->name }}" maxWidth="2xl">
            <div class="max-h-[70vh] overflow-y-auto pr-1">
                <div class="mb-3 flex flex-wrap items-center gap-2">
                    @if($t?->asset)
                        <a href="{{ route('assets.show', $t->asset) }}" class="text-theme-sm font-medium text-brand-500 hover:text-brand-600">
                            {{ $t->asset->asset_id }}
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

                {{-- Two explicit columns.
                     Neither of the previous approaches worked. A plain grid gave
                     every section its own cell, so a 10-row Security section
                     next to a 2-row Software one left the columns badly uneven.
                     CSS `columns` balanced the height but then let a section
                     split across the break, cutting "Security" in half.

                     So the split is done here: sections are dealt round-robin
                     into two column lists, which keeps the reading order (Device,
                     Hardware, Storage...) intact while keeping the two columns
                     close to the same height.

                     The value cell carries min-w-0 because a flex item defaults
                     to min-width:auto: without it a long value like "Microsoft
                     Windows 11 Home Single Language 10.0.26200" refuses to
                     shrink, spills out of its column and lands on top of the
                     neighbouring label. break-words handles a single token with
                     no spaces in it. --}}
                @php
                    // The sections arrive keyed by name, so a string is used as
                    // the index - `$index % 2` on "Device" is a TypeError, not a
                    // silent slip. Re-key them numerically, keeping the names
                    // alongside for the headings.
                    $names = array_keys($rowsByDevice[$device->id] ?? []);
                    $left = [];
                    $right = [];
                    foreach (array_values($rowsByDevice[$device->id] ?? []) as $index => $fields) {
                        if ($index % 2 === 0) {
                            $left[$index] = $fields;
                        } else {
                            $right[$index] = $fields;
                        }
                    }
                @endphp

                <div class="grid gap-x-10 sm:grid-cols-2">
                    @foreach([$left, $right] as $column)
                        <div class="space-y-5">
                            @foreach($column as $index => $fields)
                                <section>
                                    <h4 class="mb-2 border-b border-gray-100 pb-1.5 text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                        {{ $names[$index] }}
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
                    @endforeach
                </div>
                </dl>

                @if($audit)
                    <p class="mt-4 border-t border-gray-100 pt-3 text-theme-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
                        Last audit {{ $audit->result }} · {{ $audit->audit_date?->diffForHumans() }}
                        @if($fp?->collected_at)
                            · inventory {{ $fp->collected_at->diffForHumans() }}
                        @endif
                    </p>
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
