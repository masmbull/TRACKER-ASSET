@extends('layouts.app')

@section('title', $config['label'] . ' — Master Data')

@section('content')
@php
    $tabs = ['companies' => 'Companies', 'locations' => 'Locations', 'categories' => 'Categories', 'statuses' => 'Statuses'];
@endphp

<x-page-header :title="$config['label']" subtitle="Master Data">
    <x-slot:actions>
        <x-button variant="primary" size="sm" :href="route('master.create', $type)">+ Add</x-button>
    </x-slot:actions>
</x-page-header>

<x-card class="mb-6">
    <div class="flex flex-wrap items-center gap-2 text-theme-sm"
         x-data="masterTabs('{{ $type }}')">
        <span class="text-gray-500 dark:text-gray-400">Master:</span>
        @foreach($tabs as $key => $label)
            <a href="{{ route('master.index', $key) }}"
               @click.prevent="go('{{ $key }}')"
               :class="current === '{{ $key }}'
                   ? 'bg-brand-600 text-white'
                   : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700'"
               class="rounded-lg px-3 py-1.5 font-medium transition">{{ $label }}</a>
        @endforeach>
        <span x-show="loading" x-cloak class="ml-1 text-xs text-gray-400">Loading…</span>
    </div>
</x-card>

<x-card>
    <div x-ref="panel">@include('master._panel')</div>
</x-card>
@endsection

@push('scripts')
<script>
    // The four master lists are the same table with a different model. Fetching
    // the panel fragment keeps the page, the scroll position and the search box
    // alive, which is what "no redirect, no loading screen" means in practice.
    document.addEventListener('alpine:init', () => {
        Alpine.data('masterTabs', (initial) => ({
            current: initial,
            loading: false,

            async go(key) {
                if (key === this.current) {
                    return;
                }

                this.loading = true;

                try {
                    const response = await fetch(route('master.index', key), {
                        headers: { 'X-Master-Fragment': '1' },
                        credentials: 'same-origin',
                    });

                    if (!response.ok) {
                        throw new Error('Failed to load ' + key);
                    }

                    const panel = this.$refs.panel;
                    panel.innerHTML = await response.text();

                    // The fragment carries its own Alpine nodes (delete dialogs),
                    // and innerHTML does not initialise them.
                    Alpine.initTree(panel);
                    window.initLiveFilters?.(panel);

                    this.current = key;
                    window.history.replaceState(null, '', route('master.index', key));
                } catch (error) {
                    window.showToast?.(error.message || 'Could not switch tab.', 'error');
                } finally {
                    this.loading = false;
                }
            },
        }));
    });

    // route() helper, because the tabs are the only place in this view that
    // needs a URL outside Blade.
    function route(name, key) {
        const map = {
            'companies': @json(route('master.index', 'companies')),
            'locations': @json(route('master.index', 'locations')),
            'categories': @json(route('master.index', 'categories')),
            'statuses': @json(route('master.index', 'statuses')),
        };

        return map[key] || @json(route('master.index', $type));
    }
</script>
@endpush