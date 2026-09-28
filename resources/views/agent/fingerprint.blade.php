@extends('layouts.app')

@section('title', $token->name.' — Inventory')

@section('content')
@php
    $fingerprint = $token->fingerprint;
    $telemetry = $token->telemetry;
    $findings = $fingerprint?->findings() ?? [];
@endphp

<x-page-header :title="$token->name" subtitle="Deep inventory — security posture, licensing and installed software.">
    <x-slot:actions>
        <div class="flex flex-wrap items-center gap-2">
            <form method="POST" action="{{ route('agent.scan', $token) }}">
                @csrf
                <x-button type="submit" variant="secondary" size="sm">Scan Now</x-button>
            </form>
            <x-button href="{{ route('agent.fleet') }}" variant="ghost" size="sm">Back to Live Fleet</x-button>
        </div>
    </x-slot:actions>
</x-page-header>

@if(! $fingerprint)
    <x-card>
        <p class="text-theme-sm text-gray-600 dark:text-gray-300">
            This device has not reported a deep inventory yet. The agent sends one on its first
            poll after install and then once a day, so the first report can take a moment after
            the machine comes online.
        </p>
    </x-card>
@else
    {{-- Findings first: an operator opening this page is usually looking for what is
         wrong, not for the full spec sheet. --}}
    @if(count($findings) > 0)
        <x-card class="mb-6">
            <p class="text-theme-sm font-semibold text-gray-800 dark:text-white">
                {{ count($findings) }} {{ count($findings) === 1 ? 'issue needs' : 'issues need' }} attention
            </p>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach($findings as $finding)
                    <x-badge :variant="$finding['severity'] === 'danger' ? 'danger' : 'warning'">
                        {{ $finding['label'] }}
                    </x-badge>
                @endforeach
            </div>
        </x-card>
    @else
        <x-card class="mb-6">
            <div class="flex flex-wrap items-center gap-2">
                <x-badge variant="success">No security findings</x-badge>
                <span class="text-theme-xs text-gray-500 dark:text-gray-400">
                    Last collected {{ $fingerprint->collected_at?->diffForHumans() }}.
                </span>
            </div>
        </x-card>
    @endif
    {{-- No spec sheet here on purpose.
         This page used to repeat Security and System & licensing in full, but the
         fleet detail modal already shows every one of those fields - the two
         layouts were maintained separately and had already drifted, which is how
         "Firewall profiles off", "BitLocker method" and "Local admins" ended up
         visible in only one of them. Those three now live in the modal, so this
         page keeps the readings the modal cannot show: the full software list,
         with search and paging. --}}
    <x-card>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-theme-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                Installed software
            </p>
            <x-badge variant="brand">
                {{ $search !== '' ? $software->total().' of '.$softwareTotal : $softwareTotal }} programs
            </x-badge>
        </div>

        {{-- Filtering and paging happen on the server. The old version loaded
             every row into the page and hid them with Alpine, so a 400-program
             machine shipped 400 rows to the browser to display 60 of them: slow
             to load, impossible to page through, and the browser could not reach
             a program past the first screen. --}}
        <form method="GET" class="mt-4 flex flex-wrap items-center gap-2">
            <input type="search" name="q" value="{{ $search }}"
                   placeholder="Filter by name, publisher or version…"
                   class="min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-theme-sm text-gray-800 focus:border-brand-500 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
            <x-button type="submit" variant="secondary" size="sm">Search</x-button>
            @if($search !== '')
                <a href="{{ route('agent.fingerprint', $token) }}"
                   class="whitespace-nowrap text-theme-sm font-medium text-brand-500 hover:text-brand-600">Clear</a>
            @endif
        </form>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-theme-sm">
                <thead class="text-theme-xs uppercase tracking-wider text-gray-500 dark:text-gray-800 dark:text-gray-400">
                    <tr class="border-b border-gray-100 dark:border-gray-700">
                        <th class="py-2 pr-4">Program</th>
                        <th class="py-2 pr-4">Version</th>
                        <th class="py-2 pr-4">Publisher</th>
                        <th class="py-2">Installed</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($software as $program)
                        <tr class="border-t border-gray-100 dark:border-gray-700">
                            <td class="py-2 pr-4 font-medium text-gray-800 dark:text-white">{{ $program->name }}</td>
                            <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $program->version ?? '—' }}</td>
                            <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $program->publisher ?? '—' }}</td>
                            <td class="py-2 text-gray-600 dark:text-gray-300">{{ $program->installed_at?->format('d M Y') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-6 text-center text-gray-500 dark:text-gray-400">
                                @if($search !== '')
                                    No program matches "{{ $search }}".
                                @else
                                    No software was reported by this device.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Page size and filter are independent controls, so the select posts
             through the hidden form below rather than nesting forms (illegal in
             HTML). It carries the search term along, or changing the page size
             would silently drop an active filter. --}}
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-4 text-theme-xs dark:border-gray-800">
            <form id="software-pager" method="GET" class="flex items-center gap-2">
                <input type="hidden" name="q" value="{{ $search }}">
                <label for="per_page" class="text-gray-500 dark:text-gray-400">Show</label>
                {{-- min-w, not px-2: a three-digit option plus the native dropdown
                     arrow does not fit in the padding this had, and the browser
                     clipped the last digit under the arrow. --}}
                <select id="per_page" name="per_page" onchange="this.form.submit()"
                        class="min-w-[4.5rem] rounded-lg border border-gray-300 bg-white py-1 pl-3 pr-8 text-gray-700 focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
                    @foreach($pageSizes as $size)
                        <option value="{{ $size }}" @selected($perPage === $size)>{{ $size }}</option>
                    @endforeach
                </select>
                <span class="text-gray-500 dark:text-gray-400">per page</span>
            </form>

            <p class="text-gray-500 dark:text-gray-400">
                Showing {{ $software->firstItem() ?? 0 }}–{{ $software->lastItem() ?? 0 }}
                of {{ $software->total() }}
            </p>
        </div>

        <div class="mt-3">
            {{ $software->links() }}
        </div>
    </x-card>
@endif
@endsection
