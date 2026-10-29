@extends('layouts.app')

@section('title', 'Document ' . $document->document_number)

@section('content')
{{--
    The document opens as a modal over this page, not as an embedded viewer.

    An inline viewer was still a page you had to navigate to and leave, and the
    operator asked for a popup: click the document, read it, close it, stay where
    you were. The page underneath stays mounted (the header, the buttons), and the
    PDF renders in the modal's own canvas through PDF.js - so no browser PDF
    toolbar, which was the original complaint.

    The modal opens on load because this route IS the "open the document" target,
    so arriving here should show the document rather than a button to reveal it.
--}}
<div class="flex flex-wrap items-start justify-between gap-3 mb-5">
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

<div class="rounded-xl border border-gray-200 bg-white p-8 text-center shadow-theme-xs dark:border-gray-800 dark:bg-gray-900">
    <p class="text-theme-sm text-gray-500 dark:text-gray-400">Pratinjau dokumen terbuka di jendela popup.</p>

    <x-document-preview :document="$document" :auto-open="true">
        <button type="button" class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-brand-500 px-4 py-2.5 text-theme-sm font-semibold text-white transition hover:bg-brand-600 active:scale-[0.98]">
            Buka Pratinjau
        </button>
    </x-document-preview>

    <div class="mt-4 flex flex-wrap items-center justify-center gap-2">
        <a href="{{ route('documents.download', $document) }}"
           class="inline-flex items-center gap-1.5 rounded-lg bg-success-500 px-3.5 py-2 text-theme-xs font-semibold text-white transition hover:bg-success-600">
            <x-icon name="download" class="h-4 w-4" /> Download PDF
        </a>
        <a href="{{ $document->asset_id ? route('assets.show', $document->asset_id) : route('documents.index') }}"
           class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-theme-xs font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
            Back
        </a>
    </div>
</div>
@endsection
