@extends('layouts.app')

@section('title', 'Services')

@section('content')
<x-page-header title="Services" subtitle="Every backing service this app depends on, checked live.">
    <x-slot:actions>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('services.health') }}" class="text-theme-xs font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400">JSON</a>
            <x-button href="{{ route('services.index') }}" variant="secondary" size="sm">Re-check</x-button>
        </div>
    </x-slot:actions>
</x-page-header>

{{-- One line instead of a grid of KPI cards: the whole page is a status
     list, and repeating the count in three large cards says nothing the list
     does not already say. --}}
<div class="mb-4 flex flex-wrap items-center gap-3 text-theme-sm">
    @if($down > 0)
        <x-badge variant="error" :dot="true">{{ $down }} of {{ $total }} down</x-badge>
        <span class="text-gray-600 dark:text-gray-300">{{ $healthy }} healthy</span>
    @elseif($skipped > 0)
        {{-- "All healthy" would be a lie when a check was never performed.
             Say what is actually true: nothing is down, and this much was
             not looked at. --}}
        <x-badge variant="warning" :dot="true">No failures · {{ $skipped }} not checked</x-badge>
        <span class="text-gray-600 dark:text-gray-300">{{ $healthy }} healthy</span>
    @else
        <x-badge variant="success" :dot="true">All {{ $total }} services healthy</x-badge>
    @endif
    <span class="text-theme-xs text-gray-500 dark:text-gray-400">checked {{ now()->format('H:i:s') }}</span>
</div>

<x-card>
    <div class="space-y-2">
        @foreach($services as $service)
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 pb-3 last:border-0 last:pb-0 dark:border-gray-800">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span @class([
                            'h-2 w-2 flex-shrink-0 rounded-full',
                            'bg-success-500' => $service['status'] === 'ok',
                            // Gray, not red. A check that could not be performed
                            // is not a failure, and painting it the same as a real
                            // outage is how a monitor teaches people to ignore it.
                            'bg-gray-400' => $service['status'] === 'skipped',
                            'bg-error-500' => ! in_array($service['status'], ['ok', 'skipped'], true),
                        ])></span>
                        <p class="text-theme-sm font-medium text-gray-800 dark:text-white">{{ $service['name'] }}</p>
                        @if($service['status'] === 'skipped')
                            <x-badge variant="gray">not checked</x-badge>
                        @elseif($service['status'] !== 'ok')
                            <x-badge variant="error">down</x-badge>
                        @elseif($service['ms'] >= 500)
                            {{-- Slow is not down, but an operator should see it before
                                 it becomes an outage. --}}
                            <x-badge variant="warning">slow {{ $service['ms'] }} ms</x-badge>
                        @else
                            <x-badge variant="success">{{ $service['ms'] }} ms</x-badge>
                        @endif
                    </div>
                    <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ $service['purpose'] }}</p>
                </div>
                <p class="min-w-0 max-w-md break-words text-right text-theme-xs text-gray-600 dark:text-gray-300">
                    {{ $service['detail'] }}
                </p>
            </div>
        @endforeach
    </div>
</x-card>

<p class="mt-4 text-theme-xs text-gray-500 dark:text-gray-400">
    Checks are live and read-only: they open a connection, read a value and close it.
    Use <span class="font-medium">/services/health.json</span> for an uptime monitor — it
    answers <span class="font-medium">503</span> while anything is down.
</p>

<script>
    setTimeout(function () { window.location.reload(); }, 30000);
</script>
@endsection