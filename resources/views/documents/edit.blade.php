@extends('layouts.app')

@section('title', 'Edit ' . $document->document_number)

@section('content')
@php
    $parties = (array) ($payload['parties'] ?? []);
    $sender = (array) ($parties['sender'] ?? []);
    $receiver = (array) ($parties['receiver'] ?? []);
    $signatories = (array) ($payload['signatories'] ?? []);
    $handover = (array) ($payload['handover'] ?? []);
    $assetData = (array) ($payload['asset'] ?? []);
    $savedChecklist = (array) ($payload['checklist'] ?? []);
    $formType = old('document_type', $document->document_type);
@endphp

<div class="mx-auto max-w-5xl space-y-6">
    <x-page-header title="Edit document" :subtitle="$document->document_number . ' · the same number and file are overwritten — no new document is created.'">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" :href="route('documents.preview', $document)">Back to preview</x-button>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('documents.update', $document) }}" enctype="multipart/form-data" class="space-y-6">
        @csrf
        @method('PUT')

        <x-card title="Document">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Document type</label>
                    <select name="document_type" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        @foreach($types as $type)
                            <option value="{{ $type }}" {{ $formType === $type ? 'selected' : '' }}>{{ $type }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Document date</label>
                    <input type="date" name="document_date" value="{{ old('document_date', $payload['date'] ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Title</label>
                    <input type="text" name="title" value="{{ old('title', $document->title) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Nomor surat</label>
                    <input type="text" name="nomor_surat" value="{{ old('nomor_surat', $payload['nomor_surat'] ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
            </div>
        </x-card>

        <x-card title="A. Yang Menyerahkan">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Nama Penyerah</label>
                    <input type="text" name="signatory_menyerahkan" value="{{ old('signatory_menyerahkan', $sender['name'] ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">NIK / ID Karyawan</label>
                    <input type="text" name="sender_employee_id" value="{{ old('sender_employee_id', $sender['employee_id'] ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Entitas</label>
                    <select name="sender_entity" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        @foreach($companies as $company)
                            <option value="{{ $company->name }}" {{ old('sender_entity', $sender['entity'] ?? '') === $company->name ? 'selected' : '' }}>{{ $company->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Divisi / Jabatan</label>
                    <input type="text" name="sender_division" value="{{ old('sender_division', $sender['division'] ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                    <input type="email" name="sender_email" value="{{ old('sender_email', $sender['email'] ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">No. Telepon</label>
                    <input type="text" name="sender_phone" value="{{ old('sender_phone', $sender['phone'] ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
            </div>
        </x-card>

        <x-card title="B. Yang Menerima">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Nama Penerima</label>
                    <input type="text" name="signatory_menerima" value="{{ old('signatory_menerima', $receiver['name'] ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">NIK / ID Karyawan</label>
                    <input type="text" name="receiver_employee_id" value="{{ old('receiver_employee_id', $receiver['employee_id'] ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Entitas</label>
                    <select name="receiver_entity" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        @foreach($companies as $company)
                            <option value="{{ $company->name }}" {{ old('receiver_entity', $receiver['entity'] ?? '') === $company->name ? 'selected' : '' }}>{{ $company->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Divisi / Jabatan</label>
                    <input type="text" name="receiver_division" value="{{ old('receiver_division', $receiver['division'] ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                    <input type="email" name="receiver_email" value="{{ old('receiver_email', $receiver['email'] ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">No. Telepon</label>
                    <input type="text" name="receiver_phone" value="{{ old('receiver_phone', $receiver['phone'] ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
            </div>
        </x-card>

        <x-card title="C. Aset">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                @foreach([
                    'asset_type' => 'Jenis Aset',
                    'asset_brand_model' => 'Brand & Model',
                    'asset_serial_number' => 'Serial Number',
                    'asset_tag' => 'Asset Tag',
                    'asset_processor' => 'Processor',
                    'asset_ram' => 'RAM',
                    'asset_storage' => 'Storage',
                    'asset_os_version' => 'Sistem Operasi',
                ] as $field => $label)
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">{{ $label }}</label>
                        <input type="text" name="{{ $field }}" value="{{ old($field, $assetData[$field === 'asset_brand_model' ? 'brand_model' : ($field === 'asset_serial_number' ? 'serial' : substr($field, 6))] ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                @endforeach
            </div>
        </x-card>

        <x-card title="D. Waktu & Pernyataan">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Tanggal penyerahan</label>
                    <input type="date" name="handover_date" value="{{ old('handover_date', $handover['date'] ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Tanggal diterima</label>
                    <input type="date" name="received_date" value="{{ old('received_date', $handover['received_date'] ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Keperluan</label>
                    <input type="text" name="purpose" value="{{ old('purpose', $handover['purpose'] ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Pernyataan</label>
                    <textarea name="statement" rows="3" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">{{ old('statement', $payload['statement'] ?? '') }}</textarea>
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Catatan</label>
                    <textarea name="notes" rows="2" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">{{ old('notes', $payload['notes'] ?? '') }}</textarea>
                </div>
            </div>
        </x-card>

        <x-card title="E. Tanda Tangan">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Nama penyerah (tanda tangan)</label>
                    <input type="text" name="signatory_it" value="{{ old('signatory_it', $signatories['it'] ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Mengetahui</label>
                    <input type="text" name="signatory_mengetahui" value="{{ old('signatory_mengetahui', $signatories['mengetahui'] ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
            </div>
            <p class="mt-2 text-theme-xs text-gray-500">Tanda tangan penerima memakai nama di kolom B; tanda tangan penyerah memakai kolom A bila “Nama penyerah (tanda tangan)” dikosongkan.</p>
        </x-card>

        <div class="flex justify-end gap-3">
            <x-button variant="secondary" type="button" onclick="history.back()">Cancel</x-button>
            <x-button type="submit" variant="primary">Save &amp; regenerate PDF</x-button>
        </div>
    </form>
</div>
@endsection
