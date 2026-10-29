@props(['document'])

{{--
    Inline PDF renderer: the document page itself, not a modal around it.

    Split from the modal component because the two use cases differ in exactly one
    way - one floats over a list, the other fills a page - while the rendering,
    toolbar and print behaviour are the same. Duplicating the PDF.js loop in both
    would mean fixing every future bug twice.
--}}
<div x-data="pdfViewer(@js(route('documents.preview.data', $document)))"
     x-init="init()"
     class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-theme-xs dark:border-gray-800 dark:bg-gray-900">

    <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-2.5 dark:border-gray-800">
        <p class="text-theme-xs text-gray-500 dark:text-gray-400">
            <span x-show="loading">Memuat…</span>
            <span x-show="!loading && total > 0">Halaman <span class="font-medium text-gray-700 dark:text-gray-200" x-text="page"></span> dari <span x-text="total"></span></span>
        </p>

        <div class="flex items-center gap-1.5">
            <button type="button" @click="prev()" :disabled="page <= 1"
                    class="rounded-lg border border-gray-200 px-2.5 py-1 text-theme-xs font-medium text-gray-600 transition disabled:opacity-40 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
                Sebelumnya
            </button>
            <button type="button" @click="next()" :disabled="page >= total"
                    class="rounded-lg border border-gray-200 px-2.5 py-1 text-theme-xs font-medium text-gray-600 transition disabled:opacity-40 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
                Berikutnya
            </button>
        </div>
    </div>

    <div class="relative max-h-[78vh] overflow-auto bg-gray-100 px-3 py-4 dark:bg-gray-950/40">
        <div x-show="loading" class="absolute inset-0 flex items-center justify-center">
            <div class="h-8 w-8 animate-spin rounded-full border-2 border-gray-300 border-t-brand-500"></div>
        </div>

        <p x-show="error" class="py-10 text-center text-theme-sm text-error-600 dark:text-error-400" x-text="error"></p>

        <div class="mx-auto flex max-w-[820px] flex-col items-center gap-4">
            <template x-for="(rendered, index) in pages" :key="index">
                <canvas :data-page-number="index + 1"
                        class="block max-w-full rounded-sm bg-white shadow-md ring-1 ring-black/5"></canvas>
            </template>
        </div>
    </div>
</div>

@once
    <script type="module">
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

        window.pdfViewer = function (dataUrl) {
            return {
                loading: true,
                error: '',
                total: 0,
                page: 1,
                pages: [],
                _pdf: null,
                _downloadUrl: '',

                async init() {
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
                        this._downloadUrl = meta.download_url ?? '';

                        const doc = await pdfjs.getDocument({ url: meta.file_url }).promise;
                        this._pdf = doc;
                        this.total = doc.numPages;
                        this.pages = Array.from({ length: doc.numPages }, () => true);

                        await this.$nextTick();
                        await this.renderAll();
                    } catch (e) {
                        this.error = 'Pratinjau tidak bisa dimuat. Coba unduh dokumennya.';
                        console.error('pdf viewer failed', e);
                    } finally {
                        this.loading = false;
                    }

                    document.getElementById('pdf-print')?.addEventListener('click', () => {
                        // Print the PDF itself: window.print() would capture the
                        // whole app shell and every page of it.
                        window.open(this._downloadUrl + '?inline=1', '_blank')?.print?.();
                    });
                },

                async renderAll() {
                    const ratio = window.devicePixelRatio || 1;

                    for (let i = 1; i <= this._pdf.numPages; i++) {
                        const page = await this._pdf.getPage(i);
                        const base = page.getViewport({ scale: 1 });
                        const cssScale = Math.min(1.5, 820 / base.width);
                        const viewport = page.getViewport({ scale: cssScale * ratio });

                        const canvas = this.$el.querySelector(`canvas[data-page-number="${i}"]`);

                        if (!canvas) {
                            continue;
                        }

                        canvas.width = viewport.width;
                        canvas.height = viewport.height;
                        canvas.style.width = `${viewport.width / ratio}px`;
                        canvas.style.height = `${viewport.height / ratio}px`;

                        await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
                    }
                },

                goTo(number) {
                    this.page = Math.min(Math.max(1, number), this.total || 1);
                    this.$el.querySelector(`canvas[data-page-number="${this.page}"]`)
                        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                },

                next() { this.goTo(this.page + 1); },
                prev() { this.goTo(this.page - 1); },
            };
        };
    </script>
@endonce
