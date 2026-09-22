@extends('layouts.app')

@section('title', 'New Template')

@section('content')
<h1 class="text-2xl font-bold mb-6">New Template</h1>

<form method="POST" action="/templates" class="bg-white p-6 rounded shadow space-y-4">
    @csrf

    <div>
        <label class="block text-sm font-medium">Name *</label>
        <input type="text" name="name" required class="w-full border rounded px-3 py-2" placeholder="FAT Form A">
    </div>

    <div>
        <label class="block text-sm font-medium">Document Type *</label>
        <input type="text" name="document_type" required class="w-full border rounded px-3 py-2" placeholder="FAT">
    </div>

    <div>
        <label class="block text-sm font-medium">Template Fields (JSON)</label>
        <textarea name="template_content" required class="w-full border rounded px-3 py-2 font-mono text-sm" placeholder='{"field1": {"label": "Asset ID", "type": "text"}}'></textarea>
        <p class="text-xs text-gray-500 mt-1">JSON format: each key is a field identifier, each value has label and type.</p>
    </div>

    <div class="flex gap-2 pt-4">
        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded">Create Template</button>
        <a href="/templates" class="bg-gray-300 text-gray-700 px-6 py-2 rounded">Cancel</a>
    </div>
</form>
@endsection
