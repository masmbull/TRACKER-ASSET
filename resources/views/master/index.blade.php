@extends('layouts.app')

@section('title', $config['label'] . ' — Master Data')

@section('content')
<x-page-header :title="$config['label']" subtitle="Master Data">
    <x-slot:actions>
        <x-button variant="primary" size="sm" :href="route('master.create', $type)">+ Add</x-button>
    </x-slot:actions>
</x-page-header>

<x-card class="mb-6">
    <div class="flex flex-wrap items-center gap-2 text-theme-sm">
        <span class="text-gray-500 dark:text-gray-400">Master:</span>
        @foreach(['companies' => 'Companies', 'locations' => 'Locations', 'categories' => 'Categories', 'statuses' => 'Statuses'] as $key => $label)
            <a href="{{ route('master.index', $key) }}" class="rounded-lg px-3 py-1.5 font-medium transition {{ $type === $key ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700' }}">{{ $label }}</a>
        @endforeach
    </div>
</x-card>

<x-card>
    <form method="GET" action="{{ route('master.index', $type) }}" class="flex flex-col gap-3 md:flex-row md:items-end">
        <div class="flex-1">
            <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Search name</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search name..." class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
        </div>
        <x-button type="submit" variant="secondary">Search</x-button>
    </form>

    @if($records->count() > 0)
        <div class="mt-6 overflow-x-auto">
            <x-table :headers="array_merge($config['columns'], ['Used By', 'Actions'])">
                @foreach($records as $record)
                    <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-white/5">
                        @foreach($config['columns'] as $column)
                            <td class="py-3 px-4 text-theme-sm text-gray-700 dark:text-gray-300">{{ $record->$column }}</td>
                        @endforeach
                        <td class="py-3 px-4 text-theme-sm text-gray-600 dark:text-gray-400">{{ $record->usage_total }}</td>
                        <td class="py-3 px-4 whitespace-nowrap">
                            <div class="flex items-center gap-3">
                                <a href="{{ route('master.edit', [$type, $record->id]) }}" class="text-theme-sm font-medium text-warning-500 hover:text-warning-600">Edit</a>
                                @if($record->usage_total === 0)
                                    <button type="button" @click="$dispatch('open-modal', 'delete-master-{{ $record->id }}')" class="text-theme-sm font-medium text-error-500 hover:text-error-600">Delete</button>
                                @else
                                    <span class="text-theme-sm text-gray-400" title="Still referenced — deactivate instead">Delete</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-table>
        </div>

        @if($records->hasPages())
            <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-800">
                {{ $records->links('components.pagination') }}
            </div>
        @endif
    @else
        <p class="mt-6 rounded-xl bg-gray-50 py-8 text-center text-theme-sm text-gray-400 dark:bg-gray-900">No records found.</p>
    @endif
</x-card>

@foreach($records as $record)
    @if($record->usage_total === 0)
        <x-modal id="delete-master-{{ $record->id }}" title="Delete record" maxWidth="md">
            <div class="space-y-4">
                <p class="text-theme-sm text-gray-600 dark:text-gray-300">
                    Delete <span class="font-semibold text-gray-800 dark:text-white">{{ $record->{ $config['columns'][0] ?? 'name' } }}</span> from master data?
                </p>

                <form method="POST" action="{{ route('master.destroy', [$type, $record->id]) }}" class="space-y-4">
                    @csrf
                    @method('DELETE')

                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" @click="$dispatch('close-modal', 'delete-master-{{ $record->id }}')" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</button>
                        <x-button type="submit" variant="danger">Delete</x-button>
                    </div>
                </form>
            </div>
        </x-modal>
    @endif
@endforeach
@endsection
