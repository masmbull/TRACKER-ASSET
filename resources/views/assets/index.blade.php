@extends('layouts.app')

@section('title', 'Assets')
@section('header', 'All Assets')

@section('content')
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

<x-card class="mb-6" x-data="{ showFilters: {{ request()->hasAny(['status','category','company','location']) ? 'true' : 'false' }} }">
    <form method="GET" action="{{ route('assets.index') }}">
        <div class="flex flex-col gap-3 sm:flex-row">
            <div class="flex-1 relative">
                <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2">
                    <svg class="fill-gray-400" width="20" height="20" viewBox="0 0 20 20" fill="none"><path fill-rule="evenodd" clip-rule="evenodd" d="M3.04175 9.37363C3.04175 5.87693 5.87711 3.04199 9.37508 3.04199C12.8731 3.04199 15.7084 5.87693 15.7084 9.37363C15.7084 12.8703 12.8731 15.7053 9.37508 15.7053C5.87711 15.7053 3.04175 12.8703 3.04175 9.37363ZM9.37508 1.54199C5.04902 1.54199 1.54175 5.04817 1.54175 9.37363C1.54175 13.6991 5.04902 17.2053 9.37508 17.2053C11.2674 17.2053 13.003 16.5344 14.357 15.4176L17.177 18.238C17.4699 18.5309 17.9448 18.5309 18.2377 18.238C18.5306 17.9451 18.5306 17.4703 18.2377 17.1774L15.418 14.3573C16.5365 13.0033 17.2084 11.2669 17.2084 9.37363C17.2084 5.04817 13.7011 1.54199 9.37508 1.54199Z" fill=""/></svg>
                </span>
                <input type="text" name="search" value="{{ request('search') }}"
                       placeholder="Search or type command..."
                       class="dark:bg-dark-900 w-full rounded-lg border border-gray-200 bg-transparent py-2.5 pl-12 pr-4 text-theme-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:ring-brand-500/10 focus:ring-3 focus:outline-none dark:border-gray-800 dark:text-white/90 dark:placeholder:text-white/30">
            </div>
            <div class="grid grid-cols-3 gap-2 sm:flex">
                <x-button type="submit" size="md" class="w-full sm:w-auto">Search</x-button>
                <x-button type="button" variant="secondary" size="md" class="w-full sm:w-auto" @click="showFilters = !showFilters">Filters</x-button>
                <a href="{{ route('assets.index') }}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-4 py-2.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">Reset</a>
            </div>
        </div>

        <div x-show="showFilters" x-collapse x-cloak class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <x-select name="status" label="Status">
                <option value="">All</option>
                @foreach($statuses as $status)
                    <option value="{{ $status->id }}" {{ (string) request('status') === (string) $status->id ? 'selected' : '' }}>{{ $status->name }}</option>
                @endforeach
            </x-select>
            <x-select name="category" label="Category">
                <option value="">All</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}" {{ (string) request('category') === (string) $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                @endforeach
            </x-select>
            <x-select name="company" label="Company">
                <option value="">All</option>
                @foreach($companies as $company)
                    <option value="{{ $company->id }}" {{ (string) request('company') === (string) $company->id ? 'selected' : '' }}>{{ $company->name }}</option>
                @endforeach
            </x-select>
            <x-select name="location" label="Location">
                <option value="">All</option>
                @foreach($locations as $location)
                    <option value="{{ $location->id }}" {{ (string) request('location') === (string) $location->id ? 'selected' : '' }}>{{ $location->name }}</option>
                @endforeach
            </x-select>
            <x-select name="sort" label="Sort">
                @foreach(['updated_at' => 'Updated', 'asset_id' => 'Asset ID', 'serial_number' => 'Serial', 'hostname' => 'Hostname'] as $value => $label)
                    <option value="{{ $value }}" {{ request('sort', 'updated_at') === $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </x-select>
        </div>
        <div x-show="showFilters" x-collapse x-cloak class="mt-3">
            <label class="inline-flex items-center gap-2 text-theme-sm text-gray-600 dark:text-gray-400 cursor-pointer">
                <input type="checkbox" name="archived" value="include" {{ request('archived') === 'include' ? 'checked' : '' }}
                       class="h-4 w-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500/10">
                Include archived assets
            </label>
        </div>
    </form>
</x-card>

@if($assets->count() > 0)
    <x-card>
        <x-table :headers="['Asset ID', 'Category', 'Brand/Model', 'Serial', 'Hostname', 'Location', 'User', 'Status', 'Actions']">
            @foreach($assets as $asset)
                <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-white/5 transition-colors">
                    <td class="py-3 px-4"><a href="{{ route('assets.show', $asset) }}" class="text-theme-sm font-semibold text-brand-500 hover:text-brand-600">{{ $asset->asset_id }}</a></td>
                    <td class="py-3 px-4 text-theme-sm text-gray-600 dark:text-gray-400">{{ $asset->category?->name }}</td>
                    <td class="py-3 px-4 text-theme-sm text-gray-700 dark:text-gray-300">{{ $asset->brand }} {{ $asset->model }}</td>
                    <td class="py-3 px-4 text-theme-xs text-gray-500 font-mono">{{ $asset->serial_number }}</td>
                    <td class="py-3 px-4 text-theme-xs text-gray-500 font-mono">{{ $asset->hostname }}</td>
                    <td class="py-3 px-4 text-theme-sm text-gray-600">{{ $asset->location?->name }}</td>
                    <td class="py-3 px-4 text-theme-sm text-gray-600">{{ $asset->currentUser?->name }}</td>
                    <td class="py-3 px-4">
                        @php
                            $badgeMap = ['Active' => 'success', 'Available' => 'brand', 'Assigned' => 'warning', 'Repair' => 'orange', 'Retired' => 'gray', 'Disposed' => 'error'];
                        @endphp
                        <x-badge :variant="$badgeMap[$asset->status?->name] ?? 'gray'">{{ $asset->status?->name }}</x-badge>
                    </td>
                    <td class="py-3 px-4">
                        <div class="flex items-center gap-2">
                            <a href="{{ route('assets.show', $asset) }}" class="text-theme-sm font-medium text-brand-500 hover:text-brand-600">View</a>
                            @can('update', $asset)
                                <a href="{{ route('assets.edit', $asset) }}" class="text-theme-sm text-gray-400 hover:text-gray-700 dark:hover:text-gray-300">Edit</a>
                            @endcan
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-table>

        @if($assets->hasPages())
            <div class="border-t border-gray-100 dark:border-gray-800">
                {{ $assets->links('components.pagination') }}
            </div>
        @endif
    </x-card>
@else
    <x-card class="text-center py-12">
        <p class="text-theme-sm text-gray-400">No assets found.</p>
    </x-card>
@endif
@endsection
