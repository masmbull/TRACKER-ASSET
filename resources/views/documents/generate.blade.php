@extends('layouts.app')

@section('title', 'Generate Document')

@section('content')
<h1 class="text-2xl font-bold mb-6">Generate Document — {{ $asset->asset_id }}</h1>

<div class="bg-white p-6 rounded shadow mb-6">
    <h2 class="text-lg font-semibold mb-4">Data Terisi Otomatis (tanpa copy/paste)</h2>
    <dl class="grid grid-cols-2 gap-2 text-sm">
        <dt class="font-medium">Asset ID</dt><dd>{{ $asset->asset_id }}</dd>
        <dt class="font-medium">Perusahaan</dt><dd>{{ $asset->company?->name }}</dd>
        <dt class="font-medium">Kategori</dt><dd>{{ $asset->category?->name }}</dd>
        <dt class="font-medium">Merk / Model</dt><dd>{{ $asset->brand }} {{ $asset->model }}</dd>
        <dt class="font-medium">Serial Number</dt><dd>{{ $asset->serial_number }}</dd>
        <dt class="font-medium">Hostname</dt><dd>{{ $asset->hostname }}</dd>
        <dt class="font-medium">Lokasi</dt><dd>{{ $asset->location?->name }}</dd>
        <dt class="font-medium">Pengguna Saat Ini</dt><dd>{{ $asset->currentUser?->name }}</dd>
    </dl>
</div>

<form method="POST" action="{{ route('documents.generate.store', $asset) }}" class="bg-white p-6 rounded shadow space-y-4">
    @csrf

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium">Document Type *</label>
            <select name="document_type" required class="w-full border rounded px-3 py-2" id="document-type">
                @foreach($types as $type)
                    <option value="{{ $type }}" {{ old('document_type', 'FAT') === $type ? 'selected' : '' }}>{{ $type }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium">Document Date</label>
            <input type="date" name="document_date" value="{{ old('document_date', now()->toDateString()) }}" class="w-full border rounded px-3 py-2">
        </div>
    </div>

    <div>
        <label class="block text-sm font-medium">Linked Transfer (optional)</label>
        <select name="transfer_id" class="w-full border rounded px-3 py-2">
            <option value="">None</option>
            @foreach($transfers as $transfer)
                <option value="{{ $transfer->id }}" {{ (string) old('transfer_id') === (string) $transfer->id ? 'selected' : '' }}>
                    {{ $transfer->transfer_number }} — {{ $transfer->fromUser?->name }} → {{ $transfer->toUser?->name }} ({{ $transfer->transfer_date?->format('Y-m-d') }})
                </option>
            @endforeach
        </select>
    </div>

    <fieldset class="border rounded p-4">
        <legend class="text-sm font-semibold px-1">Signatories (typed names)</legend>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium">Yang Menyerahkan</label>
                <input type="text" name="signatory_menyerahkan" value="{{ old('signatory_menyerahkan', Auth::user()?->name) }}" class="w-full border rounded px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium">Yang Menerima</label>
                <input type="text" name="signatory_menerima" value="{{ old('signatory_menerima', $asset->currentUser?->name) }}" class="w-full border rounded px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium">Mengetahui</label>
                <input type="text" name="signatory_mengetahui" value="{{ old('signatory_mengetahui') }}" class="w-full border rounded px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium">IT</label>
                <input type="text" name="signatory_it" value="{{ old('signatory_it') }}" class="w-full border rounded px-3 py-2">
            </div>
        </div>
    </fieldset>

    <fieldset class="border rounded p-4">
        <legend class="text-sm font-semibold px-1">FAT Checklist (applies to FAT)</legend>
        @foreach($checklist as $index => $item)
            <div class="flex items-center justify-between py-1 border-b last:border-b-0">
                <span class="text-sm">{{ $item }}</span>
                <span class="flex gap-3 text-sm">
                    @foreach(['pass' => 'PASS', 'fail' => 'FAIL', 'na' => 'N/A'] as $value => $label)
                        <label class="flex items-center gap-1">
                            <input type="radio" name="checklist[{{ $index }}]" value="{{ $value }}" {{ (old("checklist.{$index}", 'pass')) === $value ? 'checked' : '' }}>
                            {{ $label }}
                        </label>
                    @endforeach
                </span>
            </div>
        @endforeach
    </fieldset>

    <div>
        <label class="block text-sm font-medium">Notes</label>
        <textarea name="notes" class="w-full border rounded px-3 py-2">{{ old('notes') }}</textarea>
    </div>

    <div class="flex gap-2 pt-4">
        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded">Generate & Preview</button>
        <a href="{{ route('assets.show', $asset) }}" class="bg-gray-300 text-gray-700 px-6 py-2 rounded">Cancel</a>
    </div>
</form>
@endsection
