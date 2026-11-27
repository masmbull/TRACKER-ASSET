@extends('layouts.app')

@section('title', 'Asset ' . $asset->asset_id)
@section('header', 'Asset Detail')

@section('content')
@php
    $badgeMap = ['Active' => 'success', 'Available' => 'brand', 'Assigned' => 'warning', 'Repair' => 'orange', 'Retired' => 'gray', 'Disposed' => 'error'];
@endphp

<x-page-header :title="$asset->asset_id" :subtitle="$asset->brand . ' ' . $asset->model . ' · ' . ($asset->company?->name ?? '')">
    <x-slot:actions>
        <x-button type="button" variant="secondary" size="sm" @click="$dispatch('open-modal', 'asset-label-modal')">
            <x-icon name="printer" class="h-4 w-4" /> Label
        </x-button>
        @can('audit', $asset)
            <x-button variant="secondary" size="sm" :href="route('assets.audit', $asset)">Audit</x-button>
        @endcan
        @can('transfer', $asset)
            <x-button variant="success" size="sm" :href="route('assets.transfer', $asset)">Transfer</x-button>
        @endcan
        @can('update', $asset)
            <x-button variant="secondary" size="sm" :href="route('assets.edit', $asset)">Edit</x-button>
        @endcan
        @can('archive', $asset)
            <x-button type="button" variant="secondary" size="sm" @click="$dispatch('open-modal', 'archive-asset-modal')">Archive</x-button>
            <x-button type="button" variant="warning" size="sm" @click="$dispatch('open-modal', 'retire-asset-modal')">Retire</x-button>
            <x-button type="button" variant="danger" size="sm" @click="$dispatch('open-modal', 'dispose-asset-modal')">Dispose</x-button>
        @endcan
    </x-slot:actions>
</x-page-header>

<div x-data="{ activeTab: 'overview' }">
    {{-- Tabs --}}
    <div class="border-b border-gray-200 dark:border-gray-800 mb-6">
        <nav class="flex gap-0 -mb-px overflow-x-auto">
            @foreach(['overview' => 'Overview', 'assignments' => 'Assignments', 'audit' => 'Audit', 'documents' => 'Documents', 'repairs' => 'Repairs', 'attachments' => 'Attachments', 'activity' => 'Activity'] as $id => $label)
                <button @click="activeTab = '{{ $id }}'"
                        :class="activeTab === '{{ $id }}' ? 'border-brand-500 text-brand-600 tab-active' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="px-4 py-3 text-theme-sm font-medium border-b-2 whitespace-nowrap transition-all duration-200 dark:text-gray-400 dark:hover:text-gray-300">
                    {{ $label }}
                </button>
            @endforeach
        </nav>
    </div>

    {{-- Overview --}}
    <div x-show="activeTab === 'overview'" x-transition>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <x-card title="Hardware">
                @foreach([
                    ['Processor', $asset->processor], ['RAM', $asset->ram], ['Storage', $asset->storage],
                    ['OS', $asset->os_version], ['MAC Address', $asset->mac_address], ['Static IP', $asset->static_ip],
                ] as [$label, $value])
                    <div class="flex items-center justify-between py-2 border-b border-gray-100 dark:border-gray-800 last:border-0">
                        <span class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $label }}</span>
                        <span class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $value ?: '—' }}</span>
                    </div>
                @endforeach
            </x-card>

            <x-card title="Asset Information">
                @foreach([
                    ['Company', $asset->company?->name], ['Category', $asset->category?->name],
                    ['Brand/Model', $asset->brand . ' ' . $asset->model], ['Serial Number', $asset->serial_number],
                    ['Hostname', $asset->hostname], ['Location', $asset->location?->name],
                    ['Purchase Year', $asset->purchase_year], ['Vendor', $asset->vendor],
                    ['Warranty', $asset->warranty_until?->format('Y-m-d')], ['Deploy Date', $asset->deploy_date?->format('Y-m-d')],
                    ['Software License', $asset->software_license], ['Admin IT PIC', $asset->adminItPic?->name],
                    ['Current User', $asset->currentUser?->name],
                ] as [$label, $value])
                    <div class="flex items-center justify-between py-2 border-b border-gray-100 dark:border-gray-800 last:border-0">
                        <span class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $label }}</span>
                        <span class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $value ?: '—' }}</span>
                    </div>
                @endforeach
            </x-card>
        </div>

        <x-card class="mt-6" title="Quick Actions">
            <div class="flex flex-wrap gap-3">
                @can('generateDocument', $asset)
                    <x-button type="button" variant="secondary" size="sm" @click="$dispatch('open-modal', 'generate-document-modal')">Generate FAT/BAST</x-button>
                @endcan
                @can('manageRepair', $asset)
                    <x-button variant="secondary" size="sm" :href="route('assets.repair.create', $asset)">Add Repair</x-button>
                @endcan
                @can('manageAttachments', $asset)
                    <x-button variant="secondary" size="sm" :href="route('ike-bast.inspection.create', $asset)">IKE_BAST Inspection</x-button>
                @endcan
                @can('update', $asset)
                    @if($asset->hasQr())
                        <a href="{{ route('assets.public.pdf', $asset->qr_token) }}" class="inline-flex items-center gap-1.5 rounded-lg bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 ring-1 ring-gray-300 transition-colors hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700">
                            Download QR PDF
                        </a>
                        <a href="{{ route('assets.qr', $asset) }}" class="inline-flex items-center gap-1.5 rounded-lg bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 ring-1 ring-gray-300 transition-colors hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700">
                            Download QR
                        </a>
                        <form method="POST" action="{{ route('assets.qr.generate', $asset) }}" onsubmit="return confirm('Re-create the QR for {{ $asset->asset_id }}? Any label already printed for it will stop working.');">
                            @csrf
                            <input type="hidden" name="regenerate" value="1">
                            <x-button type="submit" variant="secondary" size="sm">Re-create QR</x-button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('assets.qr.generate', $asset) }}">
                            @csrf
                            <x-button type="submit" variant="secondary" size="sm">Buat QR untuk asset ini</x-button>
                        </form>
                    @endif
                @endcan
                @can('audit', $asset)
                    {{-- Remote lives on Live Fleet's per-device row now: that page
                         has the online state and works for devices not yet on the
                         asset register. The asset action only scans. --}}
                    <x-button type="button" variant="primary" size="sm" @click="$dispatch('open-modal', 'scan-again-modal')">Scan Again</x-button>
                @endcan
            </div>
        </x-card>
    </div>

    {{-- Assignments --}}
    <div x-show="activeTab === 'assignments'" x-transition>
        <x-card>
            @if($asset->assignments->count() > 0)
                <x-table :headers="['User', 'Assigned', 'Returned', 'Location']">
                    @foreach($asset->assignments as $a)
                        <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-white/5">
                            <td class="py-3 px-4 text-theme-sm font-medium text-gray-700 dark:text-gray-300">{{ $a->user?->name }}</td>
                            <td class="py-3 px-4 text-theme-sm text-gray-500">{{ $a->assigned_date }}</td>
                            <td class="py-3 px-4 text-theme-sm text-gray-500">{{ $a->returned_date ?? '—' }}</td>
                            <td class="py-3 px-4 text-theme-sm text-gray-600">{{ $a->toLocation?->name }}</td>
                        </tr>
                    @endforeach
                </x-table>
            @else
                <p class="py-8 text-center text-theme-sm text-gray-400">No assignment history.</p>
            @endif
        </x-card>
    </div>

    {{-- Audit --}}
    <div x-show="activeTab === 'audit'" x-transition>
        <x-card>
            @if($asset->audits->count() > 0)
                <x-table :headers="['Date', 'Auditor', 'Result', 'Notes']">
                    @foreach($asset->audits as $audit)
                        <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-white/5">
                            <td class="py-3 px-4 text-theme-sm text-gray-500">{{ $audit->audit_date->format('Y-m-d') }}</td>
                            <td class="py-3 px-4 text-theme-sm font-medium text-gray-700">{{ $audit->auditor?->name }}</td>
                            <td class="py-3 px-4"><x-badge :variant="$audit->result === 'MATCH' ? 'success' : 'error'">{{ $audit->result }}</x-badge></td>
                            <td class="py-3 px-4 text-theme-sm text-gray-500 max-w-xs truncate">{{ $audit->notes }}</td>
                        </tr>
                    @endforeach
                </x-table>
            @else
                <p class="py-8 text-center text-theme-sm text-gray-400">No audit records.</p>
            @endif
        </x-card>
    </div>

    {{-- Documents --}}
    <div x-show="activeTab === 'documents'" x-transition>
        <x-card>
            <div class="flex flex-wrap items-center gap-2 mb-4">
                @can('generateDocument', $asset)
                    <x-button variant="primary" size="sm" type="button" @click="$dispatch('open-modal', 'generate-document-modal')">Generate FAT/BAST</x-button>
                    <x-button variant="secondary" size="sm" :href="route('documents.create', $asset)">+ Add</x-button>
                @endcan
            </div>

            @if($asset->documents->count() > 0)
                <x-table :headers="['Number', 'Type', 'Title', 'Date', 'Status', '']">
                    @foreach($asset->documents as $doc)
                        <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-white/5">
                            <td class="py-3 px-4"><a href="{{ route('documents.preview', $doc) }}" class="text-theme-sm font-medium text-brand-500 hover:text-brand-600">{{ $doc->document_number }}</a></td>
                            <td class="py-3 px-4 text-theme-sm text-gray-600">{{ $doc->document_type }}</td>
                            <td class="py-3 px-4 text-theme-sm text-gray-700">{{ $doc->title }}</td>
                            <td class="py-3 px-4 text-theme-sm text-gray-500">{{ $doc->generated_date?->format('Y-m-d') }}</td>
                            <td class="py-3 px-4"><x-badge :variant="$doc->status === 'final' ? 'success' : 'gray'">{{ $doc->status }}</x-badge></td>
                            <td class="py-3 px-4 text-right">
                                <button type="button" @click="$dispatch('open-modal', 'preview-doc-{{ $doc->id }}')" class="text-theme-sm text-brand-500 hover:text-brand-600">Preview</button>
                                <button type="button" @click="$dispatch('open-modal', 'delete-document-{{ $doc->id }}')" class="ml-2 text-theme-sm text-error-500 hover:text-error-600">Delete</button>
                            </td>
                        </tr>
                    @endforeach
                </x-table>
            @else
                <p class="py-8 text-center text-theme-sm text-gray-400">No documents.</p>
            @endif

            @foreach($asset->documents as $doc)
                <x-modal id="preview-doc-{{ $doc->id }}" title="{{ $doc->document_number }}" maxWidth="full">
                    <div class="bg-white p-4 rounded-xl shadow dark:bg-[#1d2939]">
                        <iframe id="pdf-frame-{{ $doc->id }}" src="{{ route('documents.download', $doc) }}?inline=1" class="w-full" style="height: 70vh; border: none;"></iframe>
                    </div>
                    <div class="flex justify-end gap-3 pt-2 px-6 py-4">
                        <button type="button" @click="$dispatch('close-modal', 'preview-doc-{{ $doc->id }}')" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Close</button>
                        <a href="{{ route('documents.download', $doc) }}" class="inline-flex items-center justify-center rounded-lg bg-success-500 px-4 py-2 text-theme-sm font-medium text-white shadow-theme-xs transition-all duration-200 active:scale-[0.97] hover:bg-success-600">Download PDF</a>
                    </div>
                </x-modal>
            @endforeach
        </x-card>
    </div>

    {{-- Repairs --}}
    <div x-show="activeTab === 'repairs'" x-transition>
        <x-card>
            @can('manageRepair', $asset)
                <div class="flex justify-end mb-4">
                    <x-button variant="secondary" size="sm" :href="route('assets.repair.create', $asset)">+ Add Repair</x-button>
                </div>
            @endcan

            @if($asset->repairs->count() > 0)
                @php $repairColors = ['open' => 'warning', 'in_progress' => 'brand', 'completed' => 'success', 'cancelled' => 'gray']; @endphp
                <x-table :headers="['Date', 'Problem', 'Action', 'Vendor', 'Cost', 'Status', '']">
                    @foreach($asset->repairs as $repair)
                        <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-white/5">
                            <td class="py-3 px-4 text-theme-sm text-gray-500">{{ $repair->date }}</td>
                            <td class="py-3 px-4 text-theme-sm text-gray-700">{{ $repair->problem }}</td>
                            <td class="py-3 px-4 text-theme-sm text-gray-600">{{ $repair->action }}</td>
                            <td class="py-3 px-4 text-theme-sm text-gray-600">{{ $repair->vendor }}</td>
                            <td class="py-3 px-4 text-theme-sm font-medium text-gray-700">{{ $repair->cost ? number_format($repair->cost, 0, ',', '.') : '—' }}</td>
                            <td class="py-3 px-4"><x-badge :variant="$repairColors[$repair->status] ?? 'gray'">{{ $repair->status }}</x-badge></td>
                            <td class="py-3 px-4">
                                <button type="button" @click="$dispatch('open-modal', 'delete-repair-{{ $repair->id }}')" class="text-theme-sm text-error-500 hover:text-error-600">Delete</button>
                            </td>
                        </tr>
                    @endforeach
                </x-table>
            @else
                <p class="py-8 text-center text-theme-sm text-gray-400">No repair records.</p>
            @endif
        </x-card>
    </div>

    {{-- Attachments --}}
    <div x-show="activeTab === 'attachments'" x-transition>
        <x-card>
            @can('manageAttachments', $asset)
                <form method="POST" action="{{ route('assets.attachments.store', $asset) }}" enctype="multipart/form-data" class="flex items-center gap-3 mb-4">
                    @csrf
                    <input type="file" name="file" required accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv"
                           class="flex-1 text-theme-sm text-gray-500 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-brand-50 file:text-brand-500 hover:file:bg-brand-100 file:cursor-pointer">
                    <x-button type="submit" variant="primary" size="sm">Upload</x-button>
                </form>
                @error('file') <p class="text-theme-xs text-error-500 mb-2">{{ $message }}</p> @enderror
            @endcan

            @if($asset->attachments->count() > 0)
                <x-table :headers="['File', 'Type', 'Size', 'Uploaded By', 'Date', '']">
                    @foreach($asset->attachments as $att)
                        <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-white/5">
                            <td class="py-3 px-4"><a href="{{ route('assets.attachments.download', [$asset, $att]) }}" class="text-theme-sm font-medium text-brand-500 hover:text-brand-600">{{ $att->file_name }}</a></td>
                            <td class="py-3 px-4"><x-badge>{{ $att->file_type }}</x-badge></td>
                            <td class="py-3 px-4 text-theme-sm text-gray-500">{{ number_format($att->file_size / 1024, 0) }} KB</td>
                            <td class="py-3 px-4 text-theme-sm text-gray-600">{{ $att->uploader?->name }}</td>
                            <td class="py-3 px-4 text-theme-sm text-gray-500">{{ $att->created_at?->format('Y-m-d') }}</td>
                            <td class="py-3 px-4">
                                @can('manageAttachments', $asset)
                                    <button type="button" @click="$dispatch('open-modal', 'delete-attachment-{{ $att->id }}')" class="text-theme-sm text-error-500 hover:text-error-600">Delete</button>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </x-table>
            @else
                <p class="py-8 text-center text-theme-sm text-gray-400">No attachments.</p>
            @endif
        </x-card>
    </div>

    {{-- Activity --}}
    <div x-show="activeTab === 'activity'" x-transition>
        <x-card>
            @if($asset->activityLogs->count() > 0)
                <div class="space-y-0">
                    @foreach($asset->activityLogs as $log)
                        <div class="flex items-start gap-3 px-4 py-3 border-b border-gray-100 dark:border-gray-800 last:border-0 hover:bg-gray-50 dark:hover:bg-gray-800/60 transition-colors">
                            <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700/50">
                                <span class="text-theme-xs font-semibold text-gray-500">{{ strtoupper(substr($log->action, 0, 1)) }}</span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-theme-sm text-gray-700 dark:text-gray-300">
                                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $log->actor?->name ?? 'System' }}</span>
                                    <span class="text-gray-400 mx-1">&middot;</span>
                                    {{ $log->action }}
                                </p>
                                <p class="text-theme-xs text-gray-400 mt-0.5">{{ $log->source }} · {{ $log->created_at->diffForHumans() }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="py-8 text-center text-theme-sm text-gray-400">No activity logs.</p>
            @endif
        </x-card>
    </div>
</div>

<x-modal id="generate-document-modal" title="Generate Document" maxWidth="lg">
    <div class="space-y-4">
        <p class="text-theme-sm text-gray-600 dark:text-gray-300">
            Select a document type to generate the record and PDF automatically from the current asset data.
        </p>

        <form method="GET" action="{{ route('documents.generate', $asset) }}" class="space-y-4">
            <div>
                <label for="doc_type" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Document type</label>
                <select id="doc_type" name="document_type" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    @foreach((array) config('documents.types', []) as $type)
                        <option value="{{ $type }}">{{ $type }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="doc-template" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Template (optional)</label>
                <select id="doc-template" name="template_id" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    <option value="">Use standard layout</option>
                    @foreach($activeDocumentTemplates ?? collect() as $template)
                        <option value="{{ $template->id }}" data-document-type="{{ $template->document_type }}">{{ $template->name }} · {{ $template->document_type }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" @click="$dispatch('close-modal', 'generate-document-modal')" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</button>
                <x-button type="submit" variant="primary">Continue</x-button>
            </div>
        </form>
    </div>
</x-modal>

<script>
    (() => {
        const type = document.getElementById('doc_type');
        const template = document.getElementById('doc-template');
        const syncTemplates = () => {
            if (!type || !template) return;
            [...template.options].forEach((option) => {
                if (!option.value) return;
                option.hidden = option.dataset.documentType !== type.value;
                option.disabled = option.hidden;
            });
            if (template.selectedOptions[0]?.disabled) template.value = '';
        };
        type?.addEventListener('change', syncTemplates);
        syncTemplates();
    })();
</script>

<x-modal id="scan-again-modal" title="Scan this device" maxWidth="md">
    <div class="space-y-4">
        <p class="text-theme-sm text-gray-600 dark:text-gray-300">
            This will send a scan request to the audit agent for this device so the latest hardware data can be compared.
        </p>

        <form method="POST" action="{{ route('assets.audit.agent.scan', $asset) }}" class="space-y-4" data-modal-action data-close-modal="scan-again-modal">
            @csrf

            <div class="rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 text-theme-sm text-brand-700 dark:border-brand-500/20 dark:bg-brand-500/10 dark:text-brand-400">
                Serial number: {{ $asset->serial_number ?: 'Not available' }}
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" @click="$dispatch('close-modal', 'scan-again-modal')" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</button>
                <x-button type="submit" variant="primary">Send scan</x-button>
            </div>
        </form>
    </div>
</x-modal>

@foreach($asset->documents as $doc)
    <x-modal id="delete-document-{{ $doc->id }}" title="Delete document" maxWidth="md">
        <div class="space-y-4">
            <p class="text-theme-sm text-gray-600 dark:text-gray-300">
                Are you sure you want to delete the document <span class="font-semibold text-gray-800 dark:text-white">{{ $doc->document_number }}</span> for this asset?
            </p>

            <form method="POST" action="/assets/{{ $asset->id }}/documents/{{ $doc->id }}" class="space-y-4">
                @csrf @method('DELETE')

                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="$dispatch('close-modal', 'delete-document-{{ $doc->id }}')" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</button>
                    <x-button type="submit" variant="danger">Delete document</x-button>
                </div>
            </form>
        </div>
    </x-modal>
@endforeach

@foreach($asset->repairs as $repair)
    <x-modal id="delete-repair-{{ $repair->id }}" title="Delete repair record" maxWidth="md">
        <div class="space-y-4">
            <p class="text-theme-sm text-gray-600 dark:text-gray-300">
                Delete the repair record for <span class="font-semibold text-gray-800 dark:text-white">{{ $repair->problem ?: 'this issue' }}</span>? This action cannot be undone.
            </p>

            <form method="POST" action="/assets/{{ $asset->id }}/repair/{{ $repair->id }}" class="space-y-4">
                @csrf @method('DELETE')

                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="$dispatch('close-modal', 'delete-repair-{{ $repair->id }}')" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</button>
                    <x-button type="submit" variant="danger">Delete repair</x-button>
                </div>
            </form>
        </div>
    </x-modal>
@endforeach

@foreach($asset->attachments as $att)
    <x-modal id="delete-attachment-{{ $att->id }}" title="Delete attachment" maxWidth="md">
        <div class="space-y-4">
            <p class="text-theme-sm text-gray-600 dark:text-gray-300">
                Remove the attachment <span class="font-semibold text-gray-800 dark:text-white">{{ $att->file_name }}</span> from this asset record?
            </p>

            <form method="POST" action="{{ route('assets.attachments.destroy', [$asset, $att]) }}" class="space-y-4">
                @csrf @method('DELETE')

                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="$dispatch('close-modal', 'delete-attachment-{{ $att->id }}')" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</button>
                    <x-button type="submit" variant="danger">Delete attachment</x-button>
                </div>
            </form>
        </div>
    </x-modal>
@endforeach

@can('archive', $asset)
    <x-modal id="archive-asset-modal" title="Archive asset" maxWidth="md">
        <div class="space-y-4">
            <p class="text-theme-sm text-gray-600 dark:text-gray-300">
                Archiving keeps the asset record for history and audit tracking, but removes it from the active inventory list.
            </p>

            <form method="POST" action="{{ route('assets.destroy', $asset) }}" class="space-y-4">
                @csrf
                @method('DELETE')

                <div class="rounded-xl border border-warning-200 bg-warning-50 px-4 py-3 text-theme-sm text-warning-700 dark:border-warning-500/20 dark:bg-warning-500/10 dark:text-warning-400">
                    This action is reversible from the asset edit screen, but the record will remain in history.
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="$dispatch('close-modal', 'archive-asset-modal')" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</button>
                    <x-button type="submit" variant="danger">Archive asset</x-button>
                </div>
            </form>
        </div>
    </x-modal>

    <x-modal id="retire-asset-modal" title="Retire asset" maxWidth="md">
        <div class="space-y-4">
            <p class="text-theme-sm text-gray-600 dark:text-gray-300">
                Retiring sets the asset to Retired status and closes any open assignment while keeping the history intact.
            </p>

            <form method="POST" action="{{ route('assets.retire', $asset) }}" class="space-y-4">
                @csrf

                <div>
                    <label for="retire_date" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Retirement date</label>
                    <input id="retire_date" type="date" name="retire_date" value="{{ now()->toDateString() }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>

                <div>
                    <label for="retire_notes" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                    <textarea id="retire_notes" name="notes" rows="3" placeholder="Reason for retirement or note for audit trail..." class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"></textarea>
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="$dispatch('close-modal', 'retire-asset-modal')" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</button>
                    <x-button type="submit" variant="warning">Confirm retire</x-button>
                </div>
            </form>
        </div>
    </x-modal>

    <x-modal id="dispose-asset-modal" title="Dispose asset" maxWidth="md">
        <div class="space-y-4">
            <p class="text-theme-sm text-gray-600 dark:text-gray-300">
                Disposing marks the asset as disposed and keeps the final status for compliance and reporting history.
            </p>

            <form method="POST" action="{{ route('assets.dispose', $asset) }}" class="space-y-4">
                @csrf

                <div>
                    <label for="dispose_date" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Disposal date</label>
                    <input id="dispose_date" type="date" name="retire_date" value="{{ now()->toDateString() }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>

                <div>
                    <label for="dispose_notes" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                    <textarea id="dispose_notes" name="notes" rows="3" placeholder="Disposal details or compliance note..." class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"></textarea>
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="$dispatch('close-modal', 'dispose-asset-modal')" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</button>
                    <x-button type="submit" variant="danger">Confirm dispose</x-button>
                </div>
            </form>
        </div>
    </x-modal>
    @endcan

<x-modal id="asset-label-modal" title="Asset label · {{ $asset->asset_id }}" maxWidth="lg">
    <div class="flex justify-center rounded-xl bg-gray-50 p-6 dark:bg-gray-800/50">
        <x-asset-label :asset="$asset" :qr-src="route('assets.qr', [$asset, 'plain' => 1])" />
    </div>
    <x-slot:footer>
        <a href="{{ route('assets.label', $asset) }}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Print view</a>
        <a href="{{ route('assets.label.pdf', $asset) }}" class="inline-flex items-center justify-center rounded-lg bg-brand-500 px-4 py-2 text-theme-sm font-medium text-white shadow-theme-xs transition-all duration-200 hover:bg-brand-600">Download PDF</a>
        <button type="button" @click="$dispatch('close-modal', 'asset-label-modal')" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Close</button>
    </x-slot:footer>
</x-modal>

@endsection
