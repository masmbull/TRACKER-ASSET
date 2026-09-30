@extends('layouts.app')

@section('title', 'QR Labels')

@section('content')
{{--
    A run of labels, printable in one pass.

    Generating one code at a time means visiting every asset and opening every
    print dialog, which is how labels end up never being applied at all. This
    answers the question an operator actually has - "give me labels for the
    laptops in this branch" - and prints them in one go.

    The furniture is hidden from print on purpose: a sidebar and a header are
    noise on a sheet that gets cut up and stuck on laptops, and they push the
    grid onto a second sheet of paper for no reason.
--}}
<div class="no-print mb-6">
    <x-page-header title="QR Labels" subtitle="Printable labels for assets already in the register. Each code opens the asset page when scanned.">
        <x-slot:actions>
            <x-button variant="primary" size="sm" onclick="window.print()">Print</x-button>
        </x-slot:actions>
    </x-page-header>

    <x-card>
        <form method="GET" action="/assets/qr" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div>
                <label for="search" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Search</label>
                <input type="text" id="search" name="search" value="{{ request('search') }}" placeholder="Asset ID, serial, hostname"
                       class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
            </div>
            <div>
                <label for="company" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Company</label>
                <select id="company" name="company" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    <option value="">All</option>
                    @foreach($companies as $company)
                        <option value="{{ $company->id }}" {{ (string) request('company') === (string) $company->id ? 'selected' : '' }}>{{ $company->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="location" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Location</label>
                <select id="location" name="location" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    <option value="">All</option>
                    @foreach($locations as $location)
                        <option value="{{ $location->id }}" {{ (string) request('location') === (string) $location->id ? 'selected' : '' }}>{{ $location->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="status" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                <select id="status" name="status" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    <option value="">All</option>
                    @foreach($statuses as $status)
                        <option value="{{ $status->id }}" {{ (string) request('status') === (string) $status->id ? 'selected' : '' }}>{{ $status->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end gap-3">
                <x-button type="submit" variant="primary">Apply</x-button>
                <a href="/assets/qr" class="pb-2.5 text-theme-sm text-gray-500 hover:text-gray-700 dark:text-gray-400">Clear</a>
            </div>
        </form>
    </x-card>

    <p class="mt-4 text-theme-sm text-gray-500">
        {{ $assets->total() }} {{ Str::plural('label', $assets->total()) }} &middot; showing {{ $assets->firstItem() }}&ndash;{{ $assets->lastItem() }}
        @if($assets->total() > config('assets.qr.sheet_size'))
            &middot; printed {{ config('assets.qr.sheet_size') }} at a time to keep the page quick and easy to handle
        @endif
    </p>

    @if($assets->lastPage() > 1)
        <div class="mt-3">{{ $assets->links() }}</div>
    @endif
</div>

<div class="label-grid">
    @forelse($assets as $asset)
        <figure class="label">
            {{-- The same service that serves /assets/{id}/qr, so a label stuck on
                 a laptop and the code on the asset page can never disagree. --}}
            <img src="{{ route('assets.qr', $asset) }}" alt="QR code for {{ $asset->asset_id }}" width="150" height="181">
            <figcaption>
                <span class="label-caption">{{ config('assets.qr.caption') }}</span>
                <span class="label-id">{{ $asset->asset_id }}</span>
            </figcaption>
        </figure>
    @empty
        <p class="col-span-full py-10 text-center text-theme-sm text-gray-400">
            {{ request()->filled(['search', 'company', 'location', 'status']) ? 'No assets match this filter.' : 'No active assets to label yet.' }}
        </p>
    @endforelse
</div>

<style>
    .label-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
        gap: 12px;
    }

    .label {
        margin: 0;
        padding: 8px 6px 10px;
        border: 1px dashed #d1d5db;
        border-radius: 8px;
        background: #fff;
        text-align: center;
        break-inside: avoid;
        page-break-inside: avoid;
    }

    .label img { display: block; width: 100%; height: auto; }

    .label figcaption { margin-top: 4px; line-height: 1.25; }

    .label-caption {
        display: block;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .04em;
        color: #111827;
    }

    .label-id {
        display: block;
        margin-top: 2px;
        font-size: 12px;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        color: #374151;
        word-break: break-all;
    }

    @media print {
        .no-print { display: none !important; }
        body { background: #fff; }
        .label-grid { gap: 6mm; }
        .label { border-color: #e5e7eb; }
    }
</style>
@endsection
