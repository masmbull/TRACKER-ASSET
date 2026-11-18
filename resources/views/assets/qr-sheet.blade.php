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
    <x-page-header title="QR Labels" subtitle="Printable asset labels · MITO GROUP ASSETS. Each code opens the asset page when scanned.">
        <x-slot:actions>
            <x-button variant="primary" size="sm" onclick="window.print()">Print</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
    <x-card title="Create a QR code">
        <form id="custom-qr-form" method="POST" action="{{ route('assets.qr.custom') }}" class="space-y-3" data-no-busy>
            @csrf
            <div>
                <label for="qr-content" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Text or URL</label>
                <textarea id="qr-content" name="content" rows="3" maxlength="500" required
                          placeholder="Paste a link or type text to encode"
                          class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"></textarea>
                <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">Up to 500 characters. The generated QR includes the MITO logo.</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <x-button type="submit" variant="primary">Generate QR</x-button>
                <p id="custom-qr-error" class="hidden text-theme-sm text-error-600 dark:text-error-400" role="alert"></p>
            </div>
        </form>
    </x-card>

    <x-card title="Scan or find an asset">
        <p class="mb-3 text-theme-sm text-gray-500 dark:text-gray-400">Scan a barcode or QR label, or type an asset ID, serial number, or hostname.</p>
        <form method="POST" action="{{ route('barcode.resolve') }}" class="flex flex-wrap gap-2">
            @csrf
            <input type="text" name="code" value="{{ old('code') }}" required
                   placeholder="Scan or enter asset code"
                   class="min-w-0 flex-1 rounded-lg border border-gray-200 bg-white px-3 py-2.5 font-mono text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
            <x-button type="submit" variant="secondary">Find asset</x-button>
        </form>
        @error('code')
            <p class="mt-2 text-theme-sm text-error-600 dark:text-error-400" role="alert">{{ $message }}</p>
        @enderror
    </x-card>
    </div>

    <div id="custom-qr-loading" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-gray-950/60 p-4" role="dialog" aria-modal="true" aria-labelledby="custom-qr-loading-title" aria-live="polite">
        <section class="flex w-full max-w-sm flex-col items-center rounded-xl bg-white p-8 text-center shadow-xl dark:bg-gray-900">
            <div class="h-11 w-11 animate-spin rounded-full border-4 border-brand-500 border-t-transparent" aria-hidden="true"></div>
            <h2 id="custom-qr-loading-title" class="mt-5 text-lg font-semibold text-gray-900 dark:text-white">Generating QR code</h2>
            <p class="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">This should only take a moment.</p>
        </section>
    </div>

    <div id="custom-qr-modal" class="fixed inset-0 z-[9998] hidden items-center justify-center bg-gray-950/60 p-4" role="presentation">
        <section class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl dark:bg-gray-900" role="dialog" aria-modal="true" aria-labelledby="custom-qr-modal-title">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 id="custom-qr-modal-title" class="text-lg font-semibold text-gray-900 dark:text-white">Your QR code</h2>
                    <p class="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">Scan it or download the SVG.</p>
                </div>
                <button id="custom-qr-close" type="button" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/10" aria-label="Close QR preview" title="Close">
                    <x-icon name="close" class="h-5 w-5" />
                </button>
            </div>
            <div class="mt-5 flex justify-center rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700">
                <img id="custom-qr-preview" class="h-auto w-full max-w-72" alt="Generated QR code with MITO logo">
            </div>
            <div class="mt-5 flex justify-end gap-2">
                <x-button id="custom-qr-download" variant="secondary" href="#" download="mito-custom-qr.svg">Download SVG</x-button>
                <x-button type="button" variant="primary" id="custom-qr-done">Done</x-button>
            </div>
        </section>
    </div>

    <x-card>
        <form method="GET" action="/assets/qr" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-6">
            <div>
                <label for="search" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Search</label>
                <input type="text" id="search" name="search" value="{{ request('search') }}" placeholder="Asset ID, serial, hostname, model"
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
                <label for="category" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Category</label>
                <select id="category" name="category" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    <option value="">All</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" {{ (string) request('category') === (string) $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
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

    <x-card class="mt-4" title="Generated QR history">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[620px] text-left">
                <thead>
                    <tr class="border-b border-gray-200 text-theme-xs font-semibold uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        <th class="px-3 py-2.5">Content</th>
                        <th class="px-3 py-2.5">Created</th>
                        <th class="px-3 py-2.5 text-right">Download</th>
                    </tr>
                </thead>
                <tbody id="custom-qr-history" class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($qrHistory as $history)
                        <tr data-history-id="{{ $history->id }}">
                            <td class="max-w-lg truncate px-3 py-3 text-theme-sm text-gray-700 dark:text-gray-300" title="{{ $history->content }}">{{ $history->content }}</td>
                            <td class="whitespace-nowrap px-3 py-3 text-theme-sm text-gray-500 dark:text-gray-400">{{ $history->created_at->format('Y-m-d H:i') }}</td>
                            <td class="px-3 py-3 text-right"><a href="{{ route('assets.qr.history.download', $history) }}" class="text-theme-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Download SVG</a></td>
                        </tr>
                    @empty
                        <tr data-history-empty>
                            <td colspan="3" class="px-3 py-5 text-center text-theme-sm text-gray-500 dark:text-gray-400">Generated QR codes will appear here.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($qrHistory->hasPages())
            <div class="mt-3">{{ $qrHistory->links() }}</div>
        @endif
    </x-card>

    <p class="mt-4 text-theme-sm text-gray-500">
        {{ $assets->total() }} {{ Str::plural('label', $assets->total()) }} &middot; showing {{ $assets->firstItem() }}&ndash;{{ $assets->lastItem() }}
        @if($assets->total() > config('assets.qr.sheet_size'))
            &middot; printed {{ config('assets.qr.sheet_size') }} at a time to keep the page quick and easy to handle
        @endif
    </p>

    {{-- A label printed from a machine whose base URL is still localhost encodes a
         dead address onto a sticker. The mistake is invisible until someone scans
         it days later, so it is shouted about here, before the print button. --}}
    @php($qrBase = rtrim((string) config('assets.qr.base_url'), '/'))
    @if($qrBase === '' || str_contains($qrBase, 'localhost') || str_contains($qrBase, '127.0.0.1'))
        <div class="mt-4 flex items-start gap-3 rounded-xl border border-error-200 bg-error-50 px-4 py-3 text-theme-sm text-error-700 dark:border-error-500/30 dark:bg-error-500/10 dark:text-error-300">
            <x-icon name="warning" class="mt-0.5 h-[18px] w-[18px] shrink-0" />
            <span>These labels would encode <code class="rounded bg-error-100 px-1 dark:bg-error-500/20">{{ $qrBase ?: 'a relative URL' }}</code> — a phone that scans them cannot open that. Set <code class="rounded bg-error-100 px-1 dark:bg-error-500/20">ASSET_QR_BASE_URL</code> to the address your fleet can reach before printing.</span>
        </div>
    @endif

    @if($assets->lastPage() > 1)
        <div class="mt-3">{{ $assets->links() }}</div>
    @endif
</div>

<div class="no-print mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-900">
    <form id="bulk-label-form" method="GET" action="{{ route('assets.qr.print') }}" class="flex flex-wrap items-center gap-2">
        @foreach(['search', 'company', 'category', 'location', 'status'] as $filter)
            <input type="hidden" name="{{ $filter }}" value="{{ request($filter) }}">
        @endforeach
        <label class="flex items-center gap-2 text-theme-sm text-gray-600 dark:text-gray-300">
            <input type="checkbox" id="label-select-all" class="h-4 w-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500">
            Select all
        </label>
        <span id="label-selection-count" class="text-theme-sm text-gray-500 dark:text-gray-400">0 selected</span>
        <x-button type="submit" variant="primary" size="sm">Print selected</x-button>
        <x-button type="submit" variant="secondary" size="sm" formaction="{{ route('assets.qr.pdf') }}">Download PDF</x-button>
    </form>
    <button type="button" class="text-theme-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400" onclick="window.print()">Print this page</button>
</div>

<div class="label-grid">
    @forelse($assets as $asset)
        <div class="label-item">
            <x-asset-label :asset="$asset" :qr-src="route('assets.qr', [$asset, 'plain' => 1])" />
            <label class="no-print label-check">
                <input type="checkbox" name="ids[]" value="{{ $asset->id }}" form="bulk-label-form" class="label-select h-4 w-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500">
                <span>Select</span>
            </label>
        </div>
    @empty
        <p class="col-span-full py-10 text-center text-theme-sm text-gray-400">
            {{ request()->anyFilled(['search', 'company', 'category', 'location', 'status']) ? 'No assets match this filter.' : 'No active assets to label yet.' }}
        </p>
    @endforelse
</div>

<script>
    const customQrForm = document.getElementById('custom-qr-form');
    const customQrModal = document.getElementById('custom-qr-modal');
    const customQrLoading = document.getElementById('custom-qr-loading');
    const customQrPreview = document.getElementById('custom-qr-preview');
    const customQrDownload = document.getElementById('custom-qr-download');
    const customQrError = document.getElementById('custom-qr-error');
    let customQrObjectUrl = null;

    function closeCustomQrModal() {
        customQrModal.classList.add('hidden');
        customQrModal.classList.remove('flex');
        customQrPreview.removeAttribute('src');
        customQrDownload.setAttribute('href', '#');

        if (customQrObjectUrl) {
            URL.revokeObjectURL(customQrObjectUrl);
            customQrObjectUrl = null;
        }
    }

    customQrForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        customQrError.classList.add('hidden');

        if (customQrObjectUrl) {
            URL.revokeObjectURL(customQrObjectUrl);
            customQrObjectUrl = null;
        }

        customQrLoading.classList.remove('hidden');
        customQrLoading.classList.add('flex');

        const submitButton = customQrForm.querySelector('button[type="submit"]');
        const originalLabel = submitButton.textContent;
        submitButton.disabled = true;
        submitButton.textContent = 'Generating...';

        try {
            const response = await fetch(customQrForm.action, {
                method: 'POST',
                body: new FormData(customQrForm),
                headers: { Accept: 'application/json, image/svg+xml' },
                credentials: 'same-origin',
                signal: AbortSignal.timeout(30000),
            });
            const contentType = response.headers.get('content-type') || '';

            if (!response.ok || !contentType.includes('image/svg+xml')) {
                let message = response.redirected
                    ? 'Your session expired. Sign in again, then retry.'
                    : 'Could not generate the QR code. Check the content and retry.';

                if (contentType.includes('application/json')) {
                    const details = await response.json();
                    message = details.errors?.content?.[0] || details.message || message;
                }

                throw new Error(message);
            }

            const svg = await response.blob();
            customQrObjectUrl = URL.createObjectURL(svg);
            customQrPreview.src = customQrObjectUrl;
            customQrDownload.href = customQrObjectUrl;
            appendHistoryRow({
                id: response.headers.get('X-QR-History-Id'),
                content: document.getElementById('qr-content').value,
                createdAt: response.headers.get('X-QR-History-Created-At'),
                downloadUrl: response.headers.get('X-QR-History-Download'),
            });
            customQrLoading.classList.add('hidden');
            customQrLoading.classList.remove('flex');
            customQrModal.classList.remove('hidden');
            customQrModal.classList.add('flex');
            document.getElementById('custom-qr-close').focus();
        } catch (error) {
            customQrLoading.classList.add('hidden');
            customQrLoading.classList.remove('flex');
            customQrError.textContent = error.message;
            customQrError.classList.remove('hidden');
        } finally {
            submitButton.disabled = false;
            submitButton.textContent = originalLabel;
        }
    });

    function appendHistoryRow(history) {
        const tableBody = document.getElementById('custom-qr-history');
        tableBody.querySelector('[data-history-empty]')?.remove();

        const row = document.createElement('tr');
        row.dataset.historyId = history.id;

        const contentCell = document.createElement('td');
        contentCell.className = 'max-w-lg truncate px-3 py-3 text-theme-sm text-gray-700 dark:text-gray-300';
        contentCell.textContent = history.content;
        contentCell.title = history.content;

        const createdCell = document.createElement('td');
        createdCell.className = 'whitespace-nowrap px-3 py-3 text-theme-sm text-gray-500 dark:text-gray-400';
        createdCell.textContent = new Date(history.createdAt).toLocaleString();

        const downloadCell = document.createElement('td');
        downloadCell.className = 'px-3 py-3 text-right';
        const downloadLink = document.createElement('a');
        downloadLink.href = history.downloadUrl;
        downloadLink.className = 'text-theme-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400';
        downloadLink.textContent = 'Download SVG';
        downloadCell.append(downloadLink);

        row.append(contentCell, createdCell, downloadCell);
        tableBody.prepend(row);
    }

    document.getElementById('custom-qr-close').addEventListener('click', closeCustomQrModal);
    document.getElementById('custom-qr-done').addEventListener('click', closeCustomQrModal);
    customQrModal.addEventListener('click', (event) => {
        if (event.target === customQrModal) {
            closeCustomQrModal();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !customQrModal.classList.contains('hidden')) {
            closeCustomQrModal();
        }
    });

    // Bulk selection. The checkboxes sit outside the form - one form wraps the
    // whole grid - so each one points at it with the form attribute.
    const labelSelectAll = document.getElementById('label-select-all');
    const labelChecks = [...document.querySelectorAll('.label-select')];
    const labelSelectionCount = document.getElementById('label-selection-count');

    const syncLabelSelection = () => {
        const selected = labelChecks.filter((box) => box.checked).length;

        if (labelSelectionCount) {
            labelSelectionCount.textContent = `${selected} selected`;
        }

        if (labelSelectAll) {
            labelSelectAll.checked = labelChecks.length > 0 && selected === labelChecks.length;
            labelSelectAll.indeterminate = selected > 0 && selected < labelChecks.length;
        }
    };

    labelChecks.forEach((box) => box.addEventListener('change', syncLabelSelection));

    labelSelectAll?.addEventListener('change', () => {
        labelChecks.forEach((box) => { box.checked = labelSelectAll.checked; });
        syncLabelSelection();
    });

    syncLabelSelection();
</script>

<style>
    .label-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
        gap: 12px;
    }

    .label-item {
        break-inside: avoid;
        page-break-inside: avoid;
    }

    .label-check {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-top: 6px;
        font-size: 12px;
        color: #6b7280;
    }

    @media print {
        .no-print { display: none !important; }
        body { background: #fff; }
        .label-grid {
            grid-template-columns: repeat(auto-fill, {{ config('assets.label.width_mm', 90) }}mm);
            gap: {{ config('assets.label.gap_mm', 4) }}mm;
        }
        .asset-label { box-shadow: none !important; }
    }
</style>
@endsection

