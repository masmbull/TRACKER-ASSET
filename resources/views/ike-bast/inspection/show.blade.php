@extends('layouts.app')

@section('title', 'IKE_BAST ' . $session->session_number)

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
        <h1 class="text-2xl font-bold">{{ $session->session_number }}</h1>
        <p class="text-sm text-gray-500">
            {{ $session->kind }} inspection — status
            <span class="px-2 py-1 text-xs rounded bg-gray-200">{{ $session->status }}</span>
        </p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('ike-bast.inspection.print', $session) }}" target="_blank" class="inline-flex items-center justify-center rounded-lg bg-brand-500 px-4 py-2 text-theme-sm font-medium text-white shadow-theme-xs transition-all duration-200 active:scale-[0.97] hover:bg-brand-600">Print</a>
        @if($session->asset)
            <a href="{{ route('assets.show', $session->asset) }}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 transition-all duration-200 active:scale-[0.97] hover:bg-gray-50">Back to Asset</a>
        @endif
    </div>
</div>

<div class="bg-white p-6 rounded-xl shadow mb-6">
    <dl class="grid grid-cols-2 gap-2 text-sm">
        <dt class="font-medium">Asset</dt><dd>{{ $session->asset?->asset_id }} — {{ $session->asset?->brand }} {{ $session->asset?->model }}</dd>
        <dt class="font-medium">Serial Number</dt><dd>{{ $session->asset?->serial_number }}</dd>
        <dt class="font-medium">Inspection Date</dt><dd>{{ $session->inspection_date?->format('Y-m-d') ?? '-' }}</dd>
        <dt class="font-medium">Inspected By</dt><dd>{{ $session->creator?->name }}</dd>
        <dt class="font-medium">Linked Transfer</dt><dd>{{ $session->transfer?->transfer_number ?? '-' }}</dd>
        <dt class="font-medium">Notes</dt><dd>{{ $session->notes ?? '-' }}</dd>
    </dl>
</div>

<div class="bg-white p-6 rounded-xl shadow mb-6 overflow-x-auto">
    <h2 class="text-lg font-semibold mb-4">Inspection Line Items</h2>
    <table class="min-w-full text-sm">
        <thead>
            <tr class="border-b bg-gray-50">
                <th class="text-left py-2 px-2">#</th>
                <th class="text-left py-2 px-2">Item Description</th>
                <th class="text-left py-2 px-2">Item Code</th>
                <th class="text-left py-2 px-2">Barcode</th>
                <th class="text-left py-2 px-2">Serial Number</th>
                <th class="text-left py-2 px-2">Unit</th>
                <th class="text-left py-2 px-2">Qty Planned</th>
                <th class="text-left py-2 px-2">Qty Received</th>
                <th class="text-left py-2 px-2">Condition</th>
                <th class="text-left py-2 px-2">Test</th>
                <th class="text-left py-2 px-2">Defective</th>
                <th class="text-left py-2 px-2">Remarks</th>
            </tr>
        </thead>
        <tbody>
            @forelse($session->lines as $line)
                <tr class="border-b">
                    <td class="py-2 px-2">{{ $line->seq }}</td>
                    <td class="py-2 px-2">{{ $line->item_desc ?? '-' }}</td>
                    <td class="py-2 px-2">{{ $line->item_code ?? '-' }}</td>
                    <td class="py-2 px-2">{{ $line->barcode ?? '-' }}</td>
                    <td class="py-2 px-2">{{ $line->serial_number ?? '-' }}</td>
                    <td class="py-2 px-2">{{ $line->unit ?? '-' }}</td>
                    <td class="py-2 px-2">{{ $line->qty_planned ?? '-' }}</td>
                    <td class="py-2 px-2">{{ $line->qty_received ?? '-' }}</td>
                    <td class="py-2 px-2">{{ $line->physical_condition ?? '-' }}</td>
                    <td class="py-2 px-2">{{ strtoupper($line->test_result) }}</td>
                    <td class="py-2 px-2">{{ $line->is_defective ? 'YES' : 'NO' }}</td>
                    <td class="py-2 px-2">{{ $line->remarks ?? '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="12" class="py-2 text-gray-500">No lines.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@can('manageAttachments', $session->asset)
<div class="bg-white p-6 rounded-xl shadow">
    <h2 class="text-lg font-semibold mb-3">Approval</h2>
    <div class="flex flex-wrap items-center gap-2">
        <form method="POST" action="{{ route('ike-bast.inspection.approve', $session) }}">
            @csrf
            <input type="hidden" name="status" value="approved">
            <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-success-500 px-4 py-2 text-theme-sm font-medium text-white shadow-theme-xs transition-all duration-200 active:scale-[0.97] hover:bg-success-600">Approve</button>
        </form>
        <form method="POST" action="{{ route('ike-bast.inspection.approve', $session) }}">
            @csrf
            <input type="hidden" name="status" value="rejected">
            <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-error-500 px-4 py-2 text-theme-sm font-medium text-white shadow-theme-xs transition-all duration-200 active:scale-[0.97] hover:bg-error-600">Reject</button>
        </form>
    </div>
</div>
@endcan
@endsection
