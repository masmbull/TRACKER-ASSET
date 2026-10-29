@props(['document', 'trigger' => null, 'autoOpen' => false])

{{--
    Document preview as a native <dialog>, driven by plain JavaScript.

    It used to be an Alpine component with x-show and x-teleport, which meant the
    modal only appeared if Alpine had started, the teleport target existed, and
    the component had hydrated. When any of those was false the page showed the
    button and nothing else - no dialog, no error. The delete confirmation had the
    same problem and was rebuilt the same way; this is the second half of it.

    A <dialog> needs none of that: showModal() is a browser call. PDF.js still
    renders into a canvas we own, so there is no browser PDF toolbar.

    The modal is rendered once per component, at the bottom of the page rather than
    where the trigger sits, so it is never nested inside a table or a form.
--}}

@php
    $modalId = 'document-preview-' . $document->getKey();
@endphp

<button type="button" data-pdf-open="{{ $modalId }}" class="contents">
    {{ $trigger ?? $slot }}
</button>

<dialog id="{{ $modalId }}" class="document-dialog w-full max-w-5xl"
        data-pdf-src="{{ route('documents.preview.data', $document) }}"
        data-pdf-auto="{{ $autoOpen ? '1' : '0' }}">
    <div class="flex h-[92vh] flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white text-left shadow-2xl dark:border-gray-700 dark:bg-gray-900">
        <header class="flex shrink-0 items-start justify-between gap-4 border-b border-gray-100 px-5 py-3.5 dark:border-gray-800">
            <div class="min-w-0">
                <p class="truncate text-theme-sm font-semibold text-gray-900 dark:text-white">{{ $document->title }}</p>
                <p class="mt-0.5 truncate text-theme-xs text-gray-500 dark:text-gray-400">
                    {{ $document->document_number }} · {{ $document->document_type }}
                    @if($document->asset) · {{ $document->asset->asset_id }} @endif
                </p>
            </div>

            <div class="flex shrink-0 items-center gap-1.5">
                <a href="{{ route('documents.download', $document) }}"
                   class="inline-flex items-center gap-1.5 rounded-lg bg-success-500 px-3 py-2 text-theme-xs font-semibold text-white transition hover:bg-success-600">
                    <x-icon name="download" class="h-4 w-4" /> Download
                </a>
                <button type="button" data-pdf-print
                        class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-2 text-theme-xs font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
                    Print
                </button>
                <button type="button" data-pdf-close aria-label="Close preview"
                        class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/5 dark:hover:text-gray-200">
                    <x-icon name="close" class="h-5 w-5" />
                </button>
            </div>
        </header>

        <div data-pdf-scroll class="relative min-h-0 flex-1 overflow-auto bg-gray-100 px-4 py-5 dark:bg-gray-950/40">
            <div data-pdf-loading class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-gray-100/80 dark:bg-gray-950/60">
                <div class="h-8 w-8 animate-spin rounded-full border-2 border-gray-300 border-t-brand-500"></div>
                <p class="text-theme-xs text-gray-500 dark:text-gray-400" data-pdf-status>Memuat dokumen…</p>
            </div>

            <p data-pdf-error class="hidden py-10 text-center text-theme-sm text-error-600 dark:text-error-400"></p>

            <div data-pdf-pages class="mx-auto flex max-w-[860px] flex-col items-center gap-4"></div>
        </div>

        <footer data-pdf-footer class="hidden shrink-0 items-center justify-center gap-3 border-t border-gray-100 px-5 py-2.5 text-theme-xs dark:border-gray-800">
            <button type="button" data-pdf-prev
                    class="rounded-lg border border-gray-200 px-2.5 py-1 font-medium text-gray-600 transition disabled:opacity-40 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300">
                Sebelumnya
            </button>
            <span class="tabular-nums text-gray-500 dark:text-gray-400" data-pdf-counter></span>
            <button type="button" data-pdf-next
                    class="rounded-lg border border-gray-200 px-2.5 py-1 font-medium text-gray-600 transition disabled:opacity-40 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300">
                Berikutnya
            </button>
        </footer>
    </div>
</dialog>

@once
    <style>
        /* Tailwind's preflight resets margin, which removes the margin:auto a
           dialog uses to centre itself. Same fix as the delete dialog. */
        dialog.document-dialog { margin: auto; padding: 0; border: none; background: transparent; }
        dialog.document-dialog::backdrop {
            background: rgba(9, 12, 20, 0.6);
            backdrop-filter: blur(6px) saturate(120%);
            -webkit-backdrop-filter: blur(6px) saturate(120%);
        }
        dialog.document-dialog[open] { animation: document-dialog-in 180ms cubic-bezier(0.16, 1, 0.3, 1); }
        @keyframes document-dialog-in {
            from { opacity: 0; transform: translateY(8px) scale(0.98); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }
        @media (prefers-reduced-motion: reduce) {
            dialog.document-dialog[open] { animation: none; }
        }
    </style>

    <script type="module">
        // Loaded lazily: ~350 KB a page with no documents never pays for.
        const PDFJS_VERSION = '4.7.76';
        let pdfjsPromise = null;

        function loadPdfJs() {
            pdfjsPromise ??= import(
                `https://cdn.jsdelivr.net/npm/pdfjs-dist@${PDFJS_VERSION}/build/pdf.min.mjs`
            ).then((module) => {
                module.GlobalWorkerOptions.workerSrc =
                    `https://cdn.jsdelivr.net/npm/pdfjs-dist@${PDFJS_VERSION}/build/pdf.worker.min.mjs`;
                return module;
            });

            return pdfjsPromise;
        }

        async function renderDocument(dialog) {
            if (dialog.dataset.rendered === '1') {
                return;
            }

            const status = dialog.querySelector('[data-pdf-status]');
            const errorBox = dialog.querySelector('[data-pdf-error]');
            const pages = dialog.querySelector('[data-pdf-pages]');
            const footer = dialog.querySelector('[data-pdf-footer]');
            const counter = dialog.querySelector('[data-pdf-counter]');

            try {
                const response = await fetch(dialog.dataset.pdfSrc, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const meta = await response.json();
                const pdfjs = await loadPdfJs();
                const doc = await pdfjs.getDocument({ url: meta.file_url }).promise;

                pages.replaceChildren();

                const canvases = [];

                for (let i = 1; i <= doc.numPages; i++) {
                    const canvas = document.createElement('canvas');
                    canvas.dataset.pageNumber = String(i);
                    canvas.className = 'block max-w-full rounded-sm bg-white shadow-md ring-1 ring-black/5';
                    pages.appendChild(canvas);
                    canvases.push(canvas);
                }

                if (doc.numPages > 1) {
                    footer.classList.remove('hidden');
                    footer.classList.add('flex');
                }

                const ratio = window.devicePixelRatio || 1;
                let current = 1;

                const describe = () => {
                    if (status) {
                        status.textContent = `Memuat halaman ${current} dari ${doc.numPages}…`;
                    }
                    if (counter) {
                        counter.textContent = `Halaman ${current} / ${doc.numPages}`;
                    }
                    dialog.querySelector('[data-pdf-prev]').disabled = current <= 1;
                    dialog.querySelector('[data-pdf-next]').disabled = current >= doc.numPages;
                };

                for (let i = 1; i <= doc.numPages; i++) {
                    current = i;
                    describe();

                    const page = await doc.getPage(i);
                    const base = page.getViewport({ scale: 1 });
                    const scale = Math.min(1.6, 860 / base.width) * ratio;
                    const viewport = page.getViewport({ scale });

                    const canvas = canvases[i - 1];
                    canvas.width = viewport.width;
                    canvas.height = viewport.height;
                    canvas.style.width = `${viewport.width / ratio}px`;
                    canvas.style.height = `${viewport.height / ratio}px`;

                    await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
                }

                dialog.dataset.rendered = '1';
                dialog.querySelector('[data-pdf-loading]')?.classList.add('hidden');

                const goTo = (n) => {
                    const target = Math.min(Math.max(1, n), doc.numPages);
                    current = target;
                    describe();
                    canvases[target - 1]?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                };

                dialog.querySelector('[data-pdf-prev]').onclick = () => goTo(current - 1);
                dialog.querySelector('[data-pdf-next]').onclick = () => goTo(current + 1);

                dialog.querySelector('[data-pdf-print]').onclick = () => {
                    // Printing the PDF itself: window.print() would capture the
                    // whole app shell around the dialog.
                    window.open(meta.download_url + '?inline=1', '_blank')?.print?.();
                };
            } catch (e) {
                console.error('document preview failed', e);
                dialog.querySelector('[data-pdf-loading]')?.classList.add('hidden');

                if (errorBox) {
                    errorBox.textContent = 'Pratinjau tidak bisa dimuat. Coba unduh dokumennya.';
                    errorBox.classList.remove('hidden');
                }
            }
        }

        function openDocument(id) {
            const dialog = document.getElementById(id);

            if (!dialog) {
                console.error('document dialog not found:', id);
                return;
            }

            dialog.showModal();
            renderDocument(dialog);
        }

        document.addEventListener('click', (event) => {
            const opener = event.target.closest('[data-pdf-open]');

            if (opener) {
                event.preventDefault();
                openDocument(opener.dataset.pdfOpen);
                return;
            }

            const closer = event.target.closest('[data-pdf-close]');

            if (closer) {
                closer.closest('dialog')?.close();
            }
        });

        // Backdrop click: the dialog element itself receives the click only when
        // the pointer is outside its content box.
        document.addEventListener('click', (event) => {
            if (event.target instanceof HTMLDialogElement) {
                event.target.close();
            }
        });

        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('dialog.document-dialog[data-pdf-auto="1"]').forEach((dialog) => {
                openDocument(dialog.id);
            });
        });
    </script>
@endonce
