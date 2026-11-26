@extends('layouts.app')

@section('title', 'Transfer History')

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <h1 class="text-2xl font-bold">Transfer History</h1>
</div>

<form method="GET" action="{{ route('transfers.index') }}" class="bg-white p-4 rounded-xl shadow mb-4 dark:bg-[#1d2939]">
    <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-3">
        <div class="md:col-span-2">
            <label class="block text-xs text-gray-500 dark:text-gray-400">Search</label>
            <input type="text" name="search" value="{{ request('search') }}" data-live-filter
                   placeholder="Transfer #, Asset ID, Hostname, User..."
                   class="w-full border rounded-lg px-3 py-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:placeholder-gray-400">
        </div>
        <div>
            <label class="block text-xs text-gray-500 dark:text-gray-400">Status</label>
            <select name="status" class="w-full border rounded-lg px-3 py-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                <option value="">All</option>
                <option value="approved" {{ request('status') === 'approved' ? 'selected' : '' }}>Approved</option>
                <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                <option value="rejected" {{ request('status') === 'rejected' ? 'selected' : '' }}>Rejected</option>
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 dark:text-gray-400">Date Range</label>
            <div class="flex gap-1">
                <input type="date" name="from" value="{{ request('from') }}" aria-label="From date" class="min-w-0 flex-1 border rounded-lg px-2 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                <input type="date" name="to" value="{{ request('to') }}" aria-label="To date" class="min-w-0 flex-1 border rounded-lg px-2 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white">
            </div>
        </div>
    </div>
    <div class="flex items-center gap-3">
        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-gray-600 px-4 py-2 text-theme-sm font-medium text-white transition-all duration-200 active:scale-[0.97] hover:bg-gray-700 dark:bg-gray-600">Apply</button>
        <a href="{{ route('transfers.index') }}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 transition-all duration-200 active:scale-[0.97] hover:bg-gray-50 dark:border-gray-600 dark:bg-[#1d2939] dark:text-gray-100 dark:hover:bg-gray-800">Reset</a>
    </div>
</form>

@if($transfers->count() > 0)
<div class="w-full overflow-x-auto" data-live-filter-scope>
<table class="min-w-[900px] w-full bg-white shadow rounded dark:bg-[#1d2939]">
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
        @php($fatDocument = $transfer->documents->firstWhere('document_type', 'FAT'))
        <tr class="border-b hover:bg-gray-50 dark:hover:bg-gray-800" data-live-filter-row>
            <td class="py-3 px-4">{{ $transfer->transfer_number }}</td>
            <td class="py-3 px-4">{{ $transfer->transfer_date?->format('Y-m-d') }}</td>
            <td class="py-3 px-4">
                <a href="{{ route('assets.show', $transfer->asset_id) }}" class="text-red-600 hover:text-red-700 dark:text-red-400">
                    {{ $transfer->asset?->asset_id }}
                </a>
                <span class="text-xs text-gray-500 block dark:text-gray-400">{{ $transfer->asset?->brand }} {{ $transfer->asset?->model }}</span>
            </td>
            <td class="py-3 px-4">
                {{ $transfer->fromUser?->name }}
                <span class="text-xs text-gray-500 block dark:text-gray-400">{{ $transfer->fromLocation?->name }}</span>
            </td>
            <td class="py-3 px-4">
                {{ $transfer->toUser?->name }}
                <span class="text-xs text-gray-500 block dark:text-gray-400">{{ $transfer->toLocation?->name }}</span>
            </td>
            <td class="py-3 px-4">{{ $transfer->submitter?->name }}</td>
            <td class="py-3 px-4">
                @if($transfer->status === 'approved')
                    <span class="px-2 py-1 text-xs rounded bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300">Approved</span>
                @elseif($transfer->status === 'pending')
                    <span class="px-2 py-1 text-xs rounded bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300">Pending</span>
                @elseif($transfer->status === 'rejected')
                    <span class="px-2 py-1 text-xs rounded bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300">Rejected</span>
                @else
                    <span class="px-2 py-1 text-xs rounded bg-gray-200 dark:bg-gray-700">{{ $transfer->status }}</span>
                @endif
            </td>
            <td class="py-3 px-4">
                <div class="flex items-center gap-3">
                    <a href="{{ route('assets.show', $transfer->asset_id) }}" class="text-red-600 text-sm hover:text-red-700 dark:text-red-400">View Asset</a>
                    @if($fatDocument)
                        <a href="{{ route('documents.download', $fatDocument) }}" class="inline-flex items-center gap-1 text-sm text-brand-500 hover:text-brand-600">
                            <x-icon name="download" class="h-4 w-4" /> FAT PDF
                        </a>
                    @endif
                </div>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>

{{ $transfers->links() }}
@else
<p class="bg-white p-6 rounded-xl shadow dark:bg-[#1d2939]">No transfers found.</p>
@endif
@endsection
