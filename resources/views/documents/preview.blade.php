@extends('layouts.app')

@section('title', 'Document ' . $document->document_number)

@section('content')
{{--
    Document detail, opened as the target of a preview click.

    This page used to be a header with an <iframe> pointed at the download route.
    The browser's PDF plugin then drew its own grey toolbar - a page counter, a
    download button, and a filename - across the top, so a document IT had just
    generated looked like something being downloaded. PDF.js renders into a canvas
    we own instead: no plugin chrome, the same output in every browser, and the
    header stays the page's own.

    The modal from the documents list uses the same component, so both entry points
    look identical.
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

    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('documents.download', $document) }}"
           class="inline-flex items-center gap-1.5 rounded-lg bg-success-500 px-3.5 py-2 text-theme-xs font-semibold text-white transition hover:bg-success-600 active:scale-[0.97]">
            <x-icon name="download" class="h-4 w-4" /> Download PDF
        </a>
        <button type="button" id="pdf-print"
                class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-theme-xs font-semibold text-gray-700 transition hover:bg-gray-50 active:scale-[0.97] dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
            Print
        </button>
        <a href="{{ $document->asset_id ? route('assets.show', $document->asset_id) : route('documents.index') }}"
           class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-theme-xs font-semibold text-gray-700 transition hover:bg-gray-50 active:scale-[0.97] dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
            Back
        </a>
    </div>
</div>

<x-document-viewer :document="$document" />
@endsection
