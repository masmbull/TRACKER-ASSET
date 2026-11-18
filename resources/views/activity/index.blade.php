@extends('layouts.app')

@section('title', 'Activity Log')

@section('content')
<h1 class="text-2xl font-bold mb-6">Activity Log</h1>

<form method="GET" action="{{ route('activity.index') }}" class="flex flex-wrap items-center gap-2 mb-4">
    <input type="text" name="search" value="{{ request('search') }}" data-live-filter placeholder="Asset, entity, user..." class="border rounded-lg px-3 py-2 flex-1 dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:placeholder-gray-400">
    <select name="action" class="border rounded-lg px-3 py-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
        <option value="">All actions</option>
        @foreach($actions as $action)
            <option value="{{ $action }}" {{ request('action') === $action ? 'selected' : '' }}>{{ $action }}</option>
        @endforeach
    </select>
    <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-gray-600 px-4 py-2 text-theme-sm font-medium text-white transition-all duration-200 active:scale-[0.97] hover:bg-gray-700 dark:bg-gray-600">Filter</button>
    <a href="{{ route('activity.index') }}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 transition-all duration-200 active:scale-[0.97] hover:bg-gray-50 dark:border-gray-600 dark:bg-[#1d2939] dark:text-gray-100 dark:hover:bg-gray-800">Reset</a>
</form>

@if($logs->count() > 0)
<div class="overflow-x-auto" data-live-filter-scope>
<table class="min-w-full bg-white shadow rounded text-sm dark:bg-[#1d2939]">
    <thead>
        <tr class="border-b">
            <th class="text-left py-3 px-4">Time</th>
            <th class="text-left py-3 px-4">User</th>
            <th class="text-left py-3 px-4">Action</th>
            <th class="text-left py-3 px-4">Entity</th>
            <th class="text-left py-3 px-4">Identifier</th>
            <th class="text-left py-3 px-4">Source</th>
            <th class="text-left py-3 px-4">Detail</th>
        </tr>
    </thead>
    <tbody>
        @foreach($logs as $log)
        <tr class="border-b align-top hover:bg-gray-50 dark:hover:bg-gray-800" data-live-filter-row>
            <td class="py-2 px-4 whitespace-nowrap">{{ $log->created_at?->format('Y-m-d H:i') }}</td>
            <td class="py-2 px-4">{{ $log->actor?->name }}</td>
            <td class="py-2 px-4"><span class="px-2 py-1 text-xs rounded bg-gray-200 dark:bg-gray-700">{{ $log->action }}</span></td>
            <td class="py-2 px-4">{{ $log->entity_type }}</td>
            <td class="py-2 px-4">{{ $log->entity_identifier }}</td>
            <td class="py-2 px-4">{{ $log->source }}</td>
            <td class="py-2 px-4">
                <x-row-action variant="brand" icon="eye" @click="$dispatch('open-modal', 'log-{{ $log->id }}')">Detail</x-row-action>
            </td>
        </tr>
        @endforeach
        <tr data-live-filter-empty class="hidden">
            <td colspan="7" class="py-6 px-4 text-center text-sm text-gray-500 dark:text-gray-400">No activity matches what you typed.</td>
        </tr>
    </tbody>
</table>
</div>

{{ $logs->links() }}
@else
<p class="bg-white p-6 rounded-xl shadow dark:bg-[#1d2939]">No activity found.</p>
@endif

{{-- One dialog per row, so a detail never costs a page load. --}}
@foreach($logs as $log)
<x-modal id="log-{{ $log->id }}" title="Activity detail" maxWidth="lg">
    <div class="space-y-4 text-sm">
        <div class="flex items-start gap-3">
            <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                <x-icon name="activity" class="h-5 w-5" />
            </span>
            <div class="min-w-0">
                <p class="font-semibold text-gray-900 dark:text-white">{{ $log->action }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $log->created_at?->format('Y-m-d H:i:s') }} · {{ $log->source ?: 'app' }}
                </p>
            </div>
        </div>

        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <dt class="text-xs text-gray-500 dark:text-gray-400">User</dt>
                <dd class="font-medium text-gray-900 dark:text-white">{{ $log->actor?->name ?: '—' }}</dd>
            </div>
            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <dt class="text-xs text-gray-500 dark:text-gray-400">Entity</dt>
                <dd class="font-medium text-gray-900 dark:text-white">{{ $log->entity_type ?: '—' }}</dd>
            </div>
            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700 sm:col-span-2">
                <dt class="text-xs text-gray-500 dark:text-gray-400">Identifier</dt>
                <dd class="font-mono text-xs text-gray-900 dark:text-white">{{ $log->entity_identifier ?: '—' }}</dd>
            </div>
        </dl>

        @if(!empty($log->mismatches ?? null))
            <div>
                <p class="mb-2 text-xs font-medium text-gray-500 dark:text-gray-400">Mismatches</p>
                <ul class="space-y-1">
                    @foreach($log->mismatches as $field => $values)
                        <li class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                            <span class="font-semibold">{{ $field }}</span>:
                            {{ $values['registered'] ?? '—' }} → {{ $values['actual'] ?? '—' }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(!empty($log->new_value))
            <div>
                <p class="mb-2 text-xs font-medium text-gray-500 dark:text-gray-400">New value</p>
                <pre class="overflow-x-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-800 dark:bg-gray-800 dark:text-gray-100">{{ $log->new_value }}</pre>
            </div>
        @endif

        @if(!empty($log->metadata))
            <div>
                <p class="mb-2 text-xs font-medium text-gray-500 dark:text-gray-400">Metadata</p>
                <pre class="overflow-x-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-800 dark:bg-gray-800 dark:text-gray-100">{{ json_encode($log->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
        @endif

        @if(empty($log->mismatches) && empty($log->new_value) && empty($log->metadata))
            <p class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                This entry carries no payload — only the actor, the entity and the source.
            </p>
        @endif
    </div>

    <x-slot:footer>
        <button type="button" @click="$dispatch('close-modal', 'log-{{ $log->id }}')" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">Close</button>
    </x-slot:footer>
</x-modal>
@endforeach
@endsection