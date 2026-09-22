@extends('layouts.app')

@section('title', 'IKE_BAST ' . $session->session_number)

@section('content')
<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-2xl font-bold">{{ $session->session_number }}</h1>
        <p class="text-sm text-gray-500">
            {{ $session->kind }} inspection — status
            <span class="px-2 py-1 text-xs rounded bg-gray-200">{{ $session->status }}</span>
        </p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('ike-bast.inspection.print', $session) }}" target="_blank" class="bg-blue-600 text-white px-4 py-2 rounded">Print</a>
        @if($session->asset)
            <a href="{{ route('assets.show', $session->asset) }}" class="bg-gray-300 text-gray-700 px-4 py-2 rounded">Back to Asset</a>
        @endif
    </div>
</div>

<div class="bg-white p-6 rounded shadow mb-6">
    <dl class="grid grid-cols-2 gap-2 text-sm">
        <dt class="font-medium">Asset</dt><dd>{{ $session->asset?->asset_id }} — {{ $session->asset?->brand }} {{ $session->asset?->model }}</dd>
        <dt class="font-medium">Serial Number</dt><dd>{{ $session->asset?->serial_number }}</dd>
        <dt class="font-medium">Inspection Date</dt><dd>{{ $session->inspection_date?->format('Y-m-d') ?? '-' }}</dd>
        <dt class="font-medium">Inspected By</dt><dd>{{ $session->creator?->name }}</dd>
        <dt class="font-medium">Linked Transfer</dt><dd>{{ $session->transfer?->transfer_number ?? '-' }}</dd>
        <dt class="font-medium">Notes</dt><dd>{{ $session->notes ?? '-' }}</dd>
    </dl>
</div>

<div class="bg-white p-6 rounded shadow mb-6 overflow-x-auto">
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
<div class="bg-white p-6 rounded shadow">
    <h2 class="text-lg font-semibold mb-3">Approval</h2>
    <div class="flex gap-2">
        <form method="POST" action="{{ route('ike-bast.inspection.approve', $session) }}">
            @csrf
            <input type="hidden" name="status" value="approved">
            <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded">Approve</button>
        </form>
        <form method="POST" action="{{ route('ike-bast.inspection.approve', $session) }}">
            @csrf
            <input type="hidden" name="status" value="rejected">
            <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded">Reject</button>
        </form>
    </div>
</div>
@endcan
@endsection
