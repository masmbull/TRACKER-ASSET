@extends('layouts.app')

@section('title', $token->name.' — Inventory')

@section('content')
@php
    $fingerprint = $token->fingerprint;
    $telemetry = $token->telemetry;
    $findings = $fingerprint?->findings() ?? [];
    $software = $fingerprint?->software ?? collect();
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
                        'BitLocker' => $fingerprint->bitlocker_status,
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
                        'CPU cores' => $fingerprint->cpu_cores !== null ? $fingerprint->cpu_physical_cores.' physical / '.$fingerprint->cpu_cores.' logical' : null,
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
            <x-badge variant="brand">{{ $software->count() }} programs</x-badge>
        </div>

        {{-- Filtering is client-side only. A laptop can carry several hundred programs
             and the browser already holds the whole list, so a server round-trip per
             keystroke would be pure waste. --}}
        <div class="mt-4" x-data="{ q: '' }">
            <input type="search" x-model="q" placeholder="Filter by name, publisher or version…"
                   class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-theme-sm text-gray-800 focus:border-brand-500 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
        </div>

        <div class="mt-4 max-h-96 overflow-y-auto">
            <table class="w-full text-left text-theme-sm">
                <thead class="sticky top-0 bg-white text-theme-xs uppercase tracking-wider text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                    <tr>
                        <th class="py-2 pr-4">Program</th>
                        <th class="py-2 pr-4">Version</th>
                        <th class="py-2 pr-4">Publisher</th>
                        <th class="py-2">Installed</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($software as $program)
                        <tr class="border-t border-gray-100 dark:border-gray-700"
                            x-show="'{{ $program->name }} {{ $program->publisher }} {{ $program->version }}'.toLowerCase().includes(q)">
                            <td class="py-2 pr-4 font-medium text-gray-800 dark:text-white">{{ $program->name }}</td>
                            <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $program->version ?? '—' }}</td>
                            <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $program->publisher ?? '—' }}</td>
                            <td class="py-2 text-gray-600 dark:text-gray-300">{{ $program->installed_at?->format('d M Y') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-6 text-center text-gray-500 dark:text-gray-400">
                                No software was reported by this device.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
@endif
@endsection
