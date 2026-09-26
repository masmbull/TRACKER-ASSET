@extends('layouts.app')

@section('title', 'Generate Document')

@section('content')
<div class="mx-auto max-w-6xl space-y-6">
    <x-page-header title="Generate Document" :subtitle="$asset->asset_id . ' · ' . ($asset->brand . ' ' . $asset->model)">
        <x-slot:actions>
            <a href="{{ route('assets.show', $asset) }}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Back to asset</a>
        </x-slot:actions>
    </x-page-header>

    <x-card>
        <div class="mb-6 rounded-2xl border border-brand-100 bg-brand-50 px-4 py-3 dark:border-brand-500/20 dark:bg-brand-500/10">
            <p class="text-theme-sm font-medium text-brand-700 dark:text-brand-300">Data terisi otomatis dari registrasi aset. Tidak perlu copy/paste.</p>
        </div>

        <form method="POST" action="{{ route('documents.generate.store', $asset) }}" class="space-y-6">
            @csrf

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Document Type *</label>
                    <select name="document_type" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" id="document-type">
                        @foreach($types as $type)
                            <option value="{{ $type }}" {{ old('document_type', 'FAT') === $type ? 'selected' : '' }}>{{ $type }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Document Date</label>
                    <input type="date" name="document_date" value="{{ old('document_date', now()->toDateString()) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Linked Transfer (optional)</label>
                    <select name="transfer_id" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        <option value="">None</option>
                        @foreach($transfers as $transfer)
                            <option value="{{ $transfer->id }}" {{ (string) old('transfer_id') === (string) $transfer->id ? 'selected' : '' }}>
                                {{ $transfer->transfer_number }} — {{ $transfer->fromUser?->name }} → {{ $transfer->toUser?->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Status Aset</label>
                    <input type="text" value="{{ $asset->status?->name ?? '—' }}" class="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" readonly>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 p-4 dark:border-gray-800">
                <h3 class="mb-4 text-theme-base font-semibold text-gray-800 dark:text-white">A. Informasi Yang Menyerahkan</h3>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Nama Penyerah</label>
                        <input type="text" name="signatory_menyerahkan" value="{{ old('signatory_menyerahkan', Auth::user()?->name) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">NIK / ID Karyawan</label>
                        <input type="text" name="sender_employee_id" value="{{ old('sender_employee_id', Auth::user()?->employee_id ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Entitas</label>
                        <input type="text" name="sender_entity" value="{{ old('sender_entity', $asset->company?->name) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Divisi / Jabatan</label>
                        <input type="text" name="sender_division" value="{{ old('sender_division', Auth::user()?->department ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                        <input type="email" name="sender_email" value="{{ old('sender_email', Auth::user()?->email) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">No. Telepon</label>
                        <input type="text" name="sender_phone" value="{{ old('sender_phone') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" placeholder="N/A">
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 p-4 dark:border-gray-800">
                <h3 class="mb-4 text-theme-base font-semibold text-gray-800 dark:text-white">B. Informasi Yang Menerima</h3>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Nama Penerima</label>
                        <input type="text" name="signatory_menerima" value="{{ old('signatory_menerima', $asset->currentUser?->name) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Entitas / Cabang</label>
                        <input type="text" name="receiver_entity" value="{{ old('receiver_entity', $asset->company?->name . ' / ' . ($asset->location?->name ?? '')) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Divisi / Jabatan</label>
                        <input type="text" name="receiver_division" value="{{ old('receiver_division', $asset->currentUser?->department ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                        <input type="email" name="receiver_email" value="{{ old('receiver_email', $asset->currentUser?->email) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 p-4 dark:border-gray-800">
                <h3 class="mb-4 text-theme-base font-semibold text-gray-800 dark:text-white">C. Informasi Aset</h3>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Jenis Aset</label>
                        <input type="text" value="{{ old('asset_type', $asset->category?->name ?? 'Laptop') }}" name="asset_type" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Brand &amp; Model</label>
                        <input type="text" value="{{ old('asset_brand_model', trim(($asset->brand ?? '') . ' ' . ($asset->model ?? ''))) }}" name="asset_brand_model" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Serial Number</label>
                        <input type="text" value="{{ old('asset_serial_number', $asset->serial_number) }}" name="asset_serial_number" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Asset Tag / Inventory Code</label>
                        <input type="text" value="{{ old('asset_tag', $asset->asset_id) }}" name="asset_tag" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Processor</label>
                        <input type="text" value="{{ old('asset_processor', $asset->processor) }}" name="asset_processor" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">RAM</label>
                        <input type="text" value="{{ old('asset_ram', $asset->ram) }}" name="asset_ram" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Storage</label>
                        <input type="text" value="{{ old('asset_storage', $asset->storage) }}" name="asset_storage" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Kelengkapan Asset</label>
                        <input type="text" value="{{ old('asset_accessories', '') }}" name="asset_accessories" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" placeholder="Charger, mouse, tas, dll">
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Catatan</label>
                        <textarea name="notes" rows="3" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">{{ old('notes') }}</textarea>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 p-4 dark:border-gray-800">
                <h3 class="mb-4 text-theme-base font-semibold text-gray-800 dark:text-white">D. Waktu Penyerahan</h3>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Tanggal Penyerahan</label>
                        <input type="date" name="handover_date" value="{{ old('handover_date', now()->toDateString()) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Tanggal Diterima</label>
                        <input type="date" name="received_date" value="{{ old('received_date', now()->toDateString()) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Keperluan</label>
                        <input type="text" name="purpose" value="{{ old('purpose') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" placeholder="Alat Kerja Karyawan Baru">
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 p-4 dark:border-gray-800">
                <h3 class="mb-4 text-theme-base font-semibold text-gray-800 dark:text-white">E. Pernyataan</h3>
                <textarea name="statement" rows="4" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">{{ old('statement', 'Digunakan untuk keperluan pekerjaan dan jika akan dikembalikan harus dalam kondisi baik seperti diterima.') }}</textarea>
            </div>

            <div class="rounded-2xl border border-gray-200 p-4 dark:border-gray-800">
                <h3 class="mb-4 text-theme-base font-semibold text-gray-800 dark:text-white">F. Persetujuan</h3>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">USER / Nama</label>
                        <input type="text" name="signatory_mengetahui" value="{{ old('signatory_mengetahui') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">IT / Nama Karyawan</label>
                        <input type="text" name="signatory_it" value="{{ old('signatory_it') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                </div>
            </div>

            @if(!empty($checklist))
                <div class="rounded-2xl border border-gray-200 p-4 dark:border-gray-800">
                    <h3 class="mb-4 text-theme-base font-semibold text-gray-800 dark:text-white">Checklist FAT</h3>
                    <div class="space-y-3">
                        @foreach($checklist as $index => $item)
                            <div class="flex flex-col gap-2 rounded-xl border border-gray-100 bg-gray-50 px-3 py-3 dark:border-gray-800 dark:bg-gray-900/50 md:flex-row md:items-center md:justify-between">
                                <span class="text-theme-sm text-gray-700 dark:text-gray-300">{{ $item }}</span>
                                <div class="flex gap-3 text-theme-xs">
                                    @foreach(['pass' => 'PASS', 'fail' => 'FAIL', 'na' => 'N/A'] as $value => $label)
                                        <label class="inline-flex items-center gap-1.5 text-gray-700 dark:text-gray-200">
                                            <input type="radio" name="checklist[{{ $index }}]" value="{{ $value }}" {{ (old("checklist.{$index}", 'pass')) === $value ? 'checked' : '' }}>
                                            {{ $label }}
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="flex justify-end gap-3 pt-2">
                <a href="{{ route('assets.show', $asset) }}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</a>
                <x-button type="submit" variant="primary">Generate &amp; Preview</x-button>
            </div>
        </form>
    </x-card>
</div>
@endsection
