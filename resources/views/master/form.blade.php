@extends('layouts.app')

@section('title', ($record ? 'Edit' : 'New') . ' ' . $config['singular'])

@section('content')
<div class="mx-auto max-w-4xl space-y-6">
    <x-page-header :title="($record ? 'Edit' : 'New') . ' ' . $config['singular']" subtitle="Manage {{ strtolower($config['label']) }} configuration and values.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" :href="route('master.index', $type)">Back to List</x-button>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ $record ? route('master.update', [$type, $record->id]) : route('master.store', $type) }}" class="space-y-6">
        @csrf
        @if($record)
            @method('PUT')
        @endif

        <x-card title="{{ $config['label'] }} Details">
            <div class="space-y-4">
                @foreach($config['fields'] as $field => $definition)
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">{{ $definition['label'] }} {{ ($definition['required'] ?? false) ? '*' : '' }}</label>
                        @if(($definition['type'] ?? 'text') === 'select')
                            <select name="{{ $field }}" {{ ($definition['required'] ?? false) ? 'required' : '' }} class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                @foreach($definition['options'] as $value => $label)
                                    <option value="{{ $value }}" {{ (string) old($field, $record?->$field ?? '') === (string) $value ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        @elseif(($definition['type'] ?? 'text') === 'textarea')
                            <textarea name="{{ $field }}" class="min-h-[120px] w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">{{ old($field, $record?->$field ?? '') }}</textarea>
                        @else
                            <input type="text" name="{{ $field }}" {{ ($definition['required'] ?? false) ? 'required' : '' }} value="{{ old($field, $record?->$field ?? '') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        @endif
                        @error($field)
                            <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach
            </div>
        </x-card>

        <div class="flex justify-end gap-3">
            <a href="{{ route('master.index', $type) }}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</a>
            <x-button type="submit" variant="primary">{{ $record ? 'Update' : 'Create' }}</x-button>
        </div>
    </form>
</div>
@endsection
