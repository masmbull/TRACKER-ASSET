@extends('layouts.app')

@section('title', 'Transfer History')

@section('content')
<div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-bold">Transfer History</h1>
</div>

<form method="GET" action="{{ route('transfers.index') }}" class="bg-white p-4 rounded shadow mb-4">
    <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-3">
        <div class="md:col-span-2">
            <label class="block text-xs text-gray-500">Search</label>
            <input type="text" name="search" value="{{ request('search') }}"
                   placeholder="Transfer #, Asset ID, Hostname, User..."
                   class="w-full border rounded px-3 py-2">
        </div>
        <div>
            <label class="block text-xs text-gray-500">Status</label>
            <select name="status" class="w-full border rounded px-3 py-2">
                <option value="">All</option>
                <option value="approved" {{ request('status') === 'approved' ? 'selected' : '' }}>Approved</option>
                <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                <option value="rejected" {{ request('status') === 'rejected' ? 'selected' : '' }}>Rejected</option>
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500">Date Range</label>
            <div class="flex gap-1">
                <input type="date" name="from" value="{{ request('from') }}" class="w-1/2 border rounded px-2 py-2 text-sm">
                <input type="date" name="to" value="{{ request('to') }}" class="w-1/2 border rounded px-2 py-2 text-sm">
            </div>
        </div>
    </div>
    <div class="flex items-center gap-3">
        <button type="submit" class="bg-gray-600 text-white px-4 py-2 rounded">Apply</button>
        <a href="{{ route('transfers.index') }}" class="bg-gray-300 text-gray-700 px-4 py-2 rounded">Reset</a>
    </div>
</form>

@if($transfers->count() > 0)
<div class="w-full overflow-x-auto">
<table class="min-w-[900px] w-full bg-white shadow rounded">
    <thead>
        <tr class="border-b">
            <th class="text-left py-3 px-4">Transfer #</th>
            <th class="text-left py-3 px-4">Date</th>
            <th class="text-left py-3 px-4">Asset</th>
            <th class="text-left py-3 px-4">From</th>
            <th class="text-left py-3 px-4">To</th>
            <th class="text-left py-3 px-4">Submitted By</th>
            <th class="text-left py-3 px-4">Status</th>
            <th class="text-left py-3 px-4">Action</th>
        </tr>
    </thead>
    <tbody>
        @foreach($transfers as $transfer)
        <tr class="border-b hover:bg-gray-50">
            <td class="py-3 px-4">{{ $transfer->transfer_number }}</td>
            <td class="py-3 px-4">{{ $transfer->transfer_date?->format('Y-m-d') }}</td>
            <td class="py-3 px-4">
                <a href="{{ route('assets.show', $transfer->asset_id) }}" class="text-red-600 hover:text-red-700">
                    {{ $transfer->asset?->asset_id }}
                </a>
                <span class="text-xs text-gray-500 block">{{ $transfer->asset?->brand }} {{ $transfer->asset?->model }}</span>
            </td>
            <td class="py-3 px-4">
                {{ $transfer->fromUser?->name }}
                <span class="text-xs text-gray-500 block">{{ $transfer->fromLocation?->name }}</span>
            </td>
            <td class="py-3 px-4">
                {{ $transfer->toUser?->name }}
                <span class="text-xs text-gray-500 block">{{ $transfer->toLocation?->name }}</span>
            </td>
            <td class="py-3 px-4">{{ $transfer->submitter?->name }}</td>
            <td class="py-3 px-4">
                @if($transfer->status === 'approved')
                    <span class="px-2 py-1 text-xs rounded bg-green-100 text-green-700">Approved</span>
                @elseif($transfer->status === 'pending')
                    <span class="px-2 py-1 text-xs rounded bg-gray-100 text-gray-700">Pending</span>
                @elseif($transfer->status === 'rejected')
                    <span class="px-2 py-1 text-xs rounded bg-red-100 text-red-700">Rejected</span>
                @else
                    <span class="px-2 py-1 text-xs rounded bg-gray-200">{{ $transfer->status }}</span>
                @endif
            </td>
            <td class="py-3 px-4">
                <a href="{{ route('assets.show', $transfer->asset_id) }}" class="text-red-600 text-sm hover:text-red-700">View Asset</a>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>

{{ $transfers->links() }}
@else
<p class="bg-white p-6 rounded shadow">No transfers found.</p>
@endif
@endsection
