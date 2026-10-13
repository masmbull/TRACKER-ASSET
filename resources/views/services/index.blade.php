@extends('layouts.app')

@section('title', 'Services')

@section('content')
<x-page-header title="Services" subtitle="Every backing service this app depends on, checked live.">
    <x-slot:actions>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('services.health') }}" class="text-theme-xs font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400">JSON</a>
            <x-button id="services-recheck" type="button" variant="secondary" size="sm">Re-check</x-button>
        </div>
    </x-slot:actions>
</x-page-header>

{{-- One line instead of a grid of KPI cards: the whole page is a status
     list, and repeating the count in three large cards says nothing the list
     does not already say. --}}
<div class="animate-fade-in mb-4 flex flex-wrap items-center gap-3 text-theme-sm">
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
    <span class="inline-flex items-center gap-1.5 rounded-full bg-success-50 px-2.5 py-1 text-theme-xs font-semibold text-success-700 dark:bg-success-500/10 dark:text-success-300">
        <span class="pulse-dot h-1.5 w-1.5 rounded-full bg-success-500"></span>live
    </span>
    <span class="text-theme-xs text-gray-500 dark:text-gray-400" data-live-stamp>checked {{ now()->format('H:i:s') }} · refresh in <span data-refresh-countdown>30s</span></span>
</div>

<x-card data-live-region="services" class="{{ $down > 0 ? 'border-error-300 dark:border-error-500/30' : '' }}">
    <div class="stagger space-y-2">
        @foreach($services as $service)
            @php
                $isOk = $service['status'] === 'ok';
                $isSkipped = $service['status'] === 'skipped';
                // Bar scale: 1000 ms fills the track. Inline background, not a
                // bg-* utility, so the enterprise bar override cannot flatten
                // every bar to the same red.
                $latWidth = max(min(($service['ms'] ?? 0) / 10, 100), 4);
                $latColor = ($service['ms'] ?? 0) >= 500 ? '#f79009' : (($service['ms'] ?? 0) >= 200 ? '#0ba5ec' : '#12b76a');
            @endphp
            <div class="animate-fade-up flex flex-wrap items-start justify-between gap-3 rounded-xl border-b border-gray-100 px-3 py-3 transition-colors duration-200 last:border-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/5">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span @class([
                            'h-2.5 w-2.5 flex-shrink-0 rounded-full',
                            'bg-success-500 status-glow-ok' => $isOk,
                            'bg-gray-400' => $isSkipped,
                            'bg-error-500 status-glow-down' => ! $isOk && ! $isSkipped,
                        ])></span>
                        <p class="text-theme-sm font-medium text-gray-800 dark:text-white">{{ $service['name'] }}</p>
                        @if($isSkipped)
                            <x-badge variant="gray">not checked</x-badge>
                        @elseif(! $isOk)
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
                    @if($isOk)
                        <div class="mt-2 h-1 w-full max-w-xs overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                            <div class="latency-fill h-full rounded-full" style="width: {{ $latWidth }}%; background: {{ $latColor }}"></div>
                        </div>
                    @endif
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
    // Owns this page's refresh cycle: a visible countdown, a soft patch of
    // the region at zero, and a manual re-check that never navigates.
    (() => {
        const INTERVAL = 30;
        let left = INTERVAL;
        const region = document.querySelector('[data-live-region="services"]');
        const stamp = document.querySelector('[data-live-stamp]');
        const label = document.querySelector('[data-refresh-countdown]');
        const bar = document.getElementById('top-progress');
        if (!region) {
            return;
        }

        const stampNow = () => {
            const now = new Date();
            const pad = (n) => String(n).padStart(2, '0');
            return 'checked ' + pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
        };

        const refresh = async (manual) => {
            try {
                if (bar) {
                    bar.classList.add('is-busy');
                }
                region.classList.add('opacity-60');
                const response = await fetch(window.location.href, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
                    credentials: 'same-origin',
                });
                if (!response.ok) {
                    throw new Error('re-check failed');
                }
                const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
                const fresh = doc.querySelector('[data-live-region="services"]');
                if (fresh) {
                    // Fresh nodes replay their entrance animations on insert,
                    // so every refresh cascades the rows again.
                    region.innerHTML = fresh.innerHTML;
                }
                if (stamp) {
                    stamp.childNodes[0].textContent = stampNow() + ' · refresh in ';
                }
                if (manual) {
                    window.showToast?.('Services re-checked.', 'success');
                }
            } catch (error) {
                if (manual) {
                    window.showToast?.('Re-check failed. Please try again.', 'error');
                }
            } finally {
                if (bar) {
                    bar.classList.remove('is-busy');
                }
                region.classList.remove('opacity-60');
                left = INTERVAL;
                if (label) {
                    label.textContent = INTERVAL + 's';
                }
            }
        };

        document.getElementById('services-recheck')?.addEventListener('click', () => refresh(true));

        window.setInterval(() => {
            if (document.hidden) {
                return;
            }
            left -= 1;
            if (left <= 0) {
                refresh(false);
                return;
            }
            if (label) {
                label.textContent = left + 's';
            }
        }, 1000);
    })();
</script>
@endsection
