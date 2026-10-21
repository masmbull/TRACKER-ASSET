@extends('layouts.app')

@section('title', 'Retired & Disposed Assets')

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <h1 class="text-2xl font-bold">Retired & Disposed Assets</h1>
    <div class="flex flex-wrap items-center gap-2">
        @can('export-assets')
            <a href="{{ route('assets.export', ['archived' => 'include'] + request()->only('search')) }}" class="inline-flex items-center justify-center rounded-lg bg-success-500 px-4 py-2 text-theme-sm font-medium text-white shadow-theme-xs transition-all duration-200 active:scale-[0.97] hover:bg-success-600">Export Excel</a>
        @endcan
    </div>
</div>

<form method="GET" action="{{ route('assets.retired') }}" class="bg-white p-4 rounded-xl shadow mb-4 dark:bg-[#1d2939]">
    <div class="flex items-center gap-3">
        <div class="flex-1">
            <input type="text" name="search" value="{{ request('search') }}"
                   placeholder="Search by Asset ID, Serial, Hostname, User..."
                   class="w-full border rounded-lg px-3 py-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:placeholder-gray-400">
        </div>
        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-gray-600 px-4 py-2 text-theme-sm font-medium text-white transition-all duration-200 active:scale-[0.97] hover:bg-gray-700 dark:bg-gray-600">Search</button>
        <a href="{{ route('assets.retired') }}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 transition-all duration-200 active:scale-[0.97] hover:bg-gray-50 dark:border-gray-600 dark:bg-[#1d2939] dark:text-gray-100 dark:hover:bg-gray-800">Reset</a>
    </div>
</form>

@if($assets->count() > 0)
<table class="min-w-full bg-white shadow rounded dark:bg-[#1d2939]">
    <thead>
        <tr class="border-b">
            <th class="text-left py-3 px-4">Asset ID</th>
            <th class="text-left py-3 px-4">Category</th>
            <th class="text-left py-3 px-4">Brand/Model</th>
            <th class="text-left py-3 px-4">Serial</th>
            <th class="text-left py-3 px-4">Location</th>
            <th class="text-left py-3 px-4">Last User</th>
            <th class="text-left py-3 px-4">Status</th>
            <th class="text-left py-3 px-4">Retired</th>
            <th class="text-left py-3 px-4">Action</th>
        </tr>
    </thead>
    <tbody>
        @foreach($assets as $asset)
        <tr class="border-b hover:bg-gray-50 dark:hover:bg-gray-800">
            <td class="py-3 px-4">{{ $asset->asset_id }}</td>
            <td class="py-3 px-4">{{ $asset->category?->name }}</td>
            <td class="py-3 px-4">{{ $asset->brand }} {{ $asset->model }}</td>
            <td class="py-3 px-4">{{ $asset->serial_number }}</td>
            <td class="py-3 px-4">{{ $asset->location?->name }}</td>
            <td class="py-3 px-4">{{ $asset->currentUser?->name }}</td>
            <td class="py-3 px-4">
                @if($asset->status?->name === 'Retired')
                    <span class="px-2 py-1 text-xs rounded bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300">Retired</span>
                @else
                    <span class="px-2 py-1 text-xs rounded bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300">Disposed</span>
                @endif
            </td>
            <td class="py-3 px-4 text-xs text-gray-500 dark:text-gray-400">{{ $asset->retire_date?->format('Y-m-d') }}</td>
            <td class="py-3 px-4">
                <a href="{{ route('assets.show', $asset) }}" class="text-red-600 hover:text-red-700 dark:text-red-400">View</a>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>

{{ $assets->links() }}
@else
<p class="bg-white p-6 rounded-xl shadow dark:bg-[#1d2939]">No retired or disposed assets found.</p>
@endif
@endsection
