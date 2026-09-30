@extends('layouts.app')

@section('title', 'Documents')

@section('content')
<x-page-header title="Documents" subtitle="Every generated FAT, BAST and handover record across all assets.">
    <x-slot:actions>
        <x-button variant="secondary" size="sm" :href="'/templates'">Templates</x-button>
    </x-slot:actions>
</x-page-header>

{{--
    The Documents tab on an asset answers "what does this one asset have?".
    This answers the other half: which documents exist at all. Without it the
    only route to a document was a direct link, so anything that left the browser
    tab was effectively gone from the UI - and "show me every BAST issued this
    quarter" had no answer.
--}}
<form method="GET" action="/documents" class="mb-6 flex flex-wrap items-end gap-3">
    <div>
        <label for="q" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Search</label>
        <input type="text" id="q" name="q" value="{{ $search }}" placeholder="Document number, title or asset ID"
               class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
    </div>

    <div>
        <label for="type" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Type</label>
        <select id="type" name="type"
                class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
            <option value="">All types</option>
            @foreach($types as $available)
                <option value="{{ $available }}" {{ (string) $type === $available ? 'selected' : '' }}>{{ $available }}</option>
            @endforeach
        </select>
    </div>

    <x-button type="submit" variant="primary">Filter</x-button>

    @if($search !== '' || $type)
        <a href="/documents" class="text-theme-sm text-gray-500 hover:text-gray-700 dark:text-gray-400">Clear</a>
    @endif
</form>

<x-card>
    @if($documents->count() > 0)
        <div class="overflow-x-auto">
            <x-table :headers="['Number', 'Type', 'Title', 'Asset', 'Generated', 'By', '']">
                @foreach($documents as $document)
                    <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-white/5">
                        <td class="py-3 px-4">
                            <a href="{{ route('documents.preview', $document) }}" class="text-theme-sm font-medium text-brand-500 hover:text-brand-600">
                                {{ $document->document_number }}
                            </a>
                        </td>
                        <td class="py-3 px-4">
                            <span class="inline-flex rounded-full bg-brand-50 px-2 py-1 text-theme-xs font-medium text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
                                {{ $document->document_type }}
                            </span>
                        </td>
                        <td class="py-3 px-4 text-theme-sm text-gray-600 dark:text-gray-400">{{ $document->title }}</td>
                        <td class="py-3 px-4 text-theme-sm">
                            @if($document->asset)
                                <a href="{{ route('assets.show', $document->asset) }}" class="text-brand-500 hover:text-brand-600">
                                    {{ $document->asset->asset_id }}
                                </a>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="py-3 px-4 text-theme-sm text-gray-600 dark:text-gray-400">
                            {{ $document->generated_date?->format('d M Y') }}
                        </td>
                        <td class="py-3 px-4 text-theme-sm text-gray-600 dark:text-gray-400">
                            {{ $document->generator?->name ?? '—' }}
                        </td>
                        <td class="py-3 px-4 text-right">
                            <a href="{{ route('documents.download', $document) }}" class="text-theme-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-300">
                                Download
                            </a>
                        </td>
                    </tr>
                @endforeach
            </x-table>
        </div>

        <div class="mt-4">
            {{ $documents->links() }}
        </div>
    @else
        {{-- A generated document always belongs to an asset, so an empty list
             means the register has not had one produced yet. Saying "No documents
             found." would read as a broken filter. --}}
        <div class="py-8 text-center">
            <p class="text-theme-sm text-gray-400">
                {{ ($search !== '' || $type) ? 'No documents match this filter.' : 'No documents yet.' }}
            </p>
            @unless($search !== '' || $type)
                <p class="mt-1 text-theme-xs text-gray-400">Open an asset and use the Documents tab to generate one.</p>
            @endunless
        </div>
    @endif
</x-card>
@endsection
