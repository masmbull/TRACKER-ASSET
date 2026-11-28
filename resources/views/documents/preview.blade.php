@extends('layouts.app')

@section('title', 'Document ' . $document->document_number)

@push('styles')
<style>
    /* The preview is the moment an operator checks a printed document, so it
       arrives with a short, calm entrance instead of snapping into place. */
    .doc-preview-enter { animation: doc-preview-rise 420ms cubic-bezier(.16,1,.3,1) both; }
    .doc-preview-enter-delay { animation: doc-preview-rise 420ms 90ms cubic-bezier(.16,1,.3,1) both; }
    @keyframes doc-preview-rise {
        from { opacity: 0; transform: translateY(10px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    @media (prefers-reduced-motion: reduce) {
        .doc-preview-enter, .doc-preview-enter-delay { animation: none; }
    }
</style>
@endpush

@section('content')
{{-- The document opens as a native <dialog> the moment this page loads.

     The page exists as the "open the document" target, so arriving here should
     show the document rather than a button to reveal it. The dialog is plain
     JavaScript - no Alpine - because the Alpine version silently rendered
     nothing when the framework had not started, and a preview that does not
     appear is indistinguishable from a broken route. --}}
<div class="doc-preview-enter flex flex-wrap items-start justify-between gap-3 mb-5">
    <div class="min-w-0">
        <h1 class="truncate text-xl font-semibold text-gray-900 dark:text-white">{{ $document->title }}</h1>
        <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-theme-xs text-gray-500 dark:text-gray-400">
            <span class="rounded bg-gray-100 px-2 py-0.5 font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ $document->document_type }}</span>
            <span>{{ $document->document_number }}</span>
            <span>·</span>
            <span>{{ $document->generated_date?->format('d M Y') }}</span>
            @if($document->generator)
                <span>oleh {{ $document->generator->name }}</span>
            @endif
        </p>
    </div>
</div>

<div class="doc-preview-enter-delay flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-white p-5 shadow-theme-xs dark:border-gray-800 dark:bg-gray-900">
    <x-document-preview :document="$document" :auto-open="true">
        <x-button variant="primary"><x-icon name="documents.index" class="h-4 w-4" /> Buka Pratinjau</x-button>
    </x-document-preview>

    <a href="{{ route('documents.download', $document) }}"
       class="inline-flex items-center gap-1.5 rounded-lg bg-success-500 px-3.5 py-2 text-theme-xs font-semibold text-white transition hover:bg-success-600">
        <x-icon name="download" class="h-4 w-4" /> Download PDF
    </a>

    <a href="{{ route('documents.edit', $document) }}"
       class="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3.5 py-2 text-theme-xs font-semibold text-white transition hover:bg-brand-700">
        <x-icon name="templates.index" class="h-4 w-4" /> Edit &amp; regenerate
    </a>

    <a href="{{ $document->asset_id ? route('assets.show', $document->asset_id) : route('documents.index') }}"
       class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-theme-xs font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
        Back
    </a>
</div>
@endsection
