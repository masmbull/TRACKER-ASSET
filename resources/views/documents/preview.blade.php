@extends('layouts.app')

@section('title', 'Document ' . $document->document_number)

@section('content')
<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-2xl font-bold">{{ $document->title }}</h1>
        <p class="text-sm text-gray-500">
            <span class="px-2 py-1 text-xs rounded bg-gray-200">{{ $document->document_type }}</span>
            <span class="ml-2">{{ $document->document_number }}</span>
            · Generated {{ $document->generated_date?->format('Y-m-d') }}
            by {{ $document->generator?->name }}
        </p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('documents.download', $document) }}" class="bg-green-600 text-white px-4 py-2 rounded">Download PDF</a>
        <button onclick="document.getElementById('pdf-frame').contentWindow.print()" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">Print</button>
        <a href="{{ $document->asset_id ? route('assets.show', $document->asset_id) : route('assets.index') }}" class="bg-gray-300 text-gray-700 px-4 py-2 rounded">Back</a>
    </div>
</div>

<div class="bg-white p-4 rounded shadow">
    <iframe id="pdf-frame" src="{{ route('documents.download', $document) }}?inline=1" class="w-full" style="height: 80vh; border: none;"></iframe>
</div>
@endsection
