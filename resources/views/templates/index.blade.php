@extends('layouts.app')

@section('title', 'Document Templates')

@section('content')
<x-page-header title="Document Templates" subtitle="Manage FAT, BAST, and other document templates.">
    <x-slot:actions>
        <x-button variant="primary" size="sm" :href="'/templates/create'">+ New Template</x-button>
    </x-slot:actions>
</x-page-header>

@if(session('success'))
    <div class="mb-6 rounded-xl border border-success-200 bg-success-50 px-4 py-3 text-theme-sm text-success-700 dark:border-success-500/20 dark:bg-success-500/10 dark:text-success-300">
        {{ session('success') }}
    </div>
@endif

<x-card>
    @if($templates->count() > 0)
        <div class="overflow-x-auto">
            <x-table :headers="['Name', 'Type', 'Fields', 'Status', 'Actions']">
                @foreach($templates as $template)
                    <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-white/5">
                        <td class="py-3 px-4 text-theme-sm font-medium text-gray-700 dark:text-gray-300">{{ $template->name }}</td>
                        <td class="py-3 px-4">
                            <span class="inline-flex rounded-full bg-brand-50 px-2 py-1 text-theme-xs font-medium text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">{{ $template->document_type }}</span>
                        </td>
                        <td class="py-3 px-4 text-theme-sm text-gray-600 dark:text-gray-400">{{ is_array($template->template_content) ? count($template->template_content) : 0 }} fields</td>
                        <td class="py-3 px-4">
                            <button type="button" @click="$dispatch('open-modal', 'toggle-template-{{ $template->id }}')" class="inline-flex rounded-full px-2.5 py-1 text-theme-xs font-medium {{ $template->is_active ? 'bg-success-100 text-success-700 dark:bg-success-500/10 dark:text-success-300' : 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                                {{ $template->is_active ? 'Active' : 'Inactive' }}
                            </button>
                        </td>
                        <td class="py-3 px-4">
                            <button type="button" @click="$dispatch('open-modal', 'delete-template-{{ $template->id }}')" class="text-theme-sm font-medium text-error-500 hover:text-error-600">Delete</button>
                        </td>
                    </tr>
                @endforeach
            </x-table>
        </div>
    @else
        <p class="py-8 text-center text-theme-sm text-gray-400">No templates yet.</p>
    @endif
</x-card>

@foreach($templates as $template)
    <x-modal id="toggle-template-{{ $template->id }}" title="Toggle template status" maxWidth="md">
        <div class="space-y-4">
            <p class="text-theme-sm text-gray-600 dark:text-gray-300">
                Set template <span class="font-semibold text-gray-800 dark:text-white">{{ $template->name }}</span> to <span class="font-semibold">{{ $template->is_active ? 'Inactive' : 'Active' }}</span>?
            </p>

            <form method="POST" action="/templates/{{ $template->id }}/toggle" class="space-y-4">
                @csrf
                @method('PUT')

                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="$dispatch('close-modal', 'toggle-template-{{ $template->id }}')" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</button>
                    <x-button type="submit" variant="primary">Confirm</x-button>
                </div>
            </form>
        </div>
    </x-modal>

    <x-modal id="delete-template-{{ $template->id }}" title="Delete template" maxWidth="md">
        <div class="space-y-4">
            <p class="text-theme-sm text-gray-600 dark:text-gray-300">
                Delete the template <span class="font-semibold text-gray-800 dark:text-white">{{ $template->name }}</span>? This cannot be undone.
            </p>

            <form method="POST" action="/templates/{{ $template->id }}" class="space-y-4">
                @csrf
                @method('DELETE')

                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="$dispatch('close-modal', 'delete-template-{{ $template->id }}')" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</button>
                    <x-button type="submit" variant="danger">Delete</x-button>
                </div>
            </form>
        </div>
    </x-modal>
@endforeach
@endsection
