@extends('layouts.app')

@section('title', 'Services')

@section('content')
<x-page-header icon="services.index" title="Services & engines" subtitle="Runtime checks for the engines, infrastructure, and integrations configured in this project.">
    <x-slot:actions>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('services.health') }}" class="inline-flex items-center gap-1.5 text-theme-xs font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400"><x-icon name="code" class="h-4 w-4" /> JSON</a>
            <x-button id="services-recheck" type="button" variant="secondary" size="sm"><x-icon name="refresh" class="h-4 w-4" /> Re-check</x-button>
        </div>
    </x-slot:actions>
</x-page-header>

@php
    $slow = collect($services)->where('status', 'ok')->where('ms', '>=', 500)->count();
@endphp

<section data-live-region="services" aria-label="Service and engine health">
    <div class="services-summary-grid mb-4">
        <div class="services-summary-item"><x-icon name="services.index" class="h-5 w-5 text-gray-500" /><span class="services-summary-label">Services</span><strong>{{ $total }}</strong></div>
        <div class="services-summary-item services-summary-healthy"><x-icon name="success" class="h-5 w-5 text-success-600" /><span class="services-summary-label">Healthy</span><strong>{{ $healthy }}</strong></div>
        <div class="services-summary-item services-summary-down"><x-icon name="error" class="h-5 w-5 text-error-600" /><span class="services-summary-label">Down</span><strong>{{ $down }}</strong></div>
        <div class="services-summary-item services-summary-skipped"><x-icon name="activity" class="h-5 w-5 text-gray-500" /><span class="services-summary-label">Not checked</span><strong>{{ $skipped }}</strong></div>
        <div class="services-summary-item services-summary-slow"><x-icon name="warning" class="h-5 w-5 text-warning-600" /><span class="services-summary-label">Slow</span><strong>{{ $slow }}</strong></div>
    </div>

    <div class="services-live-meta mb-3 flex flex-wrap items-center justify-between gap-2">
        <p class="text-theme-xs text-gray-500 dark:text-gray-400">
            @if($down > 0) <span class="font-semibold text-error-600">{{ $down }} service{{ $down === 1 ? '' : 's' }} need attention.</span>
            @elseif($skipped > 0) <span class="font-semibold text-warning-700">No failures detected; {{ $skipped }} check{{ $skipped === 1 ? ' was' : 's were' }} skipped.</span>
            @else <span class="font-semibold text-success-700">All {{ $total }} checks are healthy.</span> @endif
            @if($slow > 0) <span>{{ $slow }} healthy check{{ $slow === 1 ? ' is' : 's are' }} responding slowly.</span> @endif
        </p>
        <span class="inline-flex items-center gap-1.5 text-theme-xs text-gray-500 dark:text-gray-400" data-live-stamp>
            <span class="pulse-dot h-1.5 w-1.5 rounded-full bg-success-500"></span>
            checked {{ now()->format('H:i:s') }} · refresh in <span data-refresh-countdown>30s</span>
        </span>
    </div>

    <div class="services-grid stagger">
        @foreach($services as $service)
            @php
                $isOk = $service['status'] === 'ok';
                $isSkipped = $service['status'] === 'skipped';
                $serviceIcon = match ($service['name']) {
                    'PHP runtime', 'Laravel runtime', 'Livewire UI engine' => 'code',
                    'Excel import engine', 'PDF generation engine' => 'documents.index',
                    'QR code engine' => 'assets.qr.sheet',
                    'Progressive Web App' => 'install',
                    'Database' => 'database',
                    'Redis' => 'server',
                    'Cache' => 'settings',
                    'Session' => 'users',
                    'Queue' => 'transfer',
                    'Mail' => 'mail',
                    'Local file storage', 'Amazon S3 storage' => 'cloud',
                    'Scheduler' => 'activity',
                    'Health endpoint' => 'success',
                    'Microsoft Entra ID' => 'access',
                    'Microsoft Graph' => 'users',
                    default => 'services.index',
                };
            @endphp
            <article @class([
                'service-status-card animate-fade-up rounded-xl border border-l-4 p-4 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-theme-md dark:bg-gray-800/60',
                'border-gray-200 border-l-success-500 dark:border-gray-800 dark:border-l-success-500' => $isOk,
                'border-gray-200 border-l-gray-300 dark:border-gray-800 dark:border-l-gray-500' => $isSkipped,
                'border-gray-200 border-l-error-500 dark:border-gray-800 dark:border-l-error-500' => ! $isOk && ! $isSkipped,
            ])>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-gray-50 text-brand-600 dark:bg-gray-700/60 dark:text-brand-300"><x-icon :name="$serviceIcon" class="h-4 w-4" /></span>
                    <span @class([
                        'h-2.5 w-2.5 flex-shrink-0 rounded-full',
                        'bg-success-500 status-glow-ok' => $isOk,
                        'bg-gray-400' => $isSkipped,
                        'bg-error-500 status-glow-down' => ! $isOk && ! $isSkipped,
                    ])></span>
                    <h2 class="service-status-name text-theme-sm font-semibold text-gray-800 dark:text-white">{{ $service['name'] }}</h2>
                    <span class="service-category ml-auto">{{ $service['category'] ?? 'Service' }}</span>
                    <span class="service-status-badge" aria-label="Service status">
                        @if($isSkipped)
                            <x-badge variant="gray">not checked</x-badge>
                        @elseif(! $isOk)
                            <x-badge variant="error">down</x-badge>
                        @elseif($service['ms'] >= 500)
                            {{-- Slow is not down, but an operator should see it before
                                 it becomes an outage. --}}
                            <x-badge variant="warning">healthy · slow · {{ $service['ms'] }} ms</x-badge>
                        @else
                            <x-badge variant="success">healthy · {{ $service['ms'] }} ms</x-badge>
                        @endif
                    </span>
                </div>
                <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ $service['purpose'] }}</p>
                <p class="service-status-detail mt-2 border-t border-gray-100 pt-2 text-theme-xs break-words text-gray-600 dark:border-gray-800 dark:text-gray-300">
                    {{ $service['detail'] }}
                </p>
                @if(!empty($service['checked_at']))
                    <p class="mt-2 text-[10px] text-gray-400 dark:text-gray-500">Last checked {{ \Illuminate\Support\Carbon::parse($service['checked_at'])->format('H:i:s') }}</p>
                @endif
            </article>
        @endforeach
    </div>
</section>

<p class="mt-4 text-theme-xs text-gray-500 dark:text-gray-400">
    Checks are lightweight. Cache/Redis probes use a short-lived read-back marker; no agent commands are dispatched.
    Use <span class="font-medium">/services/health.json</span> for an uptime monitor — it
    answers <span class="font-medium">503</span> while anything is down.
</p>

<script>
    // Owns this page's refresh cycle: a visible countdown, a soft patch of
    // the region at zero, and a manual re-check that never navigates.
    (() => {
        const INTERVAL = 30;
        let left = INTERVAL;
        let inFlight = false;
        const region = document.querySelector('[data-live-region="services"]');
        const bar = document.getElementById('top-progress');
        const recheck = document.getElementById('services-recheck');
        if (!region) {
            return;
        }

        const refresh = async (manual) => {
            if (inFlight) return;
            inFlight = true;
            try {
                if (bar) {
                    bar.classList.add('is-busy');
                    bar.setAttribute('aria-valuetext', 'Checking services');
                }
                region.classList.add('is-refreshing');
                region.setAttribute('aria-busy', 'true');
                const refreshUrl = new URL(window.location.href);
                refreshUrl.searchParams.set('_health_refresh', String(Date.now()));
                const response = await fetch(refreshUrl, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                });
                if (!response.ok) {
                    throw new Error('re-check failed');
                }
                const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
                const fresh = doc.querySelector('[data-live-region="services"]');
                if (fresh) {
                    region.innerHTML = fresh.innerHTML;
                }
                if (manual) {
                    window.showToast?.('Services re-checked.', 'success');
                }
            } catch (error) {
                if (manual) {
                    window.showToast?.('Re-check failed. Please try again.', 'error');
                }
            } finally {
                inFlight = false;
                if (recheck) {
                    recheck.disabled = false;
                    recheck.removeAttribute('aria-busy');
                    recheck.textContent = 'Re-check';
                }
                if (bar) {
                    bar.classList.remove('is-busy');
                    bar.removeAttribute('aria-valuetext');
                }
                region.classList.remove('is-refreshing');
                region.removeAttribute('aria-busy');
                left = INTERVAL;
                const label = region.querySelector('[data-refresh-countdown]');
                if (label) {
                    label.textContent = INTERVAL + 's';
                }
            }
        };

        recheck?.addEventListener('click', () => {
            if (inFlight) return;
            recheck.disabled = true;
            recheck.setAttribute('aria-busy', 'true');
            recheck.textContent = 'Checking…';
            refresh(true);
        });

        window.setInterval(() => {
            if (document.hidden) {
                return;
            }
            left -= 1;
            if (left <= 0) {
                refresh(false);
                return;
            }
            const label = region.querySelector('[data-refresh-countdown]');
            if (label) {
                label.textContent = left + 's';
            }
        }, 1000);
    })();
</script>
@endsection
