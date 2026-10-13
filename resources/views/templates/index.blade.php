@extends('layouts.app')

@section('title', 'Document Templates')

@section('content')
<x-page-header title="Document Templates" subtitle="Manage FAT, BAST, and other document templates.">
    <x-slot:actions>
        <x-button variant="primary" size="sm" :href="'/templates/create'">+ New Template</x-button>
    </x-slot:actions>
</x-page-header>

@if(session('success'))
    {{-- Rendered as a toast by the layout instead: the old banner duplicated it
         and pushed the table down on every redirect back here. --}}
    <div data-flash-toast="{{ session('success') }}" data-flash-type="success" class="hidden"></div>
@endif

<x-card>
    @if($templates->count() > 0)
        <div class="overflow-x-auto">
            <x-table :headers="['Name', 'Type', 'Fields', 'Status', 'Actions']">
                @foreach($templates as $template)
                    <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-white/5">
                        <td class="py-3 px-4">
                            <a href="{{ route('templates.show', $template) }}" class="text-theme-sm font-medium text-brand-500 hover:text-brand-600">{{ $template->name }}</a>
                            <p class="text-theme-xs text-gray-400">{{ $template->created_at?->diffForHumans() }}</p>
                        </td>
                        <td class="py-3 px-4">
                            <x-badge variant="brand">{{ $template->document_type }}</x-badge>
                        </td>
                        <td class="py-3 px-4 text-theme-sm text-gray-600 dark:text-gray-400">{{ is_array($template->template_content) ? count($template->template_content) : 0 }} fields</td>
                        <td class="py-3 px-4">
                            <button type="button" @click="$dispatch('open-modal', 'toggle-template-{{ $template->id }}')" class="inline-flex items-center justify-center gap-1.5 rounded-full px-2.5 py-1 text-theme-xs font-medium transition-all duration-200 active:scale-95 {{ $template->is_active ? 'bg-success-100 text-success-700 hover:bg-success-200 dark:bg-success-500/10 dark:text-success-300' : 'bg-gray-200 text-gray-600 hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300' }}">
                                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>{{ $template->is_active ? 'Active' : 'Inactive' }}
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
