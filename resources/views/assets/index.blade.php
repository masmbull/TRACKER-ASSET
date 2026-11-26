@extends('layouts.app')

@section('title', 'Assets')
@section('header', 'All Assets')

@section('content')
{{-- Validation errors are rendered once by the layout, above the content. --}}
@if(session('success'))
    <div class="mb-5 rounded-lg border border-success-200 bg-success-50 px-4 py-3 dark:border-success-500/30 dark:bg-success-500/10">
        <p class="text-theme-sm text-success-700 dark:text-success-300">{{ session('success') }}</p>
    </div>
@endif

<div x-data="assetBrowser()" x-effect="applyFilters()">
<x-page-header title="Assets" :subtitle="$assets->total() . ' total'">
    <x-slot:actions>
        @can('export-assets')
            <x-button variant="success" size="sm" :href="route('assets.export', request()->query())">Export</x-button>
        @endcan
        @can('import-assets')
            <x-button variant="secondary" size="sm" :href="route('assets.import')">Import</x-button>
        @endcan
        @can('create', App\Models\Asset::class)
            <x-button variant="primary" size="sm" :href="route('assets.create')">+ Add Asset</x-button>
        @endcan
    </x-slot:actions>
</x-page-header>

<x-card class="mb-6" x-data="{ showFilters: false }">
    <form method="GET" action="{{ route('assets.index') }}">
        <div class="flex flex-col gap-3 sm:flex-row">
            <div class="flex-1 relative">
                <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2">
                    <x-icon name="search" class="h-5 w-5 shrink-0 text-gray-400" />
                </span>
                <input type="search" x-model="query" autocomplete="off"
                       placeholder="Search assets in this page..."
                       class="dark:bg-dark-900 w-full rounded-lg border border-gray-200 bg-transparent py-2.5 pl-12 pr-4 text-theme-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:ring-brand-500/10 focus:ring-3 focus:outline-none dark:border-gray-800 dark:text-white/90 dark:placeholder:text-white/30">
            </div>
            <div class="grid grid-cols-3 gap-2 sm:flex">
                <x-button type="button" variant="secondary" size="md" class="w-full sm:w-auto" @click="showFilters = !showFilters">Filters</x-button>
                <x-button type="button" size="md" class="w-full sm:w-auto" @click="resetFilters()">Reset</x-button>
            </div>
        </div>

        <div x-show="showFilters" x-collapse x-cloak class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <x-select x-model="status" label="Status">
                <option value="">All</option>
                @foreach($statuses as $status)
                    <option value="{{ $status->id }}">{{ $status->name }}</option>
                @endforeach
            </x-select>
            <x-select x-model="category" label="Category">
                <option value="">All</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </x-select>
            <x-select x-model="company" label="Company">
                <option value="">All</option>
                @foreach($companies as $company)
                    <option value="{{ $company->id }}">{{ $company->name }}</option>
                @endforeach
            </x-select>
            <x-select x-model="location" label="Location">
                <option value="">All</option>
                @foreach($locations as $location)
                    <option value="{{ $location->id }}">{{ $location->name }}</option>
                @endforeach
            </x-select>
            <x-select x-model="sort" label="Sort">
                @foreach(['updated_at' => 'Updated', 'asset_id' => 'Asset ID', 'serial_number' => 'Serial', 'hostname' => 'Hostname'] as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-select>
        </div>
        <div x-show="showFilters" x-collapse x-cloak class="mt-3">
            <label class="inline-flex items-center gap-2 text-theme-sm text-gray-600 dark:text-gray-400 cursor-pointer">
                <input type="checkbox" name="archived" value="include" {{ request('archived') === 'include' ? 'checked' : '' }}
                       onchange="this.form.requestSubmit()"
                       class="h-4 w-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500/10">
                Include archived assets
            </label>
        </div>
    </form>

    <p class="mt-3 text-theme-xs text-gray-400">
        <span x-text="visible"></span> of {{ $assets->count() }} on this page shown · search and filters run in your browser
    </p>
</x-card>

@if($assets->count() > 0)
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach($assets as $asset)
            @php
                $badgeMap = ['Active' => 'success', 'Available' => 'brand', 'Assigned' => 'warning', 'Repair' => 'orange', 'Retired' => 'gray', 'Disposed' => 'error'];
                $searchText = mb_strtolower(implode(' ', array_filter([
                    $asset->asset_id, $asset->serial_number, $asset->hostname,
                    $asset->brand, $asset->model, $asset->currentUser?->name,
                    $asset->location?->name, $asset->company?->name,
                    $asset->category?->name, $asset->status?->name,
                ])));
            @endphp
            <article class="asset-card group cursor-pointer rounded-2xl border border-gray-200 bg-white p-4 shadow-theme-xs transition hover:-translate-y-0.5 hover:border-brand-300 hover:shadow-theme-md dark:border-gray-800 dark:bg-gray-900"
                     role="button" tabindex="0"
                     data-search="{{ $searchText }}"
                     data-status="{{ $asset->status?->id }}"
                     data-category="{{ $asset->category?->id }}"
                     data-company="{{ $asset->company?->id }}"
                     data-location="{{ $asset->location?->id }}"
                     @click="$dispatch('open-modal', 'asset-{{ $asset->id }}')"
                     @keydown.enter.prevent="$dispatch('open-modal', 'asset-{{ $asset->id }}')">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate text-theme-sm font-bold text-brand-600 dark:text-brand-400">{{ $asset->asset_id }}</p>
                        <p class="mt-0.5 truncate text-theme-xs text-gray-500 dark:text-gray-400">{{ $asset->category?->name ?? '—' }}</p>
                    </div>
                    <x-badge :variant="$badgeMap[$asset->status?->name] ?? 'gray'">{{ $asset->status?->name ?? '—' }}</x-badge>
                </div>

                <p class="mt-3 truncate text-theme-sm font-semibold text-gray-800 dark:text-white">{{ trim(($asset->brand ?? '').' '.($asset->model ?? '')) ?: '—' }}</p>
                <dl class="mt-2 space-y-1 text-theme-xs">
                    <div class="flex justify-between gap-2"><dt class="text-gray-400">Serial</dt><dd class="truncate font-mono text-gray-600 dark:text-gray-300">{{ $asset->serial_number ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-gray-400">Hostname</dt><dd class="truncate font-mono text-gray-600 dark:text-gray-300">{{ $asset->hostname ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-gray-400">User</dt><dd class="truncate text-gray-600 dark:text-gray-300">{{ $asset->currentUser?->name ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-gray-400">Location</dt><dd class="truncate text-gray-600 dark:text-gray-300">{{ $asset->location?->name ?: '—' }}</dd></div>
                </dl>

                <div class="mt-4 flex items-center justify-between border-t border-gray-100 pt-3 dark:border-gray-800">
                    <span class="text-theme-xs font-medium text-brand-500 group-hover:text-brand-600">Open details →</span>
                    @if($asset->hasQr())
                        <x-badge variant="success">QR ready</x-badge>
                    @else
                        <x-badge variant="gray">No QR</x-badge>
                    @endif
                </div>
            </article>

            {{-- Everything that used to live in the table's Actions and QR cells is
                 now here, scoped to this one asset id. Opening the card never navigates. --}}
            <x-modal id="asset-{{ $asset->id }}" title="{{ $asset->asset_id }} · {{ trim(($asset->brand ?? '').' '.($asset->model ?? '')) }}" maxWidth="lg">
                <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-theme-sm sm:grid-cols-2">
                    <div class="flex justify-between gap-3 border-b border-gray-100 py-1.5 dark:border-gray-800"><dt class="text-gray-500 dark:text-gray-400">Category</dt><dd class="text-right font-medium text-gray-800 dark:text-white">{{ $asset->category?->name ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-3 border-b border-gray-100 py-1.5 dark:border-gray-800"><dt class="text-gray-500 dark:text-gray-400">Status</dt><dd class="text-right font-medium text-gray-800 dark:text-white">{{ $asset->status?->name ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-3 border-b border-gray-100 py-1.5 dark:border-gray-800"><dt class="text-gray-500 dark:text-gray-400">Serial</dt><dd class="text-right font-mono text-gray-800 dark:text-white">{{ $asset->serial_number ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-3 border-b border-gray-100 py-1.5 dark:border-gray-800"><dt class="text-gray-500 dark:text-gray-400">Hostname</dt><dd class="text-right font-mono text-gray-800 dark:text-white">{{ $asset->hostname ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-3 border-b border-gray-100 py-1.5 dark:border-gray-800"><dt class="text-gray-500 dark:text-gray-400">User</dt><dd class="text-right font-medium text-gray-800 dark:text-white">{{ $asset->currentUser?->name ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-3 border-b border-gray-100 py-1.5 dark:border-gray-800"><dt class="text-gray-500 dark:text-gray-400">Location</dt><dd class="text-right font-medium text-gray-800 dark:text-white">{{ $asset->location?->name ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-3 border-b border-gray-100 py-1.5 dark:border-gray-800"><dt class="text-gray-500 dark:text-gray-400">Company</dt><dd class="text-right font-medium text-gray-800 dark:text-white">{{ $asset->company?->name ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-3 border-b border-gray-100 py-1.5 dark:border-gray-800"><dt class="text-gray-500 dark:text-gray-400">Processor</dt><dd class="text-right font-medium text-gray-800 dark:text-white">{{ $asset->processor ?: '—' }}</dd></div>
                </dl>

                <div class="mt-5 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <h4 class="mb-2 text-theme-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">QR &amp; document</h4>
                    @can('update', $asset)
                        @if($asset->hasQr())
                            <div class="flex flex-wrap gap-2">
                                <x-button variant="secondary" size="sm" :href="route('assets.public.pdf', $asset->qr_token)">Asset PDF</x-button>
                                <x-button variant="secondary" size="sm" :href="route('assets.qr', $asset)">QR image</x-button>
                                <form method="POST" action="{{ route('assets.qr.generate', $asset) }}" onsubmit="return confirm('Re-create the QR for {{ $asset->asset_id }}? Any label already printed for it will stop working.');">
                                    @csrf
                                    <input type="hidden" name="regenerate" value="1">
                                    <x-button type="submit" variant="secondary" size="sm">Re-create QR</x-button>
                                </form>
                            </div>
                        @else
                            <form method="POST" action="{{ route('assets.qr.generate', $asset) }}">
                                @csrf
                                <x-button type="submit" variant="secondary" size="sm">+ Create QR</x-button>
                            </form>
                        @endif
                    @else
                        <p class="text-theme-xs text-gray-400">No permission to manage QR for this asset.</p>
                    @endcan
                </div>

                <div class="mt-5 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <x-button variant="primary" size="sm" :href="route('assets.show', $asset)">Full details</x-button>
                    @can('update', $asset)
                        <x-button variant="secondary" size="sm" :href="route('assets.edit', $asset)">Edit</x-button>
                    @endcan
                    @can('forceDelete', $asset)
                        <button type="button"
                                class="ml-auto text-theme-sm font-medium text-error-600 hover:text-error-700 dark:text-error-400"
                                title="Hapus aset secara permanen"
                                onclick="window.trackerDeleteAsset && window.trackerDeleteAsset('delete-asset-{{ $asset->id }}')">Delete</button>
                    @endcan
                </div>
            </x-modal>
        @endforeach
    </div>

    <div x-show="visible === 0" x-cloak class="mt-4 rounded-2xl border border-dashed border-gray-300 p-10 text-center text-theme-sm text-gray-400 dark:border-gray-700">
        No asset on this page matches the current search and filters.
    </div>

    @include('assets._delete-dialogs', ['assets' => $assets])

    @if($assets->hasPages())
        <div class="mt-5 border-t border-gray-100 dark:border-gray-800">
            {{ $assets->links('components.pagination') }}
        </div>
    @endif
@else
    <x-card class="text-center py-12">
        <p class="text-theme-sm text-gray-400">No assets found.</p>
    </x-card>
@endif
</div>

<script>
    function assetBrowser() {
        return {
            query: '',
            status: '',
            category: '',
            company: '',
            location: '',
            sort: 'updated_at',
            visible: 0,
            applyFilters() {
                const q = (this.query || '').trim().toLowerCase();
                let visible = 0;

                document.querySelectorAll('.asset-card').forEach((card) => {
                    const matchesText = !q || (card.dataset.search || '').includes(q);
                    const matches = (key, value) => !value || card.dataset[key] === value;
                    const show = matchesText
                        && matches('status', this.status)
                        && matches('category', this.category)
                        && matches('company', this.company)
                        && matches('location', this.location);

                    card.style.display = show ? '' : 'none';
                    if (show) visible++;
                });

                this.visible = visible;
            },
            resetFilters() {
                this.query = '';
                this.status = '';
                this.category = '';
                this.company = '';
                this.location = '';
                this.sort = 'updated_at';
                this.applyFilters();
            },
        };
    }
</script>
@endsection
