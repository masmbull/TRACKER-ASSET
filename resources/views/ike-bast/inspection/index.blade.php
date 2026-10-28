@extends('layouts.app')

@section('title', 'Inspections')

@section('content')
<x-page-header title="Inspections" subtitle="IKE / BAST receiving inspections: what arrived, its condition, and who signed for it." />

<x-card class="mb-6">
    <form method="GET" action="{{ route('ike-bast.inspection.index') }}" class="grid grid-cols-1 gap-3 md:grid-cols-4">
        <div>
            <label class="mb-1.5 block text-theme-xs text-gray-500">Search</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Session no, asset ID, hostname, serial"
                   class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
        </div>
        <div>
            <label class="mb-1.5 block text-theme-xs text-gray-500">Status</label>
            <select name="status" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                <option value="">All</option>
                @foreach(['draft', 'submitted', 'approved', 'rejected'] as $status)
                    <option value="{{ $status }}" {{ request('status') === $status ? 'selected' : '' }}>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1.5 block text-theme-xs text-gray-500">Kind</label>
            <select name="kind" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                <option value="">All</option>
                @foreach(['IKE_BAST', 'BAST', 'FAT', 'CUSTOM'] as $kind)
                    <option value="{{ $kind }}" {{ request('kind') === $kind ? 'selected' : '' }}>{{ $kind }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end gap-2">
            <x-button type="submit" variant="primary" size="sm">Filter</x-button>
            <a href="{{ route('ike-bast.inspection.index') }}" class="inline-flex h-[38px] items-center justify-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 text-theme-sm font-medium text-gray-600 shadow-sm transition-colors hover:border-brand-200 hover:bg-brand-50 hover:text-brand-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-brand-500/40 dark:hover:bg-brand-500/10 dark:hover:text-brand-300"><x-icon name="refresh" class="h-4 w-4" /> Reset</a>
        </div>
    </form>
</x-card>

<x-card padding="p-0">
    <x-table :headers="['Session', 'Kind', 'Asset', 'Lines', 'Date', 'Inspected By', 'Status']" emptyText="No inspection sessions yet. Start one from an asset's detail page.">
        @foreach($sessions as $session)
            <tr class="transition-colors hover:bg-gray-50 dark:hover:bg-white/5">
                <td class="py-3 px-4">
                    <a href="{{ route('ike-bast.inspection.show', $session) }}" class="text-theme-sm font-medium text-brand-500 hover:text-brand-600">{{ $session->session_number }}</a>
                </td>
                <td class="py-3 px-4"><x-badge variant="gray">{{ $session->kind }}</x-badge></td>
                <td class="py-3 px-4 text-theme-sm text-gray-700 dark:text-gray-300">{{ $session->asset?->asset_id ?? '—' }}</td>
                <td class="py-3 px-4 text-theme-sm text-gray-600 dark:text-gray-400">{{ $session->lines_count }}</td>
                <td class="py-3 px-4 text-theme-sm text-gray-500">{{ $session->inspection_date?->format('d M Y') ?? '—' }}</td>
                <td class="py-3 px-4 text-theme-sm text-gray-600 dark:text-gray-400">{{ $session->creator?->name ?? '—' }}</td>
                <td class="py-3 px-4">
                    <x-badge :variant="match($session->status) {
                        'approved' => 'success',
                        'rejected' => 'error',
                        'submitted' => 'brand',
                        default => 'gray',
                    }" dot>{{ ucfirst($session->status) }}</x-badge>
                </td>
            </tr>
        @endforeach
    </x-table>

    <x-pagination-compact :paginator="$sessions" />
</x-card>
@endsection
