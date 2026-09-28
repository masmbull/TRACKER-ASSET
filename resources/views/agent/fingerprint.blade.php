@extends('layouts.app')

@section('title', $token->name.' — Inventory')

@section('content')
@php
    $fingerprint = $token->fingerprint;
    $telemetry = $token->telemetry;
    $findings = $fingerprint?->findings() ?? [];
@endphp

<x-page-header :title="$token->name" subtitle="Deep inventory — security posture, licensing and installed software.">
    <x-slot:actions>
        <div class="flex flex-wrap items-center gap-2">
            <form method="POST" action="{{ route('agent.scan', $token) }}">
                @csrf
                <x-button type="submit" variant="secondary" size="sm">Scan Now</x-button>
            </form>
            <x-button href="{{ route('agent.fleet') }}" variant="ghost" size="sm">Back to Live Fleet</x-button>
        </div>
    </x-slot:actions>
</x-page-header>

@if(! $fingerprint)
    <x-card>
        <p class="text-theme-sm text-gray-600 dark:text-gray-300">
            This device has not reported a deep inventory yet. The agent sends one on its first
            poll after install and then once a day, so the first report can take a moment after
            the machine comes online.
        </p>
    </x-card>
@else
    {{-- Findings first: an operator opening this page is usually looking for what is
         wrong, not for the full spec sheet. --}}
    @if(count($findings) > 0)
        <x-card class="mb-6">
            <p class="text-theme-sm font-semibold text-gray-800 dark:text-white">
                {{ count($findings) }} {{ count($findings) === 1 ? 'issue needs' : 'issues need' }} attention
            </p>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach($findings as $finding)
                    <x-badge :variant="$finding['severity'] === 'danger' ? 'danger' : 'warning'">
                        {{ $finding['label'] }}
                    </x-badge>
                @endforeach
            </div>
        </x-card>
    @else
        <x-card class="mb-6">
            <div class="flex flex-wrap items-center gap-2">
                <x-badge variant="success">No security findings</x-badge>
                <span class="text-theme-xs text-gray-500 dark:text-gray-400">
                    Last collected {{ $fingerprint->collected_at?->diffForHumans() }}.
                </span>
            </div>
        </x-card>
    @endif
    <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <x-card>
            <p class="text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Security</p>
            <dl class="mt-3 space-y-2 text-theme-sm">
                @php
                    $security = [
                        'Antivirus' => $fingerprint->antivirus_product,
                        'Real-time protection' => $fingerprint->antivirus_enabled === null ? null : ($fingerprint->antivirus_enabled ? 'On' : 'Off'),
                        'Signature age' => $fingerprint->antivirus_signature_age_days !== null ? $fingerprint->antivirus_signature_age_days.' days' : null,
                        'Firewall' => $fingerprint->firewall_enabled === null ? null : ($fingerprint->firewall_enabled ? 'On' : 'Off'),
                        'Firewall profiles off' => $fingerprint->firewall_profiles_off ?: null,
                        'Secure boot' => $fingerprint->secure_boot_enabled === null ? null : ($fingerprint->secure_boot_enabled ? 'On' : 'Off'),
                        'BitLocker' => $fingerprint->bitlocker_status,
                        'BitLocker method' => $fingerprint->bitlocker_method,
                        'User Account Control' => $fingerprint->uac_enabled === null ? null : ($fingerprint->uac_enabled ? 'On' : 'Off'),
                        'TPM' => $fingerprint->tpm_present === null ? null : ($fingerprint->tpm_present ? 'Present' : 'Absent'),
                        'RDP' => $fingerprint->rdp_enabled === null ? null : ($fingerprint->rdp_enabled ? 'Enabled' : 'Disabled'),
                        'Local admins' => $fingerprint->local_admin_count,
                        'Domain' => $fingerprint->domain,
                    ];
                @endphp
                @foreach($security as $label => $value)
                    <div class="flex items-center justify-between gap-4 border-b border-gray-100 pb-2 last:border-0 dark:border-gray-700">
                        <dt class="text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                        <dd class="text-right font-medium text-gray-800 dark:text-white">
                            {{ $value === null || $value === '' ? '—' : $value }}
                        </dd>
                    </div>
                @endforeach
            </dl>
        </x-card>

        <x-card>
            <p class="text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">System &amp; licensing</p>
            <dl class="mt-3 space-y-2 text-theme-sm">
                @php
                    $system = [
                        'Edition' => $fingerprint->windows_edition,
                        'Build' => $fingerprint->os_build,
                        'Activation' => $fingerprint->activation_status,
                        'Licence channel' => $fingerprint->license_channel,
                        'Installed on' => $fingerprint->os_installed_at?->format('d M Y'),
                        'Last boot' => $fingerprint->last_boot_at,
                        'Pending reboot' => $fingerprint->pending_reboot === null ? null : ($fingerprint->pending_reboot ? 'Yes' : 'No'),
                        'Time zone' => $fingerprint->timezone,
                        'Disk type' => $fingerprint->disk_type,
                        'SMART' => $fingerprint->smart_health,
                        'Disk predicted failure' => $fingerprint->smart_predicted_failure === null ? null : ($fingerprint->smart_predicted_failure ? 'Yes' : 'No'),
                        'CPU cores' => $fingerprint->cpu_cores !== null ? $fingerprint->cpu_physical_cores.' physical / '.$fingerprint->cpu_cores.' logical' : null,
                        'RAM' => $telemetry?->ram_config,
                        'RAM slots' => $ramSlots,
                        'GPU' => $telemetry?->gpu,
                        'Monitor' => $fingerprint->monitor,
                        'Startup entries' => $fingerprint->startup_count,
                    ];
                @endphp
                @foreach($system as $label => $value)
                    <div class="flex items-center justify-between gap-4 border-b border-gray-100 pb-2 last:border-0 dark:border-gray-700">
                        <dt class="text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                        <dd class="text-right font-medium text-gray-800 dark:text-white">
                            {{ $value === null || $value === '' ? '—' : $value }}
                        </dd>
                    </div>
                @endforeach
            </dl>
        </x-card>
    </div>

    <x-card>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                Installed software
            </p>
            <x-badge variant="brand">
                {{ $search !== '' ? $software->total().' of '.$softwareTotal : $softwareTotal }} programs
            </x-badge>
        </div>

        {{-- Filtering and paging happen on the server. The old version loaded
             every row into the page and hid them with Alpine, so a 400-program
             machine shipped 400 rows to the browser to display 60 of them: slow
             to load, impossible to page through, and the browser could not reach
             a program past the first screen. --}}
        <form method="GET" class="mt-4 flex flex-wrap items-center gap-2">
            <input type="search" name="q" value="{{ $search }}"
                   placeholder="Filter by name, publisher or version…"
                   class="min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-theme-sm text-gray-800 focus:border-brand-500 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
            <x-button type="submit" variant="secondary" size="sm">Search</x-button>
            @if($search !== '')
                <a href="{{ route('agent.fingerprint', $token) }}"
                   class="whitespace-nowrap text-theme-sm font-medium text-brand-500 hover:text-brand-600">Clear</a>
            @endif
        </form>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-theme-sm">
                <thead class="text-theme-xs uppercase tracking-wider text-gray-500 dark:text-gray-800 dark:text-gray-400">
                    <tr class="border-b border-gray-100 dark:border-gray-700">
                        <th class="py-2 pr-4">Program</th>
                        <th class="py-2 pr-4">Version</th>
                        <th class="py-2 pr-4">Publisher</th>
                        <th class="py-2">Installed</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($software as $program)
                        <tr class="border-t border-gray-100 dark:border-gray-700">
                            <td class="py-2 pr-4 font-medium text-gray-800 dark:text-white">{{ $program->name }}</td>
                            <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $program->version ?? '—' }}</td>
                            <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $program->publisher ?? '—' }}</td>
                            <td class="py-2 text-gray-600 dark:text-gray-300">{{ $program->installed_at?->format('d M Y') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-6 text-center text-gray-500 dark:text-gray-400">
                                @if($search !== '')
                                    No program matches "{{ $search }}".
                                @else
                                    No software was reported by this device.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Page size and filter are independent controls, so the select posts
             through the hidden form below rather than nesting forms (illegal in
             HTML). It carries the search term along, or changing the page size
             would silently drop an active filter. --}}
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-4 text-theme-xs dark:border-gray-800">
            <form id="software-pager" method="GET" class="flex items-center gap-2">
                <input type="hidden" name="q" value="{{ $search }}">
                <label for="per_page" class="text-gray-500 dark:text-gray-400">Show</label>
                <select id="per_page" name="per_page" onchange="this.form.submit()"
                        class="rounded-lg border border-gray-300 bg-white px-2 py-1 text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
                    @foreach($pageSizes as $size)
                        <option value="{{ $size }}" @selected($perPage === $size)>{{ $size }}</option>
                    @endforeach
                </select>
                <span class="text-gray-500 dark:text-gray-400">per page</span>
            </form>

            <p class="text-gray-500 dark:text-gray-400">
                Showing {{ $software->firstItem() ?? 0 }}–{{ $software->lastItem() ?? 0 }}
                of {{ $software->total() }}
            </p>
        </div>

        <div class="mt-3">
            {{ $software->links() }}
        </div>
    </x-card>
@endif
@endsection
