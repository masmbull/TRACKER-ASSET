@extends('layouts.app')

@section('title', $template->name)

@section('content')
<div class="mx-auto max-w-5xl space-y-6">
    <x-page-header :title="$template->name" :subtitle="$template->document_type . ' template · ' . $fieldCount . ' fields · ' . count($groups) . ' sections'">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" :href="route('templates.index')">Back to Templates</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="flex flex-wrap items-center gap-2">
        <x-badge :variant="$template->is_active ? 'success' : 'gray'" :dot="true">
            {{ $template->is_active ? 'Active' : 'Inactive' }}
        </x-badge>
        <x-badge variant="brand">{{ $template->document_type }}</x-badge>
        @if($orphans > 0)
            <x-badge variant="warning">{{ $orphans }} fields without a section</x-badge>
        @endif
        @if($template->createdBy)
            <span class="text-theme-xs text-gray-400">Created by {{ $template->createdBy->name }} · {{ $template->created_at?->diffForHumans() }}</span>
        @endif
    </div>

    @if($fieldCount === 0)
        <x-card>
            <p class="py-8 text-center text-theme-sm text-gray-400">This template has no fields yet.</p>
        </x-card>
    @else
        <div class="rounded-2xl border border-brand-100 bg-brand-50 px-4 py-3 dark:border-brand-500/20 dark:bg-brand-500/10">
            <p class="text-theme-sm font-medium text-brand-700 dark:text-brand-300">
                This is how the generated document is laid out. Values are filled automatically from the asset register when a document is generated.
            </p>
        </div>

        {{-- Rendered as a form, not a schema dump. The paper page is what the
             reader is judging: a table of key/type pairs answers "what is stored"
             and nothing about "what will this look like when someone signs it".
             Each field shows its label, the sample box a value lands in, and the
             raw key underneath for the people who wire the schema up. --}}
        @foreach($groups as $section => $fields)
            <x-card padding="p-0">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-3 dark:border-gray-800">
                    <h3 class="text-theme-base font-semibold text-gray-800 dark:text-white/90">{{ $section }}</h3>
                    <span class="text-theme-xs text-gray-400">{{ count($fields) }} {{ Str::plural('field', count($fields)) }}</span>
                </div>

                <dl class="grid grid-cols-1 gap-x-8 gap-y-4 p-5 sm:grid-cols-2">
                    @foreach($fields as $field)
                        @php
                            $type = $field['type'] ?? 'text';
                            $wide = in_array($type, ['textarea'], true);
                        @endphp
                        <div class="{{ $wide ? 'sm:col-span-2' : '' }}">
                            <dt class="mb-1 flex items-center gap-2">
                                <span class="text-theme-sm font-medium text-gray-700 dark:text-gray-300">{{ $field['label'] ?? $field['key'] }}</span>
                                @if($type !== 'text')
                                    <x-badge variant="gray">{{ $type }}</x-badge>
                                @endif
                            </dt>
                            <dd>
                                @if($type === 'pass_fail_na')
                                    <div class="flex gap-2">
                                        @foreach(['PASS', 'FAIL', 'N/A'] as $opt)
                                            <span class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-2.5 py-1.5 text-theme-xs text-gray-500 dark:border-gray-700">
                                                <span class="h-3 w-3 rounded-full border border-gray-300 dark:border-gray-600"></span>{{ $opt }}
                                            </span>
                                        @endforeach
                                    </div>
                                @elseif($type === 'ada_tidak')
                                    <div class="flex gap-2">
                                        @foreach(['Ada', 'Tidak Ada'] as $opt)
                                            <span class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-2.5 py-1.5 text-theme-xs text-gray-500 dark:border-gray-700">
                                                <span class="h-3 w-3 rounded-full border border-gray-300 dark:border-gray-600"></span>{{ $opt }}
                                            </span>
                                        @endforeach
                                    </div>
                                @elseif($wide)
                                    <div class="h-14 rounded-lg border border-dashed border-gray-300 bg-gray-50 dark:border-gray-700 dark:bg-gray-900/40"></div>
                                @else
                                    <div class="flex h-9 items-center rounded-lg border border-dashed border-gray-300 bg-gray-50 px-3 text-theme-xs text-gray-400 dark:border-gray-700 dark:bg-gray-900/40">
                                        auto-filled
                                    </div>
                                @endif
                                <code class="mt-1.5 inline-block rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[10px] text-gray-500 dark:bg-white/10 dark:text-gray-400">{{ $field['key'] }}</code>
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </x-card>
        @endforeach
    @endif
</div>
@endsection