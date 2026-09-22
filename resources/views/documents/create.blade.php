@extends('layouts.app')

@section('title', 'Add Document')

@section('content')
<h1 class="text-2xl font-bold mb-6">Add Document - {{ $asset->asset_id }}</h1>

<div class="bg-white p-6 rounded shadow mb-6">
    <h2 class="text-lg font-semibold mb-4">Asset Info</h2>
    <dl class="grid grid-cols-2 gap-2">
        <dt class="font-medium">Asset ID</dt><dd>{{ $asset->asset_id }}</dd>
        <dt class="font-medium">Company</dt><dd>{{ $asset->company?->name }}</dd>
        <dt class="font-medium">Category</dt><dd>{{ $asset->category?->name }}</dd>
        <dt class="font-medium">Status</dt><dd><span class="px-2 py-1 text-xs rounded bg-gray-200">{{ $asset->status?->name }}</span></dd>
    </dl>
</div>

<form method="POST" action="/assets/{{ $asset->id }}/documents" class="bg-white p-6 rounded shadow space-y-4">
    @csrf

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium">Document Type *</label>
            <input type="text" name="document_type" required value="{{ old('document_type') }}" class="w-full border rounded px-3 py-2" placeholder="FAT, BAST, Transfer, etc.">
        </div>
        <div>
            <label class="block text-sm font-medium">Document Number *</label>
            <input type="text" name="document_number" required value="{{ old('document_number') }}" class="w-full border rounded px-3 py-2">
        </div>
    </div>

    <div>
        <label class="block text-sm font-medium">Title *</label>
        <input type="text" name="title" required value="{{ old('title') }}" class="w-full border rounded px-3 py-2">
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium">Template</label>
            <select name="template_id" class="w-full border rounded px-3 py-2">
                <option value="">None</option>
                @foreach($templates as $template)
                <option value="{{ $template->id }}">{{ $template->name }} ({{ $template->document_type }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium">Generated Date</label>
            <input type="date" name="generated_date" value="{{ old('generated_date', now()->toDateString()) }}" class="w-full border rounded px-3 py-2">
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium">File Path *</label>
            <input type="text" name="file_path" required value="{{ old('file_path') }}" class="w-full border rounded px-3 py-2" placeholder="/storage/docs/...">
        </div>
        <div>
            <label class="block text-sm font-medium">File Type *</label>
            <input type="text" name="file_type" required value="{{ old('file_type') }}" class="w-full border rounded px-3 py-2" placeholder="pdf, docx, etc.">
        </div>
    </div>

    <div>
        <label class="block text-sm font-medium">Status</label>
        <select name="status" class="w-full border rounded px-3 py-2">
            <option value="draft">Draft</option>
            <option value="final">Final</option>
        </select>
    </div>

    <div class="flex gap-2 pt-4">
        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded">Create Document</button>
        <a href="/assets/{{ $asset->id }}" class="bg-gray-300 text-gray-700 px-6 py-2 rounded">Cancel</a>
    </div>
</form>
@endsection
