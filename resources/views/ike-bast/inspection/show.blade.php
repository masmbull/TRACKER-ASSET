@extends('layouts.app')

@section('title', $session->session_number)

@section('content')
<x-page-header :title="$session->session_number" :subtitle="$session->kind . ' inspection · ' . ucfirst($session->status)">
    <x-slot:actions>
        <a href="{{ route('ike-bast.inspection.print', $session) }}" target="_blank" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-500 px-4 py-2 text-theme-sm font-medium text-white transition-all duration-200 active:scale-[0.97] hover:bg-brand-600">
            <x-icon name="printer" class="h-4 w-4" />
            Print
        </a>
        @if($session->asset)
            <x-button variant="secondary" size="md" :href="route('assets.show', $session->asset)">Back to Asset</x-button>
        @endif
    </x-slot:actions>
</x-page-header>

<x-card class="mb-6" title="Inspection Detail">
    <dl class="grid grid-cols-1 gap-x-8 gap-y-3 text-theme-sm sm:grid-cols-2">
        <div class="flex justify-between gap-4 border-b border-gray-100 py-2 dark:border-gray-800">
            <dt class="text-gray-500">Asset</dt>
            <dd class="text-right font-medium text-gray-800 dark:text-gray-200">{{ $session->asset?->asset_id ?? '—' }} — {{ $session->asset?->brand }} {{ $session->asset?->model }}</dd>
        </div>
        <div class="flex justify-between gap-4 border-b border-gray-100 py-2 dark:border-gray-800">
            <dt class="text-gray-500">Serial Number</dt>
            <dd class="text-right font-medium text-gray-800 dark:text-gray-200">{{ $session->asset?->serial_number ?? '—' }}</dd>
        </div>
        <div class="flex justify-between gap-4 border-b border-gray-100 py-2 dark:border-gray-800">
            <dt class="text-gray-500">Inspection Date</dt>
            <dd class="text-right font-medium text-gray-800 dark:text-gray-200">{{ $session->inspection_date?->format('d M Y') ?? '—' }}</dd>
        </div>
        <div class="flex justify-between gap-4 border-b border-gray-100 py-2 dark:border-gray-800">
            <dt class="text-gray-500">Inspected By</dt>
            <dd class="text-right font-medium text-gray-800 dark:text-gray-200">{{ $session->creator?->name ?? '—' }}</dd>
        </div>
        <div class="flex justify-between gap-4 border-b border-gray-100 py-2 dark:border-gray-800">
            <dt class="text-gray-500">Linked Transfer</dt>
            <dd class="text-right font-medium text-gray-800 dark:text-gray-200">{{ $session->transfer?->transfer_number ?? '—' }}</dd>
        </div>
        <div class="flex justify-between gap-4 border-b border-gray-100 py-2 dark:border-gray-800">
            <dt class="text-gray-500">Status</dt>
            <dd class="text-right">
                <x-badge :variant="match($session->status) {
                    'approved' => 'success',
                    'rejected' => 'error',
                    'submitted' => 'brand',
                    default => 'gray',
                }" dot>{{ ucfirst($session->status) }}</x-badge>
            </dd>
        </div>
        @if($session->notes)
            <div class="sm:col-span-2 flex justify-between gap-4 py-2">
                <dt class="text-gray-500">Notes</dt>
                <dd class="text-right font-medium text-gray-800 dark:text-gray-200">{{ $session->notes }}</dd>
            </div>
        @endif
    </dl>
</x-card>

<x-card class="mb-6" padding="p-0" title="Inspection Line Items">
    <x-table :headers="['#', 'Item', 'Item Code', 'Serial', 'Qty Plan', 'Qty Recv', 'Condition', 'Test', 'Remarks']" emptyText="No line items recorded.">
        @foreach($session->lines as $line)
            <tr class="transition-colors hover:bg-gray-50 dark:hover:bg-white/5">
                <td class="py-3 px-4 text-theme-sm text-gray-500">{{ $line->seq }}</td>
                <td class="py-3 px-4 text-theme-sm font-medium text-gray-800 dark:text-gray-200">{{ $line->item_desc ?? '—' }}</td>
                <td class="py-3 px-4 text-theme-sm text-gray-600 dark:text-gray-400">{{ $line->item_code ?? '—' }}</td>
                <td class="py-3 px-4 text-theme-sm text-gray-600 dark:text-gray-400">{{ $line->serial_number ?? '—' }}</td>
                <td class="py-3 px-4 text-theme-sm text-gray-600 dark:text-gray-400">{{ $line->qty_planned ?? '—' }}</td>
                <td class="py-3 px-4 text-theme-sm {{ $line->qty_received < $line->qty_planned ? 'font-medium text-warning-600' : 'text-gray-600 dark:text-gray-400' }}">{{ $line->qty_received ?? '—' }}</td>
                <td class="py-3 px-4 text-theme-sm text-gray-600 dark:text-gray-400">{{ $line->physical_condition ?? '—' }}</td>
                <td class="py-3 px-4">
                    <x-badge :variant="match($line->test_result) {
                        'pass' => 'success',
                        'fail' => 'error',
                        default => 'gray',
                    }">{{ strtoupper($line->test_result ?? 'na') }}</x-badge>
                </td>
                <td class="py-3 px-4 text-theme-sm text-gray-500">{{ $line->remarks ?? '—' }}{{ $line->is_defective ? ' (defective)' : '' }}</td>
            </tr>
        @endforeach
    </x-table>
</x-card>

@can('manageAttachments', $session->asset)
    @if(! in_array($session->status, ['approved', 'rejected'], true))
        <x-card title="Approval">
            <p class="mb-3 text-theme-sm text-gray-500">Sign off this inspection, or send it back.</p>
            <div class="flex flex-wrap items-center gap-2">
                <form method="POST" action="{{ route('ike-bast.inspection.approve', $session) }}">
                    @csrf
                    <input type="hidden" name="status" value="approved">
                    <x-button type="submit" variant="primary">Approve</x-button>
                </form>
                <form method="POST" action="{{ route('ike-bast.inspection.approve', $session) }}">
                    @csrf
                    <input type="hidden" name="status" value="rejected">
                    <x-button type="submit" variant="danger">Reject</x-button>
                </form>
            </div>
        </x-card>
    @endif
@endcan
@endsection
