@extends('layouts.app')

@section('title', 'Dashboard')
@section('header', 'Dashboard')

@section('content')
@php
    $stats = [
        ['label' => 'Total Assets', 'value' => $totalAssets, 'text' => 'text-brand-600', 'icon' => 'assets.index', 'detail' => 'Across the register'],
        ['label' => 'Active', 'value' => $activeAssets, 'text' => 'text-success-600', 'icon' => 'success', 'detail' => 'In service'],
        ['label' => 'Available', 'value' => $availableAssets, 'text' => 'text-blue-light-700', 'icon' => 'access', 'detail' => 'Ready to assign'],
        ['label' => 'Assigned', 'value' => $assignedAssets, 'text' => 'text-warning-700', 'icon' => 'users', 'detail' => 'With a user'],
    ];
    $secondaryStats = [
        ['label' => 'Repair', 'value' => $repairAssets, 'text' => 'text-orange-600', 'icon' => 'repair'],
        ['label' => 'Retired', 'value' => $retiredAssets, 'text' => 'text-gray-600', 'icon' => 'assets.index'],
        ['label' => 'Disposed', 'value' => $disposedAssets, 'text' => 'text-error-600', 'icon' => 'warning'],
    ];
@endphp

<x-page-header title="Inventory overview" subtitle="A live snapshot of your organization’s IT inventory and fleet health.">
    <x-slot:actions>
        <x-button variant="secondary" size="sm" :href="route('barcode.form')">Scan barcode</x-button>
        @can('create', App\Models\Asset::class)
            <x-button variant="primary" size="sm" :href="route('assets.create')">Add asset</x-button>
        @endcan
    </x-slot:actions>
</x-page-header>

<section class="dashboard-hero mb-5" aria-label="Inventory overview">
    <div class="dashboard-hero-copy">
        <span class="dashboard-eyebrow"><span class="dashboard-live-dot"></span> LIVE INVENTORY SNAPSHOT</span>
        <h2>Know what you own.<br class="hidden sm:block">See what needs attention.</h2>
        <p>Track assignments, asset health, and fleet activity from one workspace.</p>
        <div class="mt-5 flex flex-wrap gap-2">
            <a href="{{ route('assets.index') }}" class="dashboard-hero-link">Explore inventory <x-icon name="arrow-right" class="h-4 w-4" /></a>
        </div>
    </div>
    <div class="dashboard-hero-figure" aria-hidden="true">
        <span class="dashboard-hero-orbit dashboard-hero-orbit-one"></span>
        <span class="dashboard-hero-orbit dashboard-hero-orbit-two"></span>
        <span class="dashboard-hero-total">{{ number_format($totalAssets) }}</span>
        <span class="dashboard-hero-caption">REGISTERED ASSETS</span>
        <span class="dashboard-hero-spark dashboard-hero-spark-one"></span>
        <span class="dashboard-hero-spark dashboard-hero-spark-two"></span>
    </div>
</section>

<div class="dashboard-section-heading">
    <div>
        <p class="dashboard-section-kicker">REGISTER</p>
        <h2>Asset overview</h2>
    </div>
    <span>Current status across your inventory</span>
</div>

<div class="dashboard-stat-grid stagger grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
    @foreach($stats as $stat)
        <x-card class="dashboard-stat-card !p-4 sm:!p-5">
            <div class="flex items-start justify-between gap-2 sm:gap-3">
                <div class="min-w-0">
                    <p class="text-theme-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 sm:text-theme-sm">{{ $stat['label'] }}</p>
                    <p class="dashboard-stat-value mt-2 text-2xl font-bold text-gray-900 dark:text-white sm:text-3xl">{{ number_format($stat['value']) }}</p>
                </div>
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gray-50 {{ $stat['text'] }} dark:bg-gray-800/70 sm:h-11 sm:w-11">
                    <x-icon :name="$stat['icon']" class="h-5 w-5 sm:h-[22px] sm:w-[22px]" />
                </span>
            </div>
            <p class="mt-3 border-t border-gray-100 pt-2.5 text-[11px] text-gray-500 dark:border-gray-700/70 dark:text-gray-400 sm:text-xs">{{ $stat['detail'] }}</p>
        </x-card>
    @endforeach
</div>

<section class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 border-y border-gray-200 py-3 dark:border-gray-800 sm:mt-5 sm:gap-x-8" aria-label="Other asset statuses">
    @foreach($secondaryStats as $stat)
        <div class="flex items-center gap-2 rounded-full bg-gray-50 px-2.5 py-1 dark:bg-gray-800/60">
            <x-icon :name="$stat['icon']" class="h-4 w-4 {{ $stat['text'] }}" />
            <span class="text-[10px] font-medium text-gray-500 dark:text-gray-400 sm:text-theme-xs">{{ $stat['label'] }}</span>
            <span class="text-theme-sm font-semibold {{ $stat['text'] }}">{{ number_format($stat['value']) }}</span>
        </div>
    @endforeach
</section>

<div class="stagger mt-6 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
    <x-card class="!p-4 sm:!p-5">
        <div class="mb-2 flex items-center gap-2"><x-icon name="repair" class="h-5 w-5 text-orange-500" /><h3 class="text-theme-sm font-semibold text-gray-700 dark:text-gray-200">Open Repairs</h3></div>
        <p class="text-xl font-bold text-orange-500 sm:text-title-sm">{{ $openRepairs }}</p>
    </x-card>
    <x-card class="!p-4 sm:!p-5">
        <div class="mb-2 flex items-center gap-2"><x-icon name="repair" class="h-5 w-5 text-gray-500" /><h3 class="text-theme-sm font-semibold text-gray-700 dark:text-gray-200">Total Repairs</h3></div>
        <p class="text-xl font-bold text-gray-800 dark:text-white/90 sm:text-title-sm">{{ $totalRepairs }}</p>
    </x-card>
    <x-card class="!p-4 sm:!p-5">
        <div class="mb-2 flex items-center gap-2"><x-icon name="transfer" class="h-5 w-5 text-brand-500" /><h3 class="text-theme-sm font-semibold text-gray-700 dark:text-gray-200">Recent Transfers</h3></div>
        <p class="text-xl font-bold text-brand-500 sm:text-title-sm">{{ $recentTransfers->count() }}</p>
    </x-card>
</div>

@php $totalWarnings = array_sum($warnings); @endphp
<x-card class="dashboard-warnings mt-6" x-data="{ expanded: true }">
    <button type="button"
            class="dashboard-warnings-toggle flex w-full items-center justify-between gap-4 text-left"
            @click="expanded = !expanded"
            :aria-expanded="expanded.toString()"
            aria-controls="dashboard-warning-list">
        <span class="flex min-w-0 items-center gap-3">
            <span class="dashboard-warnings-icon flex h-10 w-10 shrink-0 items-center justify-center rounded-xl">
                <x-icon name="warning" class="h-5 w-5" />
            </span>
            <span class="min-w-0">
                <span class="block text-theme-sm font-semibold text-gray-800 dark:text-white/90">Warnings</span>
                <span class="mt-0.5 block text-theme-xs text-gray-500 dark:text-gray-400">
                    {{ $totalWarnings > 0 ? $totalWarnings . ' items need review' : 'No inventory issues detected' }}
                </span>
            </span>
        </span>
        <span class="flex shrink-0 items-center gap-3">
            @if($totalWarnings > 0)
                <x-badge variant="warning" dot>{{ $totalWarnings }}</x-badge>
            @else
                <x-badge variant="success" dot>Clear</x-badge>
            @endif
            <x-icon name="chevron-down" class="h-5 w-5 text-gray-400 transition-transform duration-200" ::class="expanded ? 'rotate-180' : ''" />
        </span>
    </button>

    <div id="dashboard-warning-list" class="mt-5 border-t border-gray-100 pt-4 dark:border-gray-800" x-show="expanded" x-collapse x-cloak>
        @php
            $warningLabels = [
                'warranty_expiring' => ['Warranty Expiring (60d)', 'warning'],
                'not_audited' => ['Not Audited', 'warning'],
                'audit_mismatch' => ['Audit Mismatch', 'error'],
                'missing_serial' => ['Missing Serial', 'error'],
                'missing_asset_id' => ['Missing Asset ID', 'error'],
                'duplicate_serial' => ['Duplicate Serial', 'error'],
                'retirement_due' => ['Retirement Due', 'gray'],
                'audit_stale' => ['Audit Stale (>6mo)', 'warning'],
            ];
        @endphp
        <div class="stagger dashboard-warning-grid grid grid-cols-2 gap-3 md:grid-cols-4">
            @foreach($warningLabels as $key => $meta)
                <div class="dashboard-warning-item rounded-xl border border-gray-200 p-3 text-center dark:border-gray-800">
                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $meta[0] }}</p>
                    <p class="mt-1 text-xl font-bold text-gray-800 dark:text-white/90">{{ number_format($warnings[$key] ?? 0) }}</p>
                </div>
            @endforeach
        </div>
    </div>
</x-card>

<div class="stagger grid grid-cols-1 gap-6 md:grid-cols-3 mt-6">
    @php
        $charts = [
            ['title' => 'by Category', 'data' => $assetsByCategory, 'color' => 'brand'],
            ['title' => 'by Location', 'data' => $assetsByLocation, 'color' => 'success'],
            ['title' => 'by Company', 'data' => $assetsByCompany, 'color' => 'theme-purple-500'],
        ];
    @endphp
    @foreach($charts as $chart)
        <x-card title="Assets {{ $chart['title'] }}">
            @if($chart['data']->count() > 0)
                @php $max = $chart['data']->max('total'); @endphp
                <div class="space-y-3">
                    @foreach($chart['data'] as $item)
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $item->name }}</span>
                                <span class="text-theme-xs text-gray-400">{{ $item->total }}</span>
                            </div>
                            <div class="w-full bg-gray-100 dark:bg-gray-800 rounded-full h-2">
                                <div class="bg-{{ $chart['color'] }} h-2 rounded-full" style="width: {{ $max > 0 ? ($item->total / $max * 100) : 0 }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-theme-sm text-gray-400">No data available.</p>
            @endif
        </x-card>
    @endforeach
</div>

<x-card class="mt-6">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Agent Fleet</h3>
        @can('manage-agents')
            <div class="flex flex-wrap items-center gap-2">
                <x-button variant="secondary" size="sm" :href="route('agent.commands')">Command Center</x-button>
                <x-button variant="success" size="sm" type="button" @click="$dispatch('open-modal', 'scan-all-agents-modal')">Scan All</x-button>
            </div>
        @endcan
    </div>

    <div class="stagger grid grid-cols-2 md:grid-cols-4 gap-4">
        @php
            $fleetItems = [
                ['label' => 'Online', 'value' => $fleet['online'], 'variant' => 'success'],
                ['label' => 'Stale (>5m)', 'value' => $fleet['stale'], 'variant' => 'warning'],
                ['label' => 'Never Reported', 'value' => $fleet['offline'], 'variant' => 'gray'],
                ['label' => 'Pending Commands', 'value' => $fleet['pending_commands'], 'variant' => $fleet['pending_commands'] > 0 ? 'error' : 'gray'],
            ];
        @endphp
        @foreach($fleetItems as $fi)
            <div class="rounded-xl border border-gray-200 dark:border-gray-800 p-4 text-center">
                <x-icon :name="$fi['label'] === 'Online' ? 'success' : ($fi['label'] === 'Pending Commands' ? 'activity' : 'agent.fleet')" class="mx-auto mb-2 h-5 w-5 text-gray-400 dark:text-gray-500" />
                <x-badge :variant="$fi['variant']" class="text-lg font-bold px-3 py-1">{{ $fi['value'] }}</x-badge>
                <p class="text-theme-xs text-gray-500 dark:text-gray-400 mt-2">{{ $fi['label'] }}</p>
            </div>
        @endforeach
    </div>
    <p class="text-theme-xs text-gray-400 mt-3">Agent audits in the last 24 hours: <strong class="text-gray-600">{{ $fleet['recent_audits'] }}</strong></p>
</x-card>

<x-modal id="scan-all-agents-modal" title="Scan all agents" maxWidth="md">
    <div class="space-y-4">
        <p class="text-theme-sm text-gray-600 dark:text-gray-300">
            Start a scan for all active agents so the current fleet status is refreshed across the asset inventory.
        </p>

        <form method="POST" action="{{ route('agent.scanAll') }}" class="space-y-4" data-modal-action data-close-modal="scan-all-agents-modal">
            @csrf

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" @click="$dispatch('close-modal', 'scan-all-agents-modal')" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</button>
                <x-button type="submit" variant="success">Scan now</x-button>
            </div>
        </form>
    </div>
</x-modal>

@if($recentTransfers->count() > 0)
    <x-card class="mt-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Recent Transfers</h3>
            <a href="{{ route('transfers.index') }}" class="text-theme-sm text-brand-500 hover:text-brand-600">View all</a>
        </div>

        <x-table :headers="['Transfer #', 'Date', 'From', 'To', 'Status']">
            @foreach($recentTransfers as $transfer)
                <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-white/5 transition-colors">
                    <td class="py-3 px-4 text-theme-sm font-medium text-gray-700 dark:text-gray-300">{{ $transfer->transfer_number }}</td>
                    <td class="py-3 px-4 text-theme-sm text-gray-500">{{ $transfer->transfer_date?->format('Y-m-d') }}</td>
                    <td class="py-3 px-4 text-theme-sm text-gray-600">{{ $transfer->fromUser?->name }}</td>
                    <td class="py-3 px-4 text-theme-sm text-gray-600">{{ $transfer->toUser?->name }}</td>
                    <td class="py-3 px-4"><x-badge variant="success">{{ $transfer->status }}</x-badge></td>
                </tr>
            @endforeach
        </x-table>
    </x-card>
@endif

<x-card class="mt-6" title="Recent Activity">
    <div class="space-y-3">
        @forelse($recentActivity as $activity)
            <div class="flex items-start gap-3 p-3 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800/60 transition-colors">
                <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-gray-700/50 dark:text-gray-300">
                    <x-icon name="activity" class="h-4 w-4" />
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-theme-sm text-gray-700 dark:text-gray-300">
                        <span class="font-medium text-gray-800 dark:text-white/90">{{ $activity->actor?->name ?? 'System' }}</span>
                        <span class="text-gray-400 mx-1">&middot;</span>
                        <span>{{ $activity->action }}</span>
                        <span class="text-gray-400 mx-1">&middot;</span>
                        <span class="font-medium text-gray-600 dark:text-gray-400">{{ $activity->entity_identifier }}</span>
                    </p>
                    <p class="text-theme-xs text-gray-400 mt-0.5">{{ $activity->created_at->diffForHumans() }}</p>
                </div>
            </div>
        @empty
            <div class="flex flex-col items-center gap-2 py-5 text-center text-gray-400"><x-icon name="activity" class="h-7 w-7" /><p class="text-theme-sm">No activity yet.</p></div>
        @endforelse
    </div>
</x-card>
@endsection
