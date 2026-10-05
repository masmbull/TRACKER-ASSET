@extends('layouts.app')

@section('title', 'New Template')

@section('content')
<div class="mx-auto max-w-4xl space-y-6">
    <x-page-header title="New Template" subtitle="Create a reusable document template and map the field schema for generation.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" href="/templates">Back to Templates</x-button>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="/templates" class="space-y-6">
        @csrf

        <x-card title="Template Details">
            <div class="space-y-4">
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Name *</label>
                    <input type="text" name="name" required value="{{ old('name') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 @error('name') border-error-400 @enderror" placeholder="FAT Form A">
                    @error('name') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Document Type *</label>
                    <input type="text" name="document_type" required value="{{ old('document_type') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 @error('document_type') border-error-400 @enderror" placeholder="FAT">
                    @error('document_type') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Template Fields (JSON)</label>
                    {{-- The controller writes a specific complaint for this field
                         (TemplateController::jsonComplaint names the offending
                         character), which is the single most useful message on the
                         form - and it was being thrown at the layout banner while
                         this textarea, the only control it refers to, showed no
                         red border and no text of its own. --}}
                    <textarea name="template_content" required class="min-h-[180px] w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 font-mono text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 @error('template_content') border-error-400 @enderror" placeholder='{"field1": {"label": "Asset ID", "type": "text"}}'>{{ old('template_content') }}</textarea>
                    @error('template_content') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
                    <p class="mt-2 text-theme-xs text-gray-500">
                        JSON format: each key is a field identifier, each value has label and type.
                        {{-- This is the field most worth keeping. Everything else on
                             this form is a name and a type code, but this is a
                             hand-written schema, and a stray comma used to throw the
                             whole thing away with no trace of what had been in it. --}}
                    </p>
                </div>
            </div>
        </x-card>

        <div class="flex justify-end gap-3">
            <a href="/templates" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</a>
            <x-button type="submit" variant="primary">Create Template</x-button>
        </div>
    </form>
</div>
@endsection
