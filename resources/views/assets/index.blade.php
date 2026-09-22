@extends('layouts.app')

@section('title', 'Assets')

@section('content')
<div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-bold">Assets</h1>
    <div class="flex gap-2">
        @can('export-assets')
            <a href="{{ route('assets.export', request()->query()) }}" class="bg-green-600 text-white px-4 py-2 rounded">Export Excel</a>
        @endcan
        @can('import-assets')
            <a href="{{ route('assets.import') }}" class="bg-purple-600 text-white px-4 py-2 rounded">Import Excel</a>
        @endcan
        @can('create', App\Models\Asset::class)
            <a href="{{ route('assets.create') }}" class="bg-blue-600 text-white px-4 py-2 rounded">+ Add Asset</a>
        @endcan
    </div>
</div>

<form method="GET" action="{{ route('assets.index') }}" class="bg-white p-4 rounded shadow mb-4">
    <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-3">
        <div class="lg:col-span-2">
            <label class="block text-xs text-gray-500">Search</label>
            <input type="text" name="search" value="{{ request('search') }}"
                   placeholder="Asset ID, Serial, Hostname, User, Email, Dept, MAC, IP..."
                   class="w-full border rounded px-3 py-2">
        </div>
        <div>
            <label class="block text-xs text-gray-500">Status</label>
            <select name="status" class="w-full border rounded px-3 py-2">
                <option value="">All</option>
                @foreach($statuses as $status)
                    <option value="{{ $status->id }}" {{ (string) request('status') === (string) $status->id ? 'selected' : '' }}>{{ $status->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500">Category</label>
            <select name="category" class="w-full border rounded px-3 py-2">
                <option value="">All</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}" {{ (string) request('category') === (string) $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500">Company</label>
            <select name="company" class="w-full border rounded px-3 py-2">
                <option value="">All</option>
                @foreach($companies as $company)
                    <option value="{{ $company->id }}" {{ (string) request('company') === (string) $company->id ? 'selected' : '' }}>{{ $company->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500">Location</label>
            <select name="location" class="w-full border rounded px-3 py-2">
                <option value="">All</option>
                @foreach($locations as $location)
                    <option value="{{ $location->id }}" {{ (string) request('location') === (string) $location->id ? 'selected' : '' }}>{{ $location->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500">Sort by</label>
            <select name="sort" class="w-full border rounded px-3 py-2">
                @foreach(['updated_at' => 'Updated', 'asset_id' => 'Asset ID', 'serial_number' => 'Serial', 'hostname' => 'Hostname', 'purchase_year' => 'Purchase Year'] as $value => $label)
                    <option value="{{ $value }}" {{ request('sort', 'updated_at') === $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500">Direction</label>
            <select name="direction" class="w-full border rounded px-3 py-2">
                <option value="desc" {{ request('direction') === 'desc' ? 'selected' : '' }}>Descending</option>
                <option value="asc" {{ request('direction') === 'asc' ? 'selected' : '' }}>Ascending</option>
            </select>
        </div>
    </div>
    <div class="flex items-center gap-3">
        <button type="submit" class="bg-gray-600 text-white px-4 py-2 rounded">Apply</button>
        <a href="{{ route('assets.index') }}" class="bg-gray-300 text-gray-700 px-4 py-2 rounded">Reset</a>
        <label class="text-sm text-gray-600 flex items-center gap-1">
            <input type="checkbox" name="archived" value="include" {{ request('archived') === 'include' ? 'checked' : '' }}>
            Include archived
        </label>
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
            <th class="text-left py-3 px-4">Hostname</th>
            <th class="text-left py-3 px-4">Location</th>
            <th class="text-left py-3 px-4">User</th>
            <th class="text-left py-3 px-4">IT PIC</th>
            <th class="text-left py-3 px-4">Status</th>
            <th class="text-left py-3 px-4">Updated</th>
            <th class="text-left py-3 px-4">Actions</th>
        </tr>
    </thead>
    <tbody>
        @foreach($assets as $asset)
        <tr class="border-b hover:bg-gray-50">
            <td class="py-3 px-4">{{ $asset->asset_id }}</td>
            <td class="py-3 px-4">{{ $asset->category?->name }}</td>
            <td class="py-3 px-4">{{ $asset->brand }} {{ $asset->model }}</td>
            <td class="py-3 px-4">{{ $asset->serial_number }}</td>
            <td class="py-3 px-4">{{ $asset->hostname }}</td>
            <td class="py-3 px-4">{{ $asset->location?->name }}</td>
            <td class="py-3 px-4">{{ $asset->currentUser?->name }}</td>
            <td class="py-3 px-4">{{ $asset->adminItPic?->name }}</td>
            <td class="py-3 px-4">
                <span class="px-2 py-1 text-xs rounded bg-gray-200">{{ $asset->status?->name }}</span>
                @unless($asset->is_active)
                    <span class="px-2 py-1 text-xs rounded bg-red-100 text-red-700">Archived</span>
                @endunless
            </td>
            <td class="py-3 px-4 text-xs text-gray-500">{{ $asset->updated_at?->format('Y-m-d') }}</td>
            <td class="py-3 px-4 whitespace-nowrap">
                <a href="{{ route('assets.show', $asset) }}" class="text-blue-600">View</a>
                @can('update', $asset)
                    <a href="{{ route('assets.edit', $asset) }}" class="text-yellow-600 ml-2">Edit</a>
                @endcan
                @can('archive', $asset)
                    <form method="POST" action="{{ route('assets.destroy', $asset) }}" class="inline ml-2" onsubmit="return confirm('Archive this asset?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-red-600">Archive</button>
                    </form>
                @endcan
            </td>
        </tr>
        @endforeach
    </tbody>
</table>

{{ $assets->links() }}
@else
<p class="bg-white p-6 rounded shadow">No assets found.</p>
@endif
@endsection
