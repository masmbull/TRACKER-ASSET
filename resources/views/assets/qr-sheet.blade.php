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

    Every visual rule below is scoped to `.asset-qr-page`. Nothing here is a
    shared component style, so the dashboard, the asset pages and the modules
    that reuse `x-card` or `x-asset-label` keep the look they already have.
--}}
<div class="asset-qr-page">
    <div class="no-print">
        <x-page-header title="QR Labels" subtitle="Printable asset labels · MITO GROUP ASSETS. Each code opens the asset page when scanned.">
            <x-slot:actions>
                <x-button variant="primary" size="sm" onclick="window.print()">Print</x-button>
            </x-slot:actions>
        </x-page-header>

        {{-- Wide horizontal action panels: the icon and the copy sit on the left,
             the control runs the rest of the row instead of being boxed into a
             square card. --}}
        <section class="qr-panel">
            <div class="qr-panel-intro">
                <span class="qr-panel-icon" aria-hidden="true"><x-icon name="plus" class="h-5 w-5" /></span>
                <div>
                    <h2 class="qr-panel-title">Create a QR code</h2>
                    <p class="qr-panel-text">Paste a link or type text. The generated code carries the MITO logo.</p>
                </div>
            </div>
            <form id="custom-qr-form" method="POST" action="{{ route('assets.qr.custom') }}" class="qr-panel-form" data-no-busy>
                @csrf
                <label for="qr-content" class="qr-sr">Text or URL</label>
                <textarea id="qr-content" name="content" rows="2" maxlength="500" required
                          placeholder="Paste a link or type text to encode"
                          class="qr-input qr-textarea"></textarea>
                <p class="qr-hint">Up to 500 characters.</p>
                <div class="qr-panel-actions">
                    <x-button type="submit" variant="primary">Generate QR</x-button>
                    <p id="custom-qr-error" class="qr-error hidden" role="alert"></p>
                </div>
            </form>
        </section>

        <section class="qr-panel">
            <div class="qr-panel-intro">
                <span class="qr-panel-icon" aria-hidden="true"><x-icon name="search" class="h-5 w-5" /></span>
                <div>
                    <h2 class="qr-panel-title">Scan or find an asset</h2>
                    <p class="qr-panel-text">Scan a barcode or QR label, or type an asset ID, serial number, or hostname.</p>
                </div>
            </div>
            <form method="POST" action="{{ route('barcode.resolve') }}" class="qr-panel-form">
                @csrf
                <label for="qr-scan-code" class="qr-sr">Scan or enter asset code</label>
                <input type="text" id="qr-scan-code" name="code" value="{{ old('code') }}" required
                       placeholder="Scan or enter asset code"
                       class="qr-input qr-mono">
                <div class="qr-panel-actions">
                    <x-button type="submit" variant="secondary">Find asset</x-button>
                    @error('code')
                        <p class="qr-error" role="alert">{{ $message }}</p>
                    @enderror
                </div>
            </form>
        </section>

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

        {{-- Filters are one wide bar rather than a card: the fields line up in a
             single row and the whole strip scrolls horizontally on small
             screens instead of stacking into five boxes. --}}
        <section class="qr-bar" aria-label="Filter labels">
            <form method="GET" action="/assets/qr" class="qr-bar-form">
                <div class="qr-field">
                    <label for="search">Search</label>
                    <input type="text" id="search" name="search" value="{{ request('search') }}" placeholder="Asset ID, serial, hostname, model" class="qr-input">
                </div>
                <div class="qr-field">
                    <label for="company">Company</label>
                    <select id="company" name="company" class="qr-select">
                        <option value="">All</option>
                        @foreach($companies as $company)
                            <option value="{{ $company->id }}" {{ (string) request('company') === (string) $company->id ? 'selected' : '' }}>{{ $company->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="qr-field">
                    <label for="category">Category</label>
                    <select id="category" name="category" class="qr-select">
                        <option value="">All</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}" {{ (string) request('category') === (string) $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="qr-field">
                    <label for="location">Location</label>
                    <select id="location" name="location" class="qr-select">
                        <option value="">All</option>
                        @foreach($locations as $location)
                            <option value="{{ $location->id }}" {{ (string) request('location') === (string) $location->id ? 'selected' : '' }}>{{ $location->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="qr-field">
                    <label for="status">Status</label>
                    <select id="status" name="status" class="qr-select">
                        <option value="">All</option>
                        @foreach($statuses as $status)
                            <option value="{{ $status->id }}" {{ (string) request('status') === (string) $status->id ? 'selected' : '' }}>{{ $status->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="qr-bar-actions">
                    <x-button type="submit" variant="primary">Apply</x-button>
                    <a href="/assets/qr" class="qr-clear">Clear</a>
                </div>
            </form>
        </section>

        <section class="qr-bar qr-history" aria-labelledby="qr-history-title">
            <div class="qr-bar-head">
                <h2 id="qr-history-title" class="qr-panel-title">Generated QR history</h2>
                <span class="qr-counter" id="qr-history-count">{{ $qrHistory->total() }}</span>
            </div>
            <div class="qr-table-wrap">
                <table class="qr-table">
                    <thead>
                        <tr>
                            <th>Content</th>
                            <th>Created</th>
                            <th class="qr-ta-right">Download</th>
                        </tr>
                    </thead>
                    <tbody id="custom-qr-history">
                        @forelse($qrHistory as $history)
                            <tr data-history-id="{{ $history->id }}">
                                <td class="qr-cell-content" title="{{ $history->content }}">{{ $history->content }}</td>
                                <td class="qr-cell-date">{{ $history->created_at->format('Y-m-d H:i') }}</td>
                                <td class="qr-ta-right"><a href="{{ route('assets.qr.history.download', $history) }}" class="qr-link">Download SVG</a></td>
                            </tr>
                        @empty
                            <tr data-history-empty>
                                <td colspan="3" class="qr-cell-empty">Generated QR codes will appear here.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($qrHistory->hasPages())
                <div class="qr-table-foot">{{ $qrHistory->links() }}</div>
            @endif
        </section>

        <p class="qr-summary">
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
            <div class="qr-warning">
                <x-icon name="warning" class="mt-0.5 h-[18px] w-[18px] shrink-0" />
                <span>These labels would encode <code class="qr-code">{{ $qrBase ?: 'a relative URL' }}</code> — a phone that scans them cannot open that. Set <code class="qr-code">ASSET_QR_BASE_URL</code> to the address your fleet can reach before printing.</span>
            </div>
        @endif

        @if($assets->lastPage() > 1)
            <div class="qr-table-foot">{{ $assets->links() }}</div>
        @endif
    </div>

    {{-- Bulk actions stay a strip, not a card, so the eye goes straight from it
         into the labels it applies to. --}}
    <div class="qr-bulkbar no-print">
        <form id="bulk-label-form" method="GET" action="{{ route('assets.qr.print') }}" class="qr-bulkbar-form">
            @foreach(['search', 'company', 'category', 'location', 'status'] as $filter)
                <input type="hidden" name="{{ $filter }}" value="{{ request($filter) }}">
            @endforeach
            <label class="qr-bulkbar-check">
                <input type="checkbox" id="label-select-all" class="qr-checkbox">
                <span>Select all</span>
            </label>
            <span id="label-selection-count" class="qr-bulkbar-count">0 selected</span>
            <span class="qr-bulkbar-spacer"></span>
            <x-button type="submit" variant="primary" size="sm">Print selected</x-button>
            <x-button type="submit" variant="secondary" size="sm" formaction="{{ route('assets.qr.pdf') }}">Download PDF</x-button>
        </form>
        <button type="button" class="qr-print-link" onclick="window.print()">Print this page</button>
    </div>

    {{-- Each label is a wide row: the sticker keeps its physical size on the
         left, its identity and the select box sit to the right. --}}
    <div class="label-grid qr-label-grid">
        @forelse($assets as $asset)
            <article class="label-item qr-label-card">
                <div class="qr-label-preview">
                    <x-asset-label :asset="$asset" :qr-src="route('assets.qr', [$asset, 'plain' => 1])" />
                </div>
                <div class="qr-label-meta">
                    <p class="qr-label-id">{{ $asset->asset_id }}</p>
                    <p class="qr-label-sub">{{ trim(($asset->brand ?? '').' '.($asset->model ?? '')) !== '' ? trim(($asset->brand ?? '').' '.($asset->model ?? '')) : '—' }}</p>
                    <label class="label-check no-print">
                        <input type="checkbox" name="ids[]" value="{{ $asset->id }}" form="bulk-label-form" class="label-select qr-checkbox">
                        <span>Select</span>
                    </label>
                </div>
            </article>
        @empty
            <p class="qr-empty">
                {{ request()->anyFilled(['search', 'company', 'category', 'location', 'status']) ? 'No assets match this filter.' : 'No active assets to label yet.' }}
            </p>
        @endforelse
    </div>
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
        contentCell.className = 'qr-cell-content';
        contentCell.textContent = history.content;
        contentCell.title = history.content;

        const createdCell = document.createElement('td');
        createdCell.className = 'qr-cell-date';
        createdCell.textContent = new Date(history.createdAt).toLocaleString();

        const downloadCell = document.createElement('td');
        downloadCell.className = 'qr-ta-right';
        const downloadLink = document.createElement('a');
        downloadLink.href = history.downloadUrl;
        downloadLink.className = 'qr-link';
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
    /* ---------------------------------------------------------------------
       Page-scoped look. Every selector is prefixed with `.asset-qr-page`, so
       the dashboard, the asset pages and any other module that renders
       `x-card` or `x-asset-label` are untouched.
       --------------------------------------------------------------------- */
    .asset-qr-page {
        --qr-surface: #ffffff;
        --qr-surface-soft: #f9fafb;
        --qr-border: #e5e7eb;
        --qr-text: #111827;
        --qr-muted: #6b7280;
        --qr-accent: #dc2626;
        --qr-radius: 16px;
        --qr-shadow: 0 1px 2px rgba(16, 24, 40, .05);
        display: flex;
        flex-direction: column;
        gap: 12px;
        color: var(--qr-text);
    }

    .dark .asset-qr-page {
        --qr-surface: #111827;
        --qr-surface-soft: #1f2937;
        --qr-border: #1f2937;
        --qr-text: #f9fafb;
        --qr-muted: #9ca3af;
        --qr-shadow: none;
    }

    .asset-qr-page .qr-sr {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }

    /* Wide horizontal action panels ------------------------------------- */
    .asset-qr-page .qr-panel {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 16px;
        padding: 14px 20px;
        min-height: 84px;
        background: var(--qr-surface);
        border: 1px solid var(--qr-border);
        border-radius: var(--qr-radius);
        box-shadow: var(--qr-shadow);
    }

    .asset-qr-page .qr-panel-intro {
        display: flex;
        align-items: center;
        gap: 12px;
        flex: 0 1 340px;
        min-width: 0;
    }

    .asset-qr-page .qr-panel-icon {
        display: grid;
        place-items: center;
        flex: 0 0 auto;
        width: 40px;
        height: 40px;
        border-radius: 12px;
        color: var(--qr-accent);
        background: color-mix(in srgb, var(--qr-accent) 10%, transparent);
    }

    .asset-qr-page .qr-panel-title {
        margin: 0;
        font-size: 15px;
        font-weight: 600;
        letter-spacing: -.01em;
        color: var(--qr-text);
    }

    .asset-qr-page .qr-panel-text {
        margin: 2px 0 0;
        font-size: 12.5px;
        line-height: 1.45;
        color: var(--qr-muted);
    }

    .asset-qr-page .qr-panel-form {
        display: flex;
        flex: 1 1 420px;
        flex-wrap: wrap;
        align-items: center;
        gap: 10px;
        min-width: 0;
    }

    .asset-qr-page .qr-textarea {
        flex: 1 1 320px;
        min-height: 44px;
        max-height: 160px;
        resize: vertical;
    }

    .asset-qr-page .qr-hint {
        flex: 0 0 auto;
        margin: 0;
        font-size: 11.5px;
        color: var(--qr-muted);
    }

    .asset-qr-page .qr-panel-actions {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-left: auto;
    }

    .asset-qr-page .qr-mono {
        flex: 1 1 260px;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    }

    .asset-qr-page .qr-error {
        margin: 0;
        font-size: 12.5px;
        color: var(--qr-accent);
    }

    /* Inputs ------------------------------------------------------------- */
    .asset-qr-page .qr-input,
    .asset-qr-page .qr-select {
        width: 100%;
        min-width: 0;
        padding: 9px 12px;
        font-size: 13px;
        line-height: 1.35;
        color: var(--qr-text);
        background: var(--qr-surface);
        border: 1px solid var(--qr-border);
        border-radius: 10px;
        transition: border-color .15s ease, box-shadow .15s ease;
    }

    .asset-qr-page .qr-input::placeholder {
        color: var(--qr-muted);
    }

    .asset-qr-page .qr-input:focus,
    .asset-qr-page .qr-select:focus {
        outline: none;
        border-color: var(--qr-accent);
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--qr-accent) 18%, transparent);
    }

    .asset-qr-page .qr-select {
        appearance: none;
        padding-right: 32px;
        background-image: linear-gradient(45deg, transparent 50%, var(--qr-muted) 50%),
                          linear-gradient(135deg, var(--qr-muted) 50%, transparent 50%);
        background-position: calc(100% - 18px) 52%, calc(100% - 13px) 52%;
        background-size: 5px 5px, 5px 5px;
        background-repeat: no-repeat;
    }

    .asset-qr-page .qr-checkbox {
        width: 16px;
        height: 16px;
        accent-color: var(--qr-accent);
        cursor: pointer;
    }

    /* Filter + history bars --------------------------------------------- */
    .asset-qr-page .qr-bar {
        padding: 14px 20px;
        background: var(--qr-surface);
        border: 1px solid var(--qr-border);
        border-radius: var(--qr-radius);
        box-shadow: var(--qr-shadow);
    }

    .asset-qr-page .qr-bar-form {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-end;
        gap: 10px;
    }

    .asset-qr-page .qr-field {
        display: flex;
        flex: 1 1 140px;
        flex-direction: column;
        gap: 5px;
        min-width: 118px;
    }

    .asset-qr-page .qr-field > label {
        font-size: 10.5px;
        font-weight: 700;
        letter-spacing: .06em;
        text-transform: uppercase;
        color: var(--qr-muted);
    }

    .asset-qr-page .qr-bar-actions {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-left: auto;
    }

    .asset-qr-page .qr-clear {
        padding: 8px 4px;
        font-size: 12.5px;
        color: var(--qr-muted);
        text-decoration: none;
    }

    .asset-qr-page .qr-clear:hover {
        color: var(--qr-text);
    }

    .asset-qr-page .qr-bar-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 10px;
    }

    .asset-qr-page .qr-counter {
        padding: 2px 8px;
        font-size: 11.5px;
        font-weight: 600;
        color: var(--qr-muted);
        background: var(--qr-surface-soft);
        border: 1px solid var(--qr-border);
        border-radius: 999px;
    }

    .asset-qr-page .qr-table-wrap {
        overflow-x: auto;
    }

    .asset-qr-page .qr-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }

    .asset-qr-page .qr-table th {
        padding: 0 8px 8px;
        font-size: 10.5px;
        font-weight: 700;
        letter-spacing: .06em;
        text-align: left;
        text-transform: uppercase;
        color: var(--qr-muted);
        border-bottom: 1px solid var(--qr-border);
    }

    .asset-qr-page .qr-table td {
        padding: 9px 8px;
        border-bottom: 1px solid var(--qr-border);
        vertical-align: middle;
    }

    .asset-qr-page .qr-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .asset-qr-page .qr-cell-content {
        max-width: 32rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        color: var(--qr-text);
    }

    .asset-qr-page .qr-cell-date {
        white-space: nowrap;
        color: var(--qr-muted);
    }

    .asset-qr-page .qr-cell-empty {
        padding: 18px 8px;
        text-align: center;
        color: var(--qr-muted);
    }

    .asset-qr-page .qr-ta-right {
        text-align: right;
    }

    .asset-qr-page .qr-link {
        font-weight: 600;
        color: var(--qr-accent);
        text-decoration: none;
    }

    .asset-qr-page .qr-link:hover {
        text-decoration: underline;
    }

    .asset-qr-page .qr-table-foot {
        margin-top: 10px;
    }

    .asset-qr-page .qr-summary {
        margin: 2px 2px 0;
        font-size: 12.5px;
        color: var(--qr-muted);
    }

    .asset-qr-page .qr-warning {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 12px 16px;
        font-size: 12.5px;
        line-height: 1.5;
        color: #b91c1c;
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: 12px;
    }

    .dark .asset-qr-page .qr-warning {
        color: #fca5a5;
        background: rgba(220, 38, 38, .12);
        border-color: rgba(220, 38, 38, .35);
    }

    .asset-qr-page .qr-code {
        padding: 1px 5px;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        background: rgba(185, 28, 28, .1);
        border-radius: 5px;
    }

    /* Bulk bar ----------------------------------------------------------- */
    .asset-qr-page .qr-bulkbar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 14px;
        padding: 10px 20px;
        background: var(--qr-surface);
        border: 1px solid var(--qr-border);
        border-radius: var(--qr-radius);
        box-shadow: var(--qr-shadow);
    }

    .asset-qr-page .qr-bulkbar-form {
        display: flex;
        flex: 1 1 auto;
        flex-wrap: wrap;
        align-items: center;
        gap: 12px;
        min-width: 0;
    }

    .asset-qr-page .qr-bulkbar-check {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 12.5px;
        color: var(--qr-text);
        cursor: pointer;
    }

    .asset-qr-page .qr-bulkbar-count {
        font-size: 12.5px;
        color: var(--qr-muted);
    }

    .asset-qr-page .qr-bulkbar-spacer {
        flex: 1 1 auto;
    }

    .asset-qr-page .qr-print-link {
        padding: 6px 4px;
        font-size: 12.5px;
        font-weight: 500;
        color: var(--qr-muted);
        background: none;
        border: 0;
        cursor: pointer;
    }

    .asset-qr-page .qr-print-link:hover {
        color: var(--qr-text);
    }

    /* Labels: wide rows --------------------------------------------------- */
    .asset-qr-page .label-grid {
        grid-template-columns: repeat(auto-fill, minmax(560px, 1fr));
        gap: 12px;
    }

    .asset-qr-page .qr-label-card {
        display: flex;
        align-items: center;
        gap: 14px;
        min-height: 96px;
        padding: 12px 14px;
        background: var(--qr-surface);
        border: 1px solid var(--qr-border);
        border-radius: 14px;
        box-shadow: var(--qr-shadow);
        transition: border-color .15s ease, transform .15s ease;
    }

    .asset-qr-page .qr-label-card:hover {
        border-color: color-mix(in srgb, var(--qr-accent) 45%, var(--qr-border));
        transform: translateY(-1px);
    }

    .asset-qr-page .qr-label-preview {
        flex: 0 0 auto;
        max-width: 100%;
    }

    .asset-qr-page .qr-label-meta {
        display: flex;
        flex: 1 1 auto;
        flex-direction: column;
        gap: 2px;
        min-width: 0;
    }

    .asset-qr-page .qr-label-id {
        margin: 0;
        overflow: hidden;
        font-size: 13px;
        font-weight: 700;
        color: var(--qr-text);
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .asset-qr-page .qr-label-sub {
        margin: 0;
        overflow: hidden;
        font-size: 11.5px;
        color: var(--qr-muted);
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .asset-qr-page .qr-label-meta .label-check {
        margin-top: 6px;
        font-size: 11.5px;
    }

    .asset-qr-page .qr-empty {
        grid-column: 1 / -1;
        padding: 40px 12px;
        font-size: 13px;
        text-align: center;
        color: var(--qr-muted);
        background: var(--qr-surface);
        border: 1px dashed var(--qr-border);
        border-radius: 14px;
    }

    @media (max-width: 640px) {
        .asset-qr-page .qr-panel {
            padding: 14px 16px;
        }

        .asset-qr-page .qr-panel-intro {
            flex: 1 1 100%;
        }

        .asset-qr-page .qr-panel-form {
            flex: 1 1 100%;
        }

        .asset-qr-page .qr-panel-actions {
            margin-left: 0;
        }

        .asset-qr-page .qr-bar,
        .asset-qr-page .qr-bulkbar {
            padding: 12px 16px;
        }

        .asset-qr-page .qr-field {
            flex: 1 1 100%;
        }

        .asset-qr-page .qr-label-grid {
            grid-template-columns: 1fr;
        }

        .asset-qr-page .qr-label-card {
            flex-wrap: wrap;
        }

        .asset-qr-page .qr-label-meta {
            flex: 1 1 100%;
            flex-direction: row;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 6px 12px;
        }

        .asset-qr-page .qr-label-meta .label-check {
            margin-top: 0;
        }
    }

    @media print {
        .no-print { display: none !important; }
        body { background: #fff; }

        .asset-qr-page .label-grid {
            grid-template-columns: repeat(auto-fill, {{ config('assets.label.width_mm', 90) }}mm);
            gap: {{ config('assets.label.gap_mm', 4) }}mm;
        }

        .asset-qr-page .qr-label-card {
            display: block;
            padding: 0;
            background: none;
            border: 0;
            box-shadow: none;
        }

        .asset-qr-page .qr-label-meta {
            display: none !important;
        }

        .asset-label { box-shadow: none !important; }
    }
</style>
@endsection