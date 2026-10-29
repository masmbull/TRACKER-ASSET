@props(['document', 'trigger' => null])

{{--
    Document preview as a modal, not a page with an embedded viewer.

    The old preview route put the PDF in a bare <iframe>. That means the browser's
    own PDF plugin decides how it looks, and Chrome/Firefox draw a grey toolbar
    with a download button and a page counter across the top - which is exactly
    what the recipient of a BAST should never see: a document that looks like it
    is being downloaded rather than read. PDF.js renders into a <canvas> we own,
    so the whole chrome belongs to us: no plugin toolbar, no browser download
    button, and identical output in every browser.

    The file is fetched from the controller one page at a time, so a long BAST does
    not have to be downloaded in full before the first page is visible.
--}}

<div x-data="pdfPreview(@js(route('documents.preview.data', $document)))"
     x-on:keydown.arrow-right.window="isOpen && next()"
     x-on:keydown.arrow-left.window="isOpen && prev()"
     x-on:keydown.escape.window="isOpen && close()">

    {{-- The slot is the trigger. It is wrapped so a caller passes any element
         (number link, row action, button) and the click is wired here, rather
         than each call site remembering @click="open()". --}}
    <span x-on:click.prevent="open()" class="contents">{{ $trigger }}</span>

    <template x-teleport="body">
        <div x-show="isOpen" x-cloak
             class="pdf-modal fixed inset-0 z-[100020] flex items-center justify-center p-3 sm:p-6"
             role="dialog" aria-modal="true" aria-label="Document preview">
            <div class="absolute inset-0 bg-gray-950/70 backdrop-blur-sm"
                 x-show="isOpen"
                 x-transition:enter="transition duration-200 ease-out"
                 x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition duration-150 ease-in"
                 x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 @click="close()"></div>

            <div class="relative flex h-full max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-black/10 dark:bg-gray-900 dark:ring-white/10"
                 x-show="isOpen"
                 x-transition:enter="transition duration-300 ease-[cubic-bezier(0.16,1,0.3,1)]"
                 x-transition:enter-start="opacity-0 translate-y-4 scale-[0.97]"
                 x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                 x-transition:leave="transition duration-200 ease-in"
                 x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                 x-transition:leave-end="opacity-0 translate-y-2 scale-[0.98]">

                <header class="flex shrink-0 items-start justify-between gap-4 border-b border-gray-100 px-5 py-3.5 dark:border-gray-800">
                    <div class="min-w-0">
                        <p class="truncate text-theme-sm font-semibold text-gray-900 dark:text-white" x-text="title"></p>
                        <p class="mt-0.5 truncate text-theme-xs text-gray-500 dark:text-gray-400">
                            {{ $document->document_number }}
                            · {{ $document->document_type }}
                            @if($document->asset)
                                · {{ $document->asset->asset_id }}
                            @endif
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-1.5">
                        <a :href="downloadUrl" class="inline-flex items-center gap-1.5 rounded-lg bg-success-500 px-3 py-2 text-theme-xs font-semibold text-white transition hover:bg-success-600 active:scale-[0.97]">
                            <x-icon name="download" class="h-4 w-4" /> Download
                        </a>
                        <button type="button" @click="print()" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-2 text-theme-xs font-semibold text-gray-700 transition hover:bg-gray-50 active:scale-[0.97] dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                            Print
                        </button>
                        <button type="button" @click="close()" aria-label="Close preview"
                                class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/5 dark:hover:text-gray-200">
                            <x-icon name="close" class="h-5 w-5" />
                        </button>
                    </div>
                </header>

                <div class="relative min-h-0 flex-1 overflow-auto bg-gray-100 px-4 py-5 dark:bg-gray-950/40" x-ref="scroller">
                    <div x-show="loading" class="absolute inset-0 flex flex-col items-center justify-center gap-3">
                        <div class="h-8 w-8 animate-spin rounded-full border-2 border-gray-300 border-t-brand-500"></div>
                        <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                            Memuat halaman <span x-text="loaded"></span> dari <span x-text="total || '…'"></span>
                        </p>
                    </div>

                    <div x-show="error" class="flex h-full items-center justify-center">
                        <p class="text-theme-sm text-error-600 dark:text-error-400" x-text="error"></p>
                    </div>

                    <div class="mx-auto flex max-w-[860px] flex-col items-center gap-4">
                        <template x-for="(page, index) in pages" :key="index">
                            <canvas :data-page-number="index + 1"
                                    class="block max-w-full rounded-sm bg-white shadow-md ring-1 ring-black/5"></canvas>
                        </template>
                    </div>
                </div>

                <footer x-show="total > 1" class="flex shrink-0 items-center justify-center gap-3 border-t border-gray-100 px-5 py-2.5 text-theme-xs dark:border-gray-800">
                    <button type="button" @click="prev()" :disabled="page <= 1"
                            class="rounded-lg border border-gray-200 px-2.5 py-1 font-medium text-gray-600 transition disabled:opacity-40 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
                        Sebelumnya
                    </button>
                    <span class="tabular-nums text-gray-500 dark:text-gray-400">
                        Halaman <span x-text="page"></span> / <span x-text="total"></span>
                    </span>
                    <button type="button" @click="next()" :disabled="page >= total"
                            class="rounded-lg border border-gray-200 px-2.5 py-1 font-medium text-gray-600 transition disabled:opacity-40 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
                        Berikutnya
                    </button>
                </footer>
            </div>
        </div>
    </template>
</div>

@once
    <script type="module">
        // pdf.js is loaded lazily, the first time a preview is opened: it is
        // ~350 KB, and a user who never opens a document should not pay for it.
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

        window.pdfPreview = function (dataUrl) {
            return {
                isOpen: false,
                loading: false,
                error: '',
                total: 0,
                loaded: 0,
                page: 1,
                pages: [],
                title: '',
                downloadUrl: '',
                _pdf: null,

                open() {
                    this.isOpen = true;
                    document.body.style.overflow = 'hidden';

                    if (!this._pdf) {
                        this.load();
                    }
                },

                close() {
                    this.isOpen = false;
                    document.body.style.overflow = '';

                    // The canvases are kept so reopening is instant; only a page
                    // that was never rendered is drawn on the next open.
                    this.renderMissing();
                },

                async load() {
                    this.loading = true;
                    this.error = '';

                    try {
                        const pdfjs = await loadPdfJs();
                        const response = await fetch(dataUrl, {
                            headers: { Accept: 'application/json' },
                            credentials: 'same-origin',
                        });

                        if (!response.ok) {
                            throw new Error(`HTTP ${response.status}`);
                        }

                        const meta = await response.json();

                        this.title = meta.title ?? '';
                        this.downloadUrl = meta.download_url ?? dataUrl;
                        this.total = meta.pages ?? 0;

                        // One entry per page keeps x-for stable; null means "not
                        // rendered yet", so the spinner knows how far it got.
                        this.pages = Array.from({ length: this.total }, () => null);

                        const doc = await pdfjs.getDocument({ url: meta.file_url }).promise;
                        this._pdf = doc;
                        this.total = doc.numPages;
                        this.pages = Array.from({ length: doc.numPages }, () => null);

                        await this.renderMissing();
                    } catch (e) {
                        this.error = 'Pratinjau tidak bisa dimuat. Coba unduh dokumennya.';
                        console.error('pdf preview failed', e);
                    } finally {
                        this.loading = false;
                    }
                },

                async renderMissing() {
                    if (!this._pdf || !this.isOpen) {
                        return;
                    }

                    this.loading = true;

                    try {
                        for (let i = 1; i <= this._pdf.numPages; i++) {
                            if (this.pages[i - 1]) {
                                continue;
                            }

                            const page = await this._pdf.getPage(i);
                            const viewport = page.getViewport({ scale: 1 });

                            // Fit the page to the column but keep text crisp on
                            // high-DPI screens, which is the whole point of
                            // rendering instead of showing the plugin.
                            const maxWidth = 860;
                            const ratio = window.devicePixelRatio || 1;
                            const cssScale = Math.min(1.6, maxWidth / viewport.width);
                            const scaled = page.getViewport({ scale: cssScale * ratio });

                            await this.$nextTick();

                            const canvas = this.$el.querySelector(`canvas[data-page-number="${i}"]`);
                            if (!canvas) {
                                continue;
                            }

                            canvas.width = scaled.width;
                            canvas.height = scaled.height;
                            canvas.style.width = `${scaled.width / ratio}px`;
                            canvas.style.height = `${scaled.height / ratio}px`;

                            await page.render({
                                canvasContext: canvas.getContext('2d'),
                                viewport: scaled,
                            }).promise;

                            this.pages[i - 1] = true;
                            this.loaded = i;
                        }
                    } finally {
                        this.loading = false;
                    }
                },

                goTo(number) {
                    this.page = Math.min(Math.max(1, number), this.total || 1);
                    const canvas = this.$el.querySelector(`canvas[data-page-number="${this.page}"]`);
                    canvas?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                },

                next() {
                    this.goTo(this.page + 1);
                },

                prev() {
                    this.goTo(this.page - 1);
                },

                print() {
                    // Printing the PDF itself, not the page: window.print() would
                    // capture the app shell and the modal chrome around it.
                    window.open(this.downloadUrl + '?inline=1', '_blank')?.print?.();
                },
            };
        };
    </script>
@endonce
