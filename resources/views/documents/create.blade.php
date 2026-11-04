@extends('layouts.app')

@section('title', 'Add Document')

@push('styles')
<link href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css" rel="stylesheet" />
@endpush

@section('content')
<div class="mx-auto max-w-5xl space-y-6">
    <x-page-header :title="'Add Document - ' . $asset->asset_id" subtitle="Create a new document record for this asset and attach the related template metadata.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" :href="'/assets/' . $asset->id">Back to Asset</x-button>
        </x-slot:actions>
    </x-page-header>

    <x-card title="Asset Info">
        <dl class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <div>
                <dt class="text-theme-xs font-medium uppercase tracking-wide text-gray-500">Asset ID</dt>
                <dd class="mt-1 text-theme-sm text-gray-800 dark:text-gray-200">{{ $asset->asset_id }}</dd>
            </div>
            <div>
                <dt class="text-theme-xs font-medium uppercase tracking-wide text-gray-500">Company</dt>
                <dd class="mt-1 text-theme-sm text-gray-800 dark:text-gray-200">{{ $asset->company?->name }}</dd>
            </div>
            <div>
                <dt class="text-theme-xs font-medium uppercase tracking-wide text-gray-500">Category</dt>
                <dd class="mt-1 text-theme-sm text-gray-800 dark:text-gray-200">{{ $asset->category?->name }}</dd>
            </div>
            <div>
                <dt class="text-theme-xs font-medium uppercase tracking-wide text-gray-500">Status</dt>
                <dd class="mt-1"><span class="inline-flex rounded-full bg-gray-100 px-2.5 py-1 text-theme-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ $asset->status?->name }}</span></dd>
            </div>
        </dl>
    </x-card>

    <form method="POST" action="/assets/{{ $asset->id }}/documents" class="space-y-6">
        @csrf

        <x-card title="Document Details">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Document Type *</label>
                    <input type="text" name="document_type" required value="{{ old('document_type') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 @error('document_type') border-error-400 @enderror" placeholder="FAT, BAST, Transfer, etc.">
                    @error('document_type') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Document Number *</label>
                    <input type="text" name="document_number" required value="{{ old('document_number') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 @error('document_number') border-error-400 @enderror">
                    {{-- unique on documents: a collision here is a routine mistake
                         (every FAT/BAST carries a number) and nothing on the form
                         hints that the number, not the asset, was the problem. --}}
                    @error('document_number') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Title *</label>
                    <input type="text" name="title" required value="{{ old('title') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 @error('title') border-error-400 @enderror">
                    @error('title') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Template</label>
                    <select name="template_id" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        <option value="">None</option>
                        @foreach($templates as $template)
                            <option value="{{ $template->id }}" {{ (string) old('template_id') === (string) $template->id ? 'selected' : '' }}>{{ $template->name }} ({{ $template->document_type }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Generated Date</label>
                    <input type="date" name="generated_date" value="{{ old('generated_date', now()->toDateString()) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">File Path *</label>
                    <input type="text" name="file_path" required value="{{ old('file_path') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 @error('file_path') border-error-400 @enderror" placeholder="/storage/docs/...">
                    @error('file_path') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">File Type *</label>
                    <input type="text" name="file_type" required value="{{ old('file_type') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 @error('file_type') border-error-400 @enderror" placeholder="pdf, docx, etc.">
                    @error('file_type') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                    <select name="status" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        <option value="draft">Draft</option>
                        <option value="final">Final</option>
                    </select>
                </div>
            </div>
        </x-card>

        <div class="flex justify-end gap-3">
            <a href="/assets/{{ $asset->id }}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</a>
            <x-button type="submit" variant="primary">Create Document</x-button>
        </div>
    </form>
</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof window.flatpickr === 'function') {
            window.flatpickr('input[type="date"]', {
                dateFormat: 'Y-m-d',
            });
        }
    });
</script>
@endpush

@endsection
