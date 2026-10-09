@extends('layouts.app')

@section('title', 'IKE_BAST Inspections')

@section('content')
<h1 class="text-2xl font-bold mb-6">IKE_BAST Inspections</h1>

<form method="GET" action="{{ route('ike-bast.inspection.index') }}" class="bg-white p-4 rounded shadow mb-6 flex flex-wrap gap-3 items-end">
    <div>
        <label class="block text-xs text-gray-500">Search</label>
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Session no, asset ID, serial..." class="border rounded px-3 py-2">
    </div>
    <div>
        <label class="block text-xs text-gray-500">Status</label>
        <select name="status" class="border rounded px-3 py-2">
            <option value="">All</option>
            @foreach(['draft', 'submitted', 'approved', 'rejected'] as $status)
                <option value="{{ $status }}" {{ request('status') === $status ? 'selected' : '' }}>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500">Kind</label>
        <select name="kind" class="border rounded px-3 py-2">
            <option value="">All</option>
            @foreach(['IKE_BAST', 'BAST', 'FAT', 'CUSTOM'] as $kind)
                <option value="{{ $kind }}" {{ request('kind') === $kind ? 'selected' : '' }}>{{ $kind }}</option>
            @endforeach
        </select>
    </div>
    <button type="submit" class="bg-gray-600 text-white px-4 py-2 rounded">Apply</button>
    <p class="text-xs text-gray-500 self-center">Start a new inspection from the asset detail page.</p>
</form>

@if($sessions->count() > 0)
<div class="overflow-x-auto">
<table class="min-w-full bg-white shadow rounded text-sm">
    <thead>
        <tr class="border-b">
            <th class="text-left py-3 px-4">Session No</th>
            <th class="text-left py-3 px-4">Kind</th>
            <th class="text-left py-3 px-4">Asset</th>
            <th class="text-left py-3 px-4">Lines</th>
            <th class="text-left py-3 px-4">Date</th>
            <th class="text-left py-3 px-4">Inspected By</th>
            <th class="text-left py-3 px-4">Status</th>
        </tr>
    </thead>
    <tbody>
        @foreach($sessions as $session)
        <tr class="border-b hover:bg-gray-50">
            <td class="py-3 px-4"><a href="{{ route('ike-bast.inspection.show', $session) }}" class="text-red-600 hover:text-red-700">{{ $session->session_number }}</a></td>
            <td class="py-3 px-4">{{ $session->kind }}</td>
            <td class="py-3 px-4">{{ $session->asset?->asset_id }}</td>
            <td class="py-3 px-4">{{ $session->lines_count }}</td>
            <td class="py-3 px-4">{{ $session->inspection_date?->format('Y-m-d') }}</td>
            <td class="py-3 px-4">{{ $session->creator?->name }}</td>
            <td class="py-3 px-4"><span class="px-2 py-1 text-xs rounded bg-gray-200">{{ $session->status }}</span></td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>

{{ $sessions->links() }}
@else
<p class="bg-white p-6 rounded shadow">No inspection sessions found.</p>
@endif
@endsection
