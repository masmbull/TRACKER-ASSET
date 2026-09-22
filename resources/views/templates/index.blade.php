@extends('layouts.app')

@section('title', 'Document Templates')

@section('content')
<h1 class="text-2xl font-bold mb-6">Document Templates</h1>

<div class="flex justify-between items-center mb-4">
    <p class="text-gray-600">Manage FAT, BAST, and other document templates.</p>
    <a href="/templates/create" class="bg-blue-600 text-white px-4 py-2 rounded">+ New Template</a>
</div>

@if(session('success'))
<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">{{ session('success') }}</div>
@endif

@if($templates->count() > 0)
<table class="min-w-full bg-white shadow rounded">
    <thead>
        <tr class="border-b">
            <th class="text-left py-3 px-4">Name</th>
            <th class="text-left py-3 px-4">Type</th>
            <th class="text-left py-3 px-4">Fields</th>
            <th class="text-left py-3 px-4">Status</th>
            <th class="text-left py-3 px-4">Actions</th>
        </tr>
    </thead>
    <tbody>
        @foreach($templates as $template)
        <tr class="border-b hover:bg-gray-50">
            <td class="py-3 px-4">{{ $template->name }}</td>
            <td class="py-3 px-4">
                <span class="px-2 py-1 text-xs rounded bg-blue-100">{{ $template->document_type }}</span>
            </td>
            <td class="py-3 px-4">{{ count($template->template_content) }} fields</td>
<td class="py-3 px-4">
                    <form method="POST" action="/templates/{{ $template->id }}/toggle" class="inline" onsubmit="return confirm('Toggle status?')">
                        @csrf
                        @method('PUT')
                        <button type="submit" class="px-2 py-1 text-xs rounded {{ $template->is_active ? 'bg-green-200' : 'bg-gray-200' }}">
                            {{ $template->is_active ? 'Active' : 'Inactive' }}
                        </button>
                    </form>
                </td>
            <td class="py-3 px-4">
                <form method="POST" action="/templates/{{ $template->id }}" class="inline" onsubmit="return confirm('Delete this template?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-red-600 text-sm">Delete</button>
                </form>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
@else
<p class="bg-white p-6 rounded shadow">No templates yet.</p>
@endif
@endsection
