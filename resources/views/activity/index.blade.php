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
    @php
        $changes = $log->changes();
        $mismatches = $log->mismatches();
        $entityLink = $log->entity_type === 'assets' && $log->entity_id
            ? route('assets.show', $log->entity_id)
            : null;
        $formatValue = fn ($value) => is_array($value)
            ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : (string) $value;
        $cell = 'rounded-lg border border-gray-200 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-800/40';
        $label = 'text-[11px] font-medium uppercase tracking-wide text-gray-400';
    @endphp
<x-modal id="log-{{ $log->id }}" title="Activity detail" maxWidth="2xl">
    @php $hasPayload = $changes['fields'] || $changes['old'] || $changes['new'] || $mismatches || !empty($log->metadata) || $changes['raw_old'] || $changes['raw_new']; @endphp
    <div class="space-y-4 text-sm">
        {{-- Summary --}}
        <div class="flex items-start gap-3 rounded-xl border border-brand-100 bg-brand-50/60 p-3 dark:border-brand-500/20 dark:bg-brand-500/10">
            <span class="mt-0.5 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white text-brand-600 shadow-theme-xs dark:bg-white/5 dark:text-brand-300">
                <x-icon name="activity" class="h-5 w-5" />
            </span>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="font-semibold text-gray-900 dark:text-white">{{ $log->action }}</p>
                    <span class="rounded-full bg-white px-2 py-0.5 font-mono text-[11px] text-gray-500 dark:bg-gray-800 dark:text-gray-400">#{{ $log->id }}</span>
                </div>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    {{ $log->created_at?->format('Y-m-d H:i:s') }} · {{ $log->created_at?->diffForHumans() }} · {{ $log->source ?: 'app' }}
                </p>
                @if($log->request_id)
                    <p class="mt-1 truncate font-mono text-[11px] text-gray-400">Request {{ $log->request_id }}</p>
                @endif
            </div>
        </div>

        {{-- Who --}}
        <section>
            <h4 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Who</h4>
            <dl class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                <div class="{{ $cell }}"><dt class="{{ $label }}">User</dt><dd class="mt-0.5 font-medium text-gray-900 dark:text-white">{{ $log->actor?->name ?: 'System / unauthenticated' }}</dd></div>
                <div class="{{ $cell }}"><dt class="{{ $label }}">Email</dt><dd class="mt-0.5 truncate text-gray-700 dark:text-gray-200">{{ $log->actor?->email ?: '—' }}</dd></div>
                <div class="{{ $cell }}"><dt class="{{ $label }}">Role / User ID</dt><dd class="mt-0.5 text-gray-700 dark:text-gray-200">{{ $log->actor ? ucfirst((string) $log->actor->role).' · #'.$log->actor_id : '—' }}</dd></div>
            </dl>
        </section>

        {{-- Where + request context (OWASP: when / where / who / what). --}}
        <section>
            <h4 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Where &amp; request</h4>
            <dl class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                <div class="{{ $cell }}"><dt class="{{ $label }}">IP address</dt><dd class="mt-0.5 font-mono text-gray-900 dark:text-white">{{ $log->ip_address ?: 'Not captured' }}</dd></div>
                <div class="{{ $cell }}"><dt class="{{ $label }}">Browser</dt><dd class="mt-0.5 text-gray-700 dark:text-gray-200">{{ $log->browserName() }}</dd></div>
                <div class="{{ $cell }}"><dt class="{{ $label }}">OS / Device</dt><dd class="mt-0.5 text-gray-700 dark:text-gray-200">{{ $log->platformName() }} · {{ $log->deviceType() }}</dd></div>
                <div class="{{ $cell }}"><dt class="{{ $label }}">Method</dt><dd class="mt-0.5 font-mono text-gray-700 dark:text-gray-200">{{ $log->request_method ?: '—' }}</dd></div>
                <div class="{{ $cell }} sm:col-span-2"><dt class="{{ $label }}">URL</dt><dd class="mt-0.5 break-all font-mono text-xs text-gray-700 dark:text-gray-200">{{ $log->request_url ?: '—' }}</dd></div>
                <div class="{{ $cell }}"><dt class="{{ $label }}">Host</dt><dd class="mt-0.5 truncate text-gray-700 dark:text-gray-200">{{ $log->server_host ?: '—' }}</dd></div>
                <div class="{{ $cell }}"><dt class="{{ $label }}">Route</dt><dd class="mt-0.5 truncate font-mono text-xs text-gray-700 dark:text-gray-200">{{ $log->route_name ?: '—' }}</dd></div>
                <div class="{{ $cell }}"><dt class="{{ $label }}">Session (hash)</dt><dd class="mt-0.5 truncate font-mono text-[11px] text-gray-400">{{ $log->session_hash ? substr($log->session_hash, 0, 16).'…' : '—' }}</dd></div>
                <div class="{{ $cell }} sm:col-span-2"><dt class="{{ $label }}">Referer</dt><dd class="mt-0.5 break-all text-xs text-gray-700 dark:text-gray-200">{{ $log->referer ?: '—' }}</dd></div>
                <div class="{{ $cell }} sm:col-span-3"><dt class="{{ $label }}">User agent</dt><dd class="mt-0.5 break-all font-mono text-[11px] text-gray-500 dark:text-gray-400">{{ $log->user_agent ?: 'Not captured' }}</dd></div>
            </dl>
        </section>

        {{-- Subject --}}
        <section>
            <h4 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Subject</h4>
            <dl class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                <div class="{{ $cell }}"><dt class="{{ $label }}">Entity</dt><dd class="mt-0.5 text-gray-900 dark:text-white">{{ $log->entity_type ?: '—' }}</dd></div>
                <div class="{{ $cell }}"><dt class="{{ $label }}">Entity ID</dt><dd class="mt-0.5 font-mono text-gray-700 dark:text-gray-200">{{ $log->entity_id ?: '—' }}</dd></div>
                <div class="{{ $cell }}"><dt class="{{ $label }}">Identifier</dt>
                    <dd class="mt-0.5 break-all font-mono text-xs text-gray-700 dark:text-gray-200">
                        @if($entityLink)<a href="{{ $entityLink }}" class="text-brand-600 hover:underline dark:text-brand-400">{{ $log->entity_identifier ?: 'Open asset' }} ↗</a>@else{{ $log->entity_identifier ?: '—' }}@endif
                    </dd>
                </div>
                @if($changes['fields'])
                    <div class="{{ $cell }} sm:col-span-3"><dt class="{{ $label }}">Fields changed</dt><dd class="mt-0.5 flex flex-wrap gap-1">@foreach($changes['fields'] as $field)<span class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[11px] text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ $field }}</span>@endforeach</dd></div>
                @endif
            </dl>
        </section>

        {{-- Before / after --}}
        @if($changes['old'] || $changes['new'] || $changes['raw_old'] || $changes['raw_new'])
            <section>
                <h4 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Before &amp; after</h4>
                @if($changes['old'] || $changes['new'])
                    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-gray-50 text-gray-500 dark:bg-gray-800 dark:text-gray-400"><tr><th class="px-3 py-2">Field</th><th class="px-3 py-2">Before</th><th class="px-3 py-2">After</th></tr></thead>
                            <tbody>
                                @foreach(array_unique(array_merge(array_keys($changes['old']), array_keys($changes['new']))) as $field)
                                    <tr class="border-t border-gray-100 dark:border-gray-700">
                                        <td class="px-3 py-2 font-mono text-gray-600 dark:text-gray-300">{{ $field }}</td>
                                        <td class="break-all px-3 py-2 text-gray-500 dark:text-gray-400">{{ array_key_exists($field, $changes['old']) ? $formatValue($changes['old'][$field]) : '—' }}</td>
                                        <td class="break-all px-3 py-2 font-medium text-gray-900 dark:text-white">{{ array_key_exists($field, $changes['new']) ? $formatValue($changes['new'][$field]) : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                @if($changes['raw_old'] || $changes['raw_new'])
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        @if($changes['raw_old'])<div><p class="mb-1 text-[11px] text-gray-400">Raw previous value</p><pre class="overflow-x-auto rounded-lg bg-gray-50 p-2 text-[11px] dark:bg-gray-800 dark:text-gray-200">{{ $log->old_value }}</pre></div>@endif
                        @if($changes['raw_new'])<div><p class="mb-1 text-[11px] text-gray-400">Raw new value</p><pre class="overflow-x-auto rounded-lg bg-gray-50 p-2 text-[11px] dark:bg-gray-800 dark:text-gray-200">{{ $log->new_value }}</pre></div>@endif
                    </div>
                @endif
            </section>
        @endif

        @if($mismatches)
            <section>
                <h4 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Mismatches</h4>
                <ul class="space-y-1">
                    @foreach($mismatches as $field => $values)
                        <li class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                            <span class="font-semibold">{{ $field }}</span>:
                            {{ $formatValue(is_array($values) ? ($values['registered'] ?? '—') : $values) }} → {{ $formatValue(is_array($values) ? ($values['actual'] ?? '—') : '—') }}
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if(!empty($log->old_value))
            <div>
                <p class="mb-2 text-xs font-medium text-gray-500 dark:text-gray-400">Old value</p>
                <pre class="overflow-x-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-800 dark:bg-gray-800 dark:text-gray-100">{{ $log->old_value }}</pre>
            </div>
        @endif

        @if(!empty($log->field_changed))
            <div>
                <p class="mb-2 text-xs font-medium text-gray-500 dark:text-gray-400">Fields changed</p>
                <p class="overflow-x-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-800 dark:bg-gray-800 dark:text-gray-100">{{ $log->field_changed }}</p>
            </div>
        @endif

        @if(!empty($log->metadata))
            <details class="rounded-lg border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800/50">
                <summary class="cursor-pointer px-3 py-2 text-xs font-medium text-gray-600 dark:text-gray-300">Raw metadata ({{ count($log->metadata) }} keys)</summary>
                <pre class="overflow-x-auto border-t border-gray-200 p-3 text-[11px] text-gray-700 dark:border-gray-700 dark:text-gray-200">{{ json_encode($log->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            </details>
        @endif

        @if(empty($log->mismatches) && empty($log->new_value) && empty($log->old_value) && empty($log->metadata) && empty($log->field_changed))
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