@extends('layouts.app')

@section('title', 'Document ' . $document->document_number)

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
        <h1 class="text-2xl font-bold">{{ $document->title }}</h1>
        <p class="text-sm text-gray-500">
            <span class="px-2 py-1 text-xs rounded bg-gray-200">{{ $document->document_type }}</span>
            <span class="ml-2">{{ $document->document_number }}</span>
            · Generated {{ $document->generated_date?->format('Y-m-d') }}
            by {{ $document->generator?->name }}
        </p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('documents.download', $document) }}" class="inline-flex items-center justify-center rounded-lg bg-success-500 px-4 py-2 text-theme-sm font-medium text-white shadow-theme-xs transition-all duration-200 active:scale-[0.97] hover:bg-success-600">Download PDF</a>
        <button onclick="document.getElementById('pdf-frame').contentWindow.print()" class="inline-flex items-center justify-center rounded-lg bg-gray-600 px-4 py-2 text-theme-sm font-medium text-white transition-all duration-200 active:scale-[0.97] hover:bg-gray-700">Print</button>
        <a href="{{ $document->asset_id ? route('assets.show', $document->asset_id) : route('assets.index') }}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 transition-all duration-200 active:scale-[0.97] hover:bg-gray-50">Back</a>
    </div>
</div>

<div class="bg-white p-4 rounded-xl shadow">
    <iframe id="pdf-frame" src="{{ route('documents.download', $document) }}?inline=1" class="w-full" style="height: 80vh; border: none;"></iframe>
</div>
@endsection
