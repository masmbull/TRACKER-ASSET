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
        {{-- Errors and success travel through the layout (global error box +
             toasts), so this page renders none of its own: the old boxes
             doubled every notice. --}}
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
                            <option value="{{ $type }}" {{ old('document_type', $selectedType) === $type ? 'selected' : '' }}>{{ $type }}</option>
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
                    @foreach($templates->where('document_type', $selectedType) as $template)
                        <option value="{{ $template->id }}" {{ (string) old('template_id') === (string) $template->id ? 'selected' : '' }}>
                            {{ $template->name }}
                        </option>
                    @endforeach
                </select>
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
                    $defaultSenderName = $senderName;
                    $defaultSenderDepartment = $senderDepartment;
                    $defaultSenderEmail = $senderEmail;
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
                                <option value="{{ $company->name }}" {{ old('sender_entity', $asset->company?->name) === $company->name ? 'selected' : '' }}>
                                    {{ $company->name }}
                                </option>
                            @endforeach
                            {{-- A company entered before this feature existed, or
                                 one that is no longer in master data, must not
                                 silently disappear from its own document. --}}
                            @php($currentSender = old('sender_entity', $asset->company?->name))
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
                        <input type="text" name="signatory_menerima" value="{{ old('signatory_menerima', $asset->currentUser?->name) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">NIK / ID Karyawan</label>
                        <input type="text" name="receiver_employee_id" value="{{ old('receiver_employee_id', $asset->currentUser?->employee_id ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
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
                        <input type="text" name="receiver_location" value="{{ old('receiver_location', $asset->location?->name) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
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
                    <div class="md:col-span-2">
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Catatan</label>
                        <textarea name="notes" rows="3" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">{{ old('notes') }}</textarea>
                    </div>
                </div>

                {{-- Kelengkapan asset, item per item, the way the paper form asks.
                     A single free-text "Kelengkapan Asset" box could not record
                     "charger yes, mouse no" in any checkable way, and the printed
                     form asks each item as Ada / Tidak Ada. --}}
                @if(!empty($kelengkapan))
                    <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-800">
                        <h4 class="mb-3 text-theme-sm font-semibold text-gray-800 dark:text-white">Kelengkapan Asset</h4>
                        <div class="space-y-2">
                            @foreach($kelengkapan as $index => $item)
                                <div class="flex flex-col gap-2 rounded-xl border border-gray-100 bg-gray-50 px-3 py-2.5 dark:border-gray-800 dark:bg-gray-900/50 md:flex-row md:items-center md:justify-between">
                                    <span class="text-theme-sm text-gray-700 dark:text-gray-300">{{ $item }}</span>
                                    <div class="flex gap-3 text-theme-xs">
                                        @foreach(['ada' => 'Ada', 'tidak' => 'Tidak Ada'] as $value => $label)
                                            <label class="inline-flex items-center gap-1.5 text-gray-700 dark:text-gray-200">
                                                <input type="radio" name="kelengkapan[{{ $index }}]" value="{{ $value }}" {{ old("kelengkapan.{$index}", 'ada') === $value ? 'checked' : '' }}>
                                                {{ $label }}
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <div class="rounded-2xl border border-gray-200 p-4 dark:border-gray-800">
                <h3 class="mb-4 text-theme-base font-semibold text-gray-800 dark:text-white">D. Waktu Penyerahan</h3>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">{{ $copy['handover_date'] }}</label>
                        <input type="date" name="handover_date" value="{{ old('handover_date', now()->toDateString()) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">{{ $copy['received_date'] }}</label>
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
                <textarea name="statement" rows="4" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">{{ old('statement', $copy['statement']) }}</textarea>
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
                    {{-- Says what the type actually is, not always "FAT". A
                     TRANSFER has no acceptance checklist and the section is not
                     rendered at all; see documents.checklists. --}}
                <h3 class="mb-4 text-theme-base font-semibold text-gray-800 dark:text-white">Checklist {{ $selectedType }}</h3>
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
