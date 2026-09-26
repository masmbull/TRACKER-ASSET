@extends('layouts.app')

@section('title', 'Import Assets')

@section('content')
<x-page-header title="Import Assets from Excel" subtitle="Upload and validate inventory data before confirming the import.">
    <x-slot:actions>
        <x-button variant="secondary" size="sm" :href="route('assets.index')">Back to Assets</x-button>
    </x-slot:actions>
</x-page-header>

@if($result)
    <x-card class="mb-6" title="Import result — {{ $result['file'] ?? '' }}">
        <div class="mb-4 grid grid-cols-2 gap-4 md:grid-cols-4">
            <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">Created</p>
                <p class="text-title-sm font-bold text-success-600">{{ $result['created'] }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">Skipped</p>
                <p class="text-title-sm font-bold text-gray-700 dark:text-gray-300">{{ $result['skipped'] }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">Assignment rows</p>
                <p class="text-title-sm font-bold text-gray-700 dark:text-gray-300">{{ $result['assignment_rows'] }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">Placeholder users</p>
                <p class="text-title-sm font-bold text-gray-700 dark:text-gray-300">{{ $result['placeholder_users'] }}</p>
            </div>
        </div>

        @if(!empty($result['errors']))
            <p class="mb-2 text-theme-sm font-medium text-error-600">Row errors</p>
            <ul class="ml-6 list-disc text-theme-sm text-error-600">
                @foreach($result['errors'] as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif
    </x-card>
@endif

<x-card class="mb-6" title="1. Upload file">
    <form method="POST" action="{{ route('assets.import.analyze') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
        @csrf
        <div class="flex-1 min-w-[220px]">
            <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Excel file (.xlsx, .xls, .csv) *</label>
            <input type="file" name="file" accept=".xlsx,.xls,.csv" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 file:mr-3 file:rounded file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-600 hover:file:bg-brand-100 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
        </div>
        <x-button type="submit" variant="primary">Analyse / Preview</x-button>
        @if($stagedName)
            <span class="text-theme-sm text-gray-500 dark:text-gray-400">Staged file: {{ $stagedName }}</span>
        @endif
    </form>

    @error('file')
        <p class="mt-2 text-theme-sm text-error-500">{{ $message }}</p>
    @enderror

    <p class="mt-3 text-theme-xs text-gray-500 dark:text-gray-400">
        Nothing is written to the database until you confirm the preview. Existing assets are never modified or deleted — duplicates are skipped.
    </p>
</x-card>

@if(isset($analysis) && $analysis)
    <x-import-analysis-preview :analysis="$analysis" />
@endif
@endsection