@extends('layouts.app')

@section('title', $template->name)

@section('content')
<div class="mx-auto max-w-4xl space-y-6">
    <x-page-header :title="$template->name" :subtitle="$template->document_type . ' template · ' . $fieldCount . ' fields'">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" :href="route('templates.index')">Back</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="flex flex-wrap items-center gap-2">
        <x-badge :variant="$template->is_active ? 'success' : 'gray'" :dot="true">
            {{ $template->is_active ? 'Active' : 'Inactive' }}
        </x-badge>
        <x-badge variant="brand">{{ $template->document_type }}</x-badge>
        <x-badge variant="gray">{{ $fieldCount }} fields · {{ count($groups) }} sections</x-badge>
        @if($orphans > 0)
            <x-badge variant="warning">{{ $orphans }} fields without a section</x-badge>
        @endif
        @if($template->createdBy)
            <span class="text-theme-xs text-gray-400">Created by {{ $template->createdBy->name }} · {{ $template->created_at?->diffForHumans() }}</span>
        @endif
    </div>

    @if($fieldCount === 0)
        <x-card>
            <p class="py-8 text-center text-theme-sm text-gray-400">
                This template has no fields yet.
            </p>
        </x-card>
    @else
        {{-- Section titles come from the field data itself ("A. Informasi Yang
             Menyerahkan"), so they stay in the same order as the paper form. --}}
        @foreach($groups as $section => $fields)
            <x-card>
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $section }}</h3>
                    <span class="shrink-0 text-theme-xs text-gray-400">{{ count($fields) }} fields</span>
                </div>

                <div class="overflow-x-auto">
                    <x-table :headers="['Label', 'Key', 'Type']">
                        @foreach($fields as $field)
                            <tr class="border-b border-gray-100 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/5">
                                <td class="py-2.5 px-4 text-theme-sm text-gray-700 dark:text-gray-300">
                                    {{ $field['label'] ?? $field['key'] }}
                                </td>
                                <td class="py-2.5 px-4">
                                    <code class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-theme-xs text-gray-600 dark:bg-white/10 dark:text-gray-400">{{ $field['key'] }}</code>
                                </td>
                                <td class="py-2.5 px-4">
                                    <x-badge variant="gray">{{ $field['type'] ?? 'text' }}</x-badge>
                                </td>
                            </tr>
                        @endforeach
                    </x-table>
                </div>
            </x-card>
        @endforeach
    @endif
</div>
@endsection