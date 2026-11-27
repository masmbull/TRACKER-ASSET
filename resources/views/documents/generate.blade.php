@extends('layouts.app')

@section('title', 'Generate Document')

@push('styles')
<link href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css" rel="stylesheet" />
@endpush

@section('content')
@php
    $formType = old('document_type', $selectedType);
    if (! in_array($formType, $types, true)) {
        $formType = $selectedType;
    }
    $formCopy = array_merge(
        (array) config('documents.copy.FAT', []),
        (array) config('documents.copy.'.$formType, [])
    );
@endphp
<div class="mx-auto max-w-6xl space-y-6">
    <x-page-header title="Generate Document" :subtitle="$asset ? $asset->asset_id . ' · ' . trim(($asset->brand ?? '') . ' ' . ($asset->model ?? '')) : 'Dokumen kosong — isi informasi secara manual.'">
        <x-slot:actions>
            <a href="{{ $asset ? route('assets.show', $asset) : route('documents.index') }}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ $asset ? 'Back to asset' : 'Back to documents' }}</a>
        </x-slot:actions>
    </x-page-header>

    <x-card>
        {{-- Errors and success travel through the layout (global error box +
             toasts), so this page renders none of its own: the old boxes
             doubled every notice. --}}
        <div class="mb-6 rounded-2xl border border-brand-100 bg-brand-50 px-4 py-3 dark:border-brand-500/20 dark:bg-brand-500/10">
            <p class="text-theme-sm font-medium text-brand-700 dark:text-brand-300">{{ $asset ? 'Data terisi otomatis dari registrasi aset. Tidak perlu copy/paste.' : 'Isi detail dokumen dan aset secara manual sebelum membuat PDF.' }}</p>
        </div>

        <form method="POST" enctype="multipart/form-data" action="{{ $asset ? route('documents.generate.store', $asset) : route('documents.generate.blank.store') }}" class="space-y-6">
            @csrf

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Document Type *</label>
                    <select name="document_type" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" id="document-type">
                        @foreach($types as $type)
                            @php
                                $typeCopy = array_merge(
                                    (array) config('documents.copy.FAT', []),
                                    (array) config('documents.copy.'.$type, [])
                                );
                            @endphp
                            <option value="{{ $type }}" data-handover-label="{{ $typeCopy['handover_date'] ?? '' }}" data-received-label="{{ $typeCopy['received_date'] ?? '' }}" data-statement="{{ $typeCopy['statement'] ?? '' }}" {{ $formType === $type ? 'selected' : '' }}>{{ $type }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Document Date</label>
                    <input type="date" name="document_date" value="{{ old('document_date', now()->toDateString()) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
            </div>

            <div>
                <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Template (opsional)</label>
                {{-- Only templates for the selected type. Listing a BAST template
                     under a FAT document invited picking the wrong one, and a
                     template whose fields do not match the document renders gaps
                     nobody notices until someone signs. --}}
                <select name="template_id" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    <option value="">None</option>
                    @foreach($templates as $template)
                        <option value="{{ $template->id }}" data-document-type="{{ $template->document_type }}" @if($template->document_type !== $formType) hidden disabled @endif {{ (string) old('template_id', $selectedTemplateId ?? '') === (string) $template->id ? 'selected' : '' }}>
                            {{ $template->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            @if($asset)
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
            @endif

            <div class="rounded-2xl border border-gray-200 p-4 dark:border-gray-800">
                <h3 class="mb-4 text-theme-base font-semibold text-gray-800 dark:text-white">A. Informasi Yang Menyerahkan</h3>
                {{-- Prefilled from the register instead of from whoever happens to
                     be logged in.

                     The sender used to default to Auth::user() - the administrator
                     generating the document. On a transfer that named the IT
                     administrator as the person handing the laptop back, which is
                     not a small error on a form people sign. It now comes from
                     the assignment history: the person giving it back, or the IT
                     PIC for a brand new laptop where nobody is returning one. --}}
                @php
                    // The sender is resolved in the controller, by the same method
                    // that fills the PDF. Everything here reads from that one
                    // answer - re-deriving it in the template is what let the form
                    // and the document disagree about who was signing.
                    $defaultSenderName = $asset ? $senderName : '';
                    $defaultSenderDepartment = $asset ? $senderDepartment : '';
                    $defaultSenderEmail = $asset ? $senderEmail : '';
                @endphp
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Nama Penyerah</label>
                        <input type="text" name="signatory_menyerahkan" value="{{ old('signatory_menyerahkan', $defaultSenderName ?? Auth::user()?->name) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">NIK / ID Karyawan</label>
                        <input type="text" name="sender_employee_id" value="{{ old('sender_employee_id', $senderUser?->employee_id ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                            {{-- Prefills from whichever person the sender turned out to be.
                                 Before users had an employee_id column this defaulted from
                                 Auth::user()?->employee_id, which was always empty - the
                                 expression was decoration, and the box was retyped off a
                                 payroll sheet on every document. --}}
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Entitas Penyerah *</label>
                        {{-- A choice, not free text. Two spellings of one company
                             ("PT Mito" / "PT. Mito") make one organisation look
                             like two on a document people sign, and the sender is
                             normally the asset's own company. --}}
                        <select name="sender_entity" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                            <option value="">— pilih entitas —</option>
                            @foreach($companies as $company)
                                <option value="{{ $company->name }}" {{ old('sender_entity', $asset?->company?->name ?? '') === $company->name ? 'selected' : '' }}>
                                    {{ $company->name }}
                                </option>
                            @endforeach
                            {{-- A company entered before this feature existed, or
                                 one that is no longer in master data, must not
                                 silently disappear from its own document. --}}
                            @php
                                $currentSender = old('sender_entity', $asset?->company?->name ?? '');
                            @endphp
                            @if($currentSender && ! $companies->contains('name', $currentSender))
                                <option value="{{ $currentSender }}" selected>{{ $currentSender }} (tidak ada di master data)</option>
                            @endif
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Divisi / Jabatan</label>
                        <input type="text" name="sender_division" value="{{ old('sender_division', $defaultSenderDepartment ?? Auth::user()?->department ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                        <input type="email" name="sender_email" value="{{ old('sender_email', $defaultSenderEmail ?? Auth::user()?->email) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
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
                        <input type="text" name="signatory_menerima" value="{{ old('signatory_menerima', $asset?->currentUser?->name ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">NIK / ID Karyawan</label>
                        <input type="text" name="receiver_employee_id" value="{{ old('receiver_employee_id', $asset?->currentUser?->employee_id ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Entitas Penerima *</label>
                        {{-- Was free text defaulting to "Company / Location" mashed
                             together with a slash. Two master-data concepts in one
                             string matches nothing, so it could not be a choice and
                             could not be checked. The branch now has its own field
                             below; this one is the legal entity. --}}
                        <select name="receiver_entity" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                            <option value="">— pilih entitas —</option>
                            @foreach($companies as $company)
                                <option value="{{ $company->name }}" {{ old('receiver_entity') === $company->name ? 'selected' : '' }}>{{ $company->name }}</option>
                            @endforeach
                            @php($currentReceiver = old('receiver_entity'))
                            @if($currentReceiver && ! $companies->contains('name', $currentReceiver))
                                <option value="{{ $currentReceiver }}" selected>{{ $currentReceiver }} (tidak ada di master data)</option>
                            @endif
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Cabang / Lokasi</label>
                        <input type="text" name="receiver_location" value="{{ old('receiver_location', $asset?->location?->name ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Divisi / Jabatan</label>
                        <input type="text" name="receiver_division" value="{{ old('receiver_division', $asset?->currentUser?->department ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                        <input type="email" name="receiver_email" value="{{ old('receiver_email', $asset?->currentUser?->email ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 p-4 dark:border-gray-800">
                <h3 class="mb-4 text-theme-base font-semibold text-gray-800 dark:text-white">C. Informasi Aset</h3>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Jenis Aset</label>
                        <input type="text" value="{{ old('asset_type', $asset?->category?->name ?? '') }}" name="asset_type" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Brand &amp; Model</label>
                        <input type="text" value="{{ old('asset_brand_model', trim(($asset?->brand ?? '') . ' ' . ($asset?->model ?? ''))) }}" name="asset_brand_model" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Serial Number</label>
                        <input type="text" value="{{ old('asset_serial_number', $asset?->serial_number ?? '') }}" name="asset_serial_number" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Asset Tag / Inventory Code</label>
                        <input type="text" value="{{ old('asset_tag', $asset?->asset_id ?? '') }}" name="asset_tag" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Processor</label>
                        <input type="text" value="{{ old('asset_processor', $asset?->processor ?? '') }}" name="asset_processor" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">RAM</label>
                        <input type="text" value="{{ old('asset_ram', $asset?->ram ?? '') }}" name="asset_ram" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Storage</label>
                        <input type="text" value="{{ old('asset_storage', $asset?->storage ?? '') }}" name="asset_storage" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Storage Type</label>
                        <input type="text" value="{{ old('asset_storage_type', $asset?->storage_type ?? '') }}" name="asset_storage_type" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Storage Capacity</label>
                        <input type="text" value="{{ old('asset_storage_capacity', $asset?->storage_capacity ?? '') }}" name="asset_storage_capacity" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Sistem Operasi</label>
                        <input type="text" value="{{ old('asset_os_version', $asset?->os_version ?? '') }}" name="asset_os_version" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Catatan</label>
                        <textarea name="notes" rows="3" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">{{ old('notes') }}</textarea>
                    </div>
                </div>

                {{-- Kelengkapan asset, item per item, the way the paper form asks.
                     A single free-text "Kelengkapan Asset" box could not record
                     "charger yes, mouse no" in any checkable way, and the printed
                     form asks each item as Ada / Tidak Ada. --}}
                @foreach($types as $completenessType)
                    @php($completenessItems = (array) config('documents.kelengkapan.'.$completenessType, []))
                    @if(!empty($completenessItems))
                        <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-800" data-kelengkapan-type="{{ $completenessType }}" @if($completenessType !== $formType) hidden @endif>
                            <h4 class="mb-3 text-theme-sm font-semibold text-gray-800 dark:text-white">Kelengkapan Asset</h4>
                            <div class="space-y-2">
                                @foreach($completenessItems as $index => $item)
                                    @php($answer = $completenessType === $formType ? old("kelengkapan.{$index}", 'ada') : 'ada')
                                    <div class="flex flex-col gap-2 rounded-xl border border-gray-100 bg-gray-50 px-3 py-2.5 dark:border-gray-800 dark:bg-gray-900/50 md:flex-row md:items-center md:justify-between">
                                        <span class="text-theme-sm text-gray-700 dark:text-gray-300">{{ $item }}</span>
                                        <div class="flex gap-3 text-theme-xs">
                                            @foreach(['ada' => 'Ada', 'tidak' => 'Tidak Ada'] as $value => $label)
                                                <label class="inline-flex items-center gap-1.5 text-gray-700 dark:text-gray-200">
                                                    <input type="radio" name="kelengkapan[{{ $index }}]" value="{{ $value }}" @if($completenessType !== $formType) disabled @endif {{ $answer === $value ? 'checked' : '' }}>
                                                    {{ $label }}
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>

            <div class="rounded-2xl border border-gray-200 p-4 dark:border-gray-800">
                <h3 class="mb-4 text-theme-base font-semibold text-gray-800 dark:text-white">D. Waktu Penyerahan</h3>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div>
                        <label id="handover-date-label" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">{{ $formCopy['handover_date'] }}</label>
                        <input type="date" name="handover_date" value="{{ old('handover_date', now()->toDateString()) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label id="received-date-label" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">{{ $formCopy['received_date'] }}</label>
                        <input type="date" name="received_date" value="{{ old('received_date') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Keperluan</label>
                        <input type="text" name="purpose" value="{{ old('purpose') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" placeholder="Alat Kerja Karyawan Baru">
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 p-4 dark:border-gray-800">
                <h3 class="mb-4 text-theme-base font-semibold text-gray-800 dark:text-white">E. Pernyataan</h3>
                <textarea name="statement" rows="4" data-default-statement="{{ $formCopy['statement'] }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">{{ old('statement', $formCopy['statement']) }}</textarea>
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
                        <input type="text" name="signatory_it" value="{{ old('signatory_it', $defaultSenderName ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 mt-4">
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Tanda Tangan Basah Penyerah (PNG/JPG, max 2MB)</label>
                        <input type="file" name="signatory_image" accept="image/png,image/jpeg" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        <p class="mt-1 text-theme-xs text-gray-500">Gambar akan dicetak pada kolom tanda tangan penyerah.</p>
                    </div>
                </div>
            </div>

            @foreach($types as $checklistType)
                @php($checklistItems = (array) config('documents.checklists.'.$checklistType, []))
                @if(!empty($checklistItems))
                    <div class="rounded-2xl border border-gray-200 p-4 dark:border-gray-800" data-checklist-type="{{ $checklistType }}" @if($checklistType !== $formType) hidden @endif>
                        <h3 class="mb-4 text-theme-base font-semibold text-gray-800 dark:text-white">Checklist {{ $checklistType }}</h3>
                        <div class="space-y-3">
                            @foreach($checklistItems as $index => $item)
                                @php($answer = $checklistType === $formType ? old("checklist.{$index}", 'pass') : 'pass')
                                <div class="flex flex-col gap-2 rounded-xl border border-gray-100 bg-gray-50 px-3 py-3 dark:border-gray-800 dark:bg-gray-900/50 md:flex-row md:items-center md:justify-between">
                                    <span class="text-theme-sm text-gray-700 dark:text-gray-300">{{ $item }}</span>
                                    <div class="flex gap-3 text-theme-xs">
                                        @foreach(['pass' => 'PASS', 'fail' => 'FAIL', 'na' => 'N/A'] as $value => $label)
                                            <label class="inline-flex items-center gap-1.5 text-gray-700 dark:text-gray-200">
                                                <input type="radio" name="checklist[{{ $index }}]" value="{{ $value }}" @if($checklistType !== $formType) disabled @endif {{ $answer === $value ? 'checked' : '' }}>
                                                {{ $label }}
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach

            <div class="flex justify-end gap-3 pt-2">
                <a href="{{ $asset ? route('assets.show', $asset) : route('documents.index') }}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</a>
                <x-button type="submit" variant="primary">Generate &amp; Preview</x-button>
            </div>
        </form>
    </x-card>
</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const typeSelect = document.getElementById('document-type');
        const templateSelect = document.querySelector('select[name="template_id"]');
        const handoverLabel = document.getElementById('handover-date-label');
        const receivedLabel = document.getElementById('received-date-label');
        const statement = document.querySelector('textarea[name="statement"]');
        let previousDefaultStatement = statement?.dataset.defaultStatement ?? '';

        const syncDocumentType = () => {
            const selectedOption = typeSelect?.selectedOptions[0];
            if (!selectedOption) return;

            const selectedType = selectedOption.value;
            document.querySelectorAll('[data-kelengkapan-type]').forEach((group) => {
                const active = group.dataset.kelengkapanType === selectedType;
                group.hidden = !active;
                group.querySelectorAll('input').forEach((input) => {
                    input.disabled = !active;
                });
            });
            document.querySelectorAll('[data-checklist-type]').forEach((group) => {
                const active = group.dataset.checklistType === selectedType;
                group.hidden = !active;
                group.querySelectorAll('input').forEach((input) => {
                    input.disabled = !active;
                });
            });

            if (templateSelect) {
                Array.from(templateSelect.options).forEach((option) => {
                    if (!option.value) return;
                    const hidden = option.dataset.documentType !== selectedType;
                    option.hidden = hidden;
                    option.disabled = hidden;
                });
                if (templateSelect.selectedOptions[0]?.disabled) {
                    templateSelect.value = '';
                }
            }

            if (handoverLabel) handoverLabel.textContent = selectedOption.dataset.handoverLabel ?? '';
            if (receivedLabel) receivedLabel.textContent = selectedOption.dataset.receivedLabel ?? '';

            const nextDefaultStatement = selectedOption.dataset.statement ?? '';
            if (statement && (!statement.value.trim() || statement.value === previousDefaultStatement)) {
                statement.value = nextDefaultStatement;
            }
            previousDefaultStatement = nextDefaultStatement;
        };

        typeSelect?.addEventListener('change', syncDocumentType);
        syncDocumentType();

        if (typeof window.flatpickr === 'function') {
            window.flatpickr('input[type="date"]', {
                dateFormat: 'Y-m-d',
            });
        }
    });
</script>
@endpush

@endsection
