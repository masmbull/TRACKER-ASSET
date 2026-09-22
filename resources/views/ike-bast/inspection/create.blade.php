@extends('layouts.app')

@section('title', 'IKE_BAST Inspection')

@section('content')
<h1 class="text-2xl font-bold mb-2">IKE_BAST Inspection Table</h1>
<p class="text-sm text-gray-500 mb-6">
    Acceptance / receiving inspection for asset <strong>{{ $asset->asset_id }}</strong>
    ({{ $asset->brand }} {{ $asset->model }}) — {{ $asset->company?->name }}
</p>

<div class="bg-white p-6 rounded shadow mb-6">
    <h2 class="text-lg font-semibold mb-3">Asset Reference</h2>
    <dl class="grid grid-cols-2 gap-2 text-sm">
        <dt class="font-medium">Serial Number</dt><dd>{{ $asset->serial_number }}</dd>
        <dt class="font-medium">Hostname</dt><dd>{{ $asset->hostname }}</dd>
        <dt class="font-medium">Location</dt><dd>{{ $asset->location?->name }}</dd>
        <dt class="font-medium">Current User</dt><dd>{{ $asset->currentUser?->name }}</dd>
    </dl>
</div>

<form method="POST" action="{{ route('ike-bast.inspection.store') }}" class="bg-white p-6 rounded shadow space-y-4">
    @csrf
    <input type="hidden" name="asset_id" value="{{ $asset->id }}">

    <div class="grid grid-cols-3 gap-4">
        <div>
            <label class="block text-sm font-medium">Document Kind</label>
            <select name="kind" class="w-full border rounded px-3 py-2">
                @foreach(['IKE_BAST', 'BAST', 'FAT', 'CUSTOM'] as $kind)
                    <option value="{{ $kind }}" {{ old('kind', $defaultKind) === $kind ? 'selected' : '' }}>{{ $kind }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium">Inspection Date</label>
            <input type="date" name="inspection_date" value="{{ old('inspection_date', now()->toDateString()) }}" class="w-full border rounded px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium">Linked Transfer</label>
            <select name="transfer_id" class="w-full border rounded px-3 py-2">
                <option value="">None</option>
                @foreach($asset->transfers as $transfer)
                    <option value="{{ $transfer->id }}">{{ $transfer->transfer_number }} ({{ $transfer->transfer_date?->format('Y-m-d') }})</option>
                @endforeach
            </select>
        </div>
    </div>

    @error('lines')
        <p class="text-red-600 text-sm">{{ $message }}</p>
    @enderror

<div>
    <div class="flex justify-between items-center mb-2">
        <h2 class="text-lg font-semibold">Inspection Line Items</h2>
        <button type="button" onclick="addInspectionRow()" class="bg-gray-200 text-gray-800 px-3 py-1 rounded text-sm">+ Add Row</button>
    </div>

    <div class="overflow-x-auto">
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
                    <th class="text-left py-2 px-2">Physical Condition</th>
                    <th class="text-left py-2 px-2">Test Result</th>
                    <th class="text-left py-2 px-2">Defective</th>
                    <th class="text-left py-2 px-2">Remarks</th>
                </tr>
            </thead>
            <tbody id="inspection-rows">
                <tr class="border-b">
                    <td class="py-2 px-2">1</td>
                    <td class="py-2 px-2"><input type="text" name="lines[0][item_desc]" value="{{ old('lines.0.item_desc', $asset->category?->name) }}" class="border rounded px-2 py-1 w-40"></td>
                    <td class="py-2 px-2"><input type="text" name="lines[0][item_code]" value="{{ old('lines.0.item_code', $asset->asset_id) }}" class="border rounded px-2 py-1 w-28"></td>
                    <td class="py-2 px-2"><input type="text" name="lines[0][barcode]" value="{{ old('lines.0.barcode', $asset->barcode) }}" class="border rounded px-2 py-1 w-28"></td>
                    <td class="py-2 px-2"><input type="text" name="lines[0][serial_number]" value="{{ old('lines.0.serial_number', $asset->serial_number) }}" class="border rounded px-2 py-1 w-32"></td>
                    <td class="py-2 px-2"><input type="text" name="lines[0][unit]" value="{{ old('lines.0.unit', 'Unit') }}" class="border rounded px-2 py-1 w-16"></td>
                    <td class="py-2 px-2"><input type="number" step="0.01" name="lines[0][qty_planned]" value="{{ old('lines.0.qty_planned', 1) }}" class="border rounded px-2 py-1 w-20"></td>
                    <td class="py-2 px-2"><input type="number" step="0.01" name="lines[0][qty_received]" value="{{ old('lines.0.qty_received', 1) }}" class="border rounded px-2 py-1 w-20"></td>
                    <td class="py-2 px-2"><input type="text" name="lines[0][physical_condition]" value="{{ old('lines.0.physical_condition') }}" class="border rounded px-2 py-1 w-32"></td>
                    <td class="py-2 px-2">
                        <select name="lines[0][test_result]" class="border rounded px-2 py-1">
                            <option value="na" {{ old('lines.0.test_result') === 'na' ? 'selected' : '' }}>N/A</option>
                            <option value="pass" {{ old('lines.0.test_result') === 'pass' ? 'selected' : '' }}>PASS</option>
                            <option value="fail" {{ old('lines.0.test_result') === 'fail' ? 'selected' : '' }}>FAIL</option>
                        </select>
                    </td>
                    <td class="py-2 px-2 text-center"><input type="checkbox" name="lines[0][is_defective]" value="1"></td>
                    <td class="py-2 px-2"><input type="text" name="lines[0][remarks]" value="{{ old('lines.0.remarks') }}" class="border rounded px-2 py-1 w-40"></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<script>
    let inspectionRowIndex = 1;

    function addInspectionRow() {
        const tbody = document.getElementById('inspection-rows');
        const i = inspectionRowIndex++;
        const row = document.createElement('tr');
        row.className = 'border-b';
        row.innerHTML =
            '<td class="py-2 px-2">' + (i + 1) + '</td>' +
            '<td class="py-2 px-2"><input type="text" name="lines[' + i + '][item_desc]" class="border rounded px-2 py-1 w-40"></td>' +
            '<td class="py-2 px-2"><input type="text" name="lines[' + i + '][item_code]" class="border rounded px-2 py-1 w-28"></td>' +
            '<td class="py-2 px-2"><input type="text" name="lines[' + i + '][barcode]" class="border rounded px-2 py-1 w-28"></td>' +
            '<td class="py-2 px-2"><input type="text" name="lines[' + i + '][serial_number]" class="border rounded px-2 py-1 w-32"></td>' +
            '<td class="py-2 px-2"><input type="text" name="lines[' + i + '][unit]" class="border rounded px-2 py-1 w-16"></td>' +
            '<td class="py-2 px-2"><input type="number" step="0.01" name="lines[' + i + '][qty_planned]" class="border rounded px-2 py-1 w-20"></td>' +
            '<td class="py-2 px-2"><input type="number" step="0.01" name="lines[' + i + '][qty_received]" class="border rounded px-2 py-1 w-20"></td>' +
            '<td class="py-2 px-2"><input type="text" name="lines[' + i + '][physical_condition]" class="border rounded px-2 py-1 w-32"></td>' +
            '<td class="py-2 px-2"><select name="lines[' + i + '][test_result]" class="border rounded px-2 py-1"><option value="na">N/A</option><option value="pass">PASS</option><option value="fail">FAIL</option></select></td>' +
            '<td class="py-2 px-2 text-center"><input type="checkbox" name="lines[' + i + '][is_defective]" value="1"></td>' +
            '<td class="py-2 px-2"><input type="text" name="lines[' + i + '][remarks]" class="border rounded px-2 py-1 w-40"></td>';
        tbody.appendChild(row);
    }
</script>

    <div>
        <label class="block text-sm font-medium">Notes</label>
        <textarea name="notes" rows="3" class="w-full border rounded px-3 py-2">{{ old('notes') }}</textarea>
    </div>

    <div class="flex gap-2 pt-4">
        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded">Save Inspection</button>
        <a href="{{ route('assets.show', $asset) }}" class="bg-gray-300 text-gray-700 px-6 py-2 rounded">Cancel</a>
    </div>
</form>
@endsection
