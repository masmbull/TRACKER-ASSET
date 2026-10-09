@extends('layouts.app')

@section('title', 'Retired & Disposed Assets')

@section('content')
<div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-bold">Retired & Disposed Assets</h1>
    <div class="flex gap-2">
        @can('export-assets')
            <a href="{{ route('assets.export', ['archived' => 'include'] + request()->only('search')) }}" class="bg-green-600 text-white px-4 py-2 rounded">Export Excel</a>
        @endcan
    </div>
</div>

<form method="GET" action="{{ route('assets.retired') }}" class="bg-white p-4 rounded shadow mb-4">
    <div class="flex items-center gap-3">
        <div class="flex-1">
            <input type="text" name="search" value="{{ request('search') }}"
                   placeholder="Search by Asset ID, Serial, Hostname, User..."
                   class="w-full border rounded px-3 py-2">
        </div>
        <button type="submit" class="bg-gray-600 text-white px-4 py-2 rounded">Search</button>
        <a href="{{ route('assets.retired') }}" class="bg-gray-300 text-gray-700 px-4 py-2 rounded">Reset</a>
    </div>
</form>

@if($assets->count() > 0)
<table class="min-w-full bg-white shadow rounded">
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
        <tr class="border-b hover:bg-gray-50">
            <td class="py-3 px-4">{{ $asset->asset_id }}</td>
            <td class="py-3 px-4">{{ $asset->category?->name }}</td>
            <td class="py-3 px-4">{{ $asset->brand }} {{ $asset->model }}</td>
            <td class="py-3 px-4">{{ $asset->serial_number }}</td>
            <td class="py-3 px-4">{{ $asset->location?->name }}</td>
            <td class="py-3 px-4">{{ $asset->currentUser?->name }}</td>
            <td class="py-3 px-4">
                @if($asset->status?->name === 'Retired')
                    <span class="px-2 py-1 text-xs rounded bg-gray-200 text-gray-700">Retired</span>
                @else
                    <span class="px-2 py-1 text-xs rounded bg-red-100 text-red-700">Disposed</span>
                @endif
            </td>
            <td class="py-3 px-4 text-xs text-gray-500">{{ $asset->retire_date?->format('Y-m-d') }}</td>
            <td class="py-3 px-4">
                <a href="{{ route('assets.show', $asset) }}" class="text-red-600 hover:text-red-700">View</a>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>

{{ $assets->links() }}
@else
<p class="bg-white p-6 rounded shadow">No retired or disposed assets found.</p>
@endif
@endsection
