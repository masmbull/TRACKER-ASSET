@extends('layouts.app')

@section('title', 'New Inspection')

@section('content')
<div class="mx-auto max-w-6xl space-y-6" x-data="inspectionForm()">
    <x-page-header title="New Inspection" :subtitle="'IKE / BAST receiving inspection for ' . $asset->asset_id . ' — ' . $asset->brand . ' ' . $asset->model">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" :href="route('assets.show', $asset)">Back to Asset</x-button>
        </x-slot:actions>
    </x-page-header>

    <x-card title="Asset Reference">
        <dl class="grid grid-cols-1 gap-x-8 gap-y-3 text-theme-sm sm:grid-cols-2">
            <div class="flex justify-between gap-4 border-b border-gray-100 py-2 dark:border-gray-800">
                <dt class="text-gray-500">Serial Number</dt>
                <dd class="font-medium text-gray-800 dark:text-gray-200">{{ $asset->serial_number ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4 border-b border-gray-100 py-2 dark:border-gray-800">
                <dt class="text-gray-500">Hostname</dt>
                <dd class="font-medium text-gray-800 dark:text-gray-200">{{ $asset->hostname ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4 border-b border-gray-100 py-2 dark:border-gray-800">
                <dt class="text-gray-500">Location</dt>
                <dd class="font-medium text-gray-800 dark:text-gray-200">{{ $asset->location?->name ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4 border-b border-gray-100 py-2 dark:border-gray-800">
                <dt class="text-gray-500">Current User</dt>
                <dd class="font-medium text-gray-800 dark:text-gray-200">{{ $asset->currentUser?->name ?? '—' }}</dd>
            </div>
        </dl>
    </x-card>

    <form method="POST" action="{{ route('ike-bast.inspection.store') }}" class="space-y-6">
        @csrf
        <input type="hidden" name="asset_id" value="{{ $asset->id }}">

        <x-card title="Inspection Header">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Document Kind</label>
                    <select name="kind" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        @foreach(['IKE_BAST', 'BAST', 'FAT', 'CUSTOM'] as $kind)
                            <option value="{{ $kind }}" {{ old('kind', $defaultKind) === $kind ? 'selected' : '' }}>{{ $kind }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Inspection Date</label>
                    <input type="date" name="inspection_date" value="{{ old('inspection_date', now()->toDateString()) }}"
                           class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Linked Transfer</label>
                    <select name="transfer_id" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        <option value="">None</option>
                        @foreach($asset->transfers as $transfer)
                            <option value="{{ $transfer->id }}" {{ (string) old('transfer_id') === (string) $transfer->id ? 'selected' : '' }}>
                                {{ $transfer->transfer_number }} ({{ $transfer->transfer_date?->format('d M Y') }})
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
        </x-card>

        <x-card padding="p-0">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-5 py-3 dark:border-gray-800">
                <h3 class="text-theme-base font-semibold text-gray-800 dark:text-white/90">Inspection Line Items</h3>
                <x-button type="button" variant="secondary" size="sm" @click="addRow()">+ Add Item</x-button>
            </div>

            @error('lines') <p class="px-5 pt-3 text-theme-sm text-error-500">{{ $message }}</p> @enderror

            <div class="overflow-x-auto">
                <table class="min-w-full text-theme-sm">
                    <thead>
                        <tr class="border-b border-gray-100 bg-gray-50 text-theme-xs uppercase tracking-wide text-gray-500 dark:border-gray-800 dark:bg-gray-800/60">
                            <th class="px-3 py-2.5 text-left">#</th>
                            <th class="px-3 py-2.5 text-left">Item</th>
                            <th class="px-3 py-2.5 text-left">Item Code</th>
                            <th class="px-3 py-2.5 text-left">Barcode</th>
                            <th class="px-3 py-2.5 text-left">Serial</th>
                            <th class="px-3 py-2.5 text-left">Unit</th>
                            <th class="px-3 py-2.5 text-left">Qty Plan</th>
                            <th class="px-3 py-2.5 text-left">Qty Recv</th>
                            <th class="px-3 py-2.5 text-left">Condition</th>
                            <th class="px-3 py-2.5 text-left">Test</th>
                            <th class="px-3 py-2.5 text-center">Defect</th>
                            <th class="px-3 py-2.5 text-left">Remarks</th>
                            <th class="px-3 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(row, i) in rows" :key="row.key">
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="px-3 py-2 text-gray-500" x-text="i + 1"></td>
                                <td class="px-3 py-2"><input type="text" :name="`lines[${i}][item_desc]`" x-model="row.item_desc" class="w-40 rounded-lg border border-gray-200 px-2 py-1.5 text-theme-sm dark:border-gray-700 dark:bg-gray-900"></td>
                                <td class="px-3 py-2"><input type="text" :name="`lines[${i}][item_code]`" x-model="row.item_code" class="w-28 rounded-lg border border-gray-200 px-2 py-1.5 text-theme-sm dark:border-gray-700 dark:bg-gray-900"></td>
                                <td class="px-3 py-2"><input type="text" :name="`lines[${i}][barcode]`" x-model="row.barcode" class="w-28 rounded-lg border border-gray-200 px-2 py-1.5 text-theme-sm dark:border-gray-700 dark:bg-gray-900"></td>
                                <td class="px-3 py-2"><input type="text" :name="`lines[${i}][serial_number]`" x-model="row.serial_number" class="w-32 rounded-lg border border-gray-200 px-2 py-1.5 text-theme-sm dark:border-gray-700 dark:bg-gray-900"></td>
                                <td class="px-3 py-2"><input type="text" :name="`lines[${i}][unit]`" x-model="row.unit" class="w-16 rounded-lg border border-gray-200 px-2 py-1.5 text-theme-sm dark:border-gray-700 dark:bg-gray-900"></td>
                                <td class="px-3 py-2"><input type="number" step="0.01" :name="`lines[${i}][qty_planned]`" x-model="row.qty_planned" class="w-20 rounded-lg border border-gray-200 px-2 py-1.5 text-theme-sm dark:border-gray-700 dark:bg-gray-900"></td>
                                <td class="px-3 py-2"><input type="number" step="0.01" :name="`lines[${i}][qty_received]`" x-model="row.qty_received" class="w-20 rounded-lg border border-gray-200 px-2 py-1.5 text-theme-sm dark:border-gray-700 dark:bg-gray-900"></td>
                                <td class="px-3 py-2"><input type="text" :name="`lines[${i}][physical_condition]`" x-model="row.physical_condition" class="w-32 rounded-lg border border-gray-200 px-2 py-1.5 text-theme-sm dark:border-gray-700 dark:bg-gray-900"></td>
                                <td class="px-3 py-2">
                                    <select :name="`lines[${i}][test_result]`" x-model="row.test_result" class="rounded-lg border border-gray-200 px-2 py-1.5 text-theme-sm dark:border-gray-700 dark:bg-gray-900">
                                        <option value="na">N/A</option>
                                        <option value="pass">PASS</option>
                                        <option value="fail">FAIL</option>
                                    </select>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <input type="checkbox" :name="`lines[${i}][is_defective]`" value="1" x-model="row.is_defective">
                                </td>
                                <td class="px-3 py-2"><input type="text" :name="`lines[${i}][remarks]`" x-model="row.remarks" class="w-40 rounded-lg border border-gray-200 px-2 py-1.5 text-theme-sm dark:border-gray-700 dark:bg-gray-900"></td>
                                <td class="px-3 py-2 text-right">
                                    <button type="button" @click="removeRow(i)" x-show="rows.length > 1" class="text-theme-xs font-medium text-error-500 hover:text-error-600">Remove</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </x-card>

        <x-card title="Notes">
            <textarea name="notes" rows="3" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">{{ old('notes') }}</textarea>
        </x-card>

        <div class="flex justify-end gap-3">
            <a href="{{ route('assets.show', $asset) }}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</a>
            <x-button type="submit" variant="primary">Save Inspection</x-button>
        </div>
    </form>
</div>

<script>
    function inspectionForm() {
        const blank = () => ({
            key: Math.random().toString(36).slice(2),
            item_desc: '', item_code: '', barcode: '', serial_number: '',
            unit: 'Unit', qty_planned: 1, qty_received: 1,
            physical_condition: '', test_result: 'na', is_defective: false, remarks: '',
        });

        return {
            rows: [{ ...blank(), item_desc: @js($asset->category?->name), item_code: @js($asset->asset_id), barcode: @js($asset->barcode), serial_number: @js($asset->serial_number) }],
            addRow() { this.rows.push(blank()); },
            removeRow(i) { if (this.rows.length > 1) this.rows.splice(i, 1); },
        };
    }
</script>
@endsection