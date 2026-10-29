@once
    <style>
        /* Native <dialog> styling.
         *
         * Tailwind's preflight resets `margin` on every element, which removes the
         * `margin: auto` a dialog needs to centre itself - so the modal appeared
         * pinned to the top-left corner of the viewport. The positioning is set
         * here explicitly rather than with utilities, because the browser applies
         * its own dialog defaults after the stylesheet and the result is
         * order-dependent.
         */

        dialog.tracker-dialog {
            margin: auto;
            padding: 0;
            border: none;
            background: transparent;
            max-height: calc(100vh - 2rem);
            overflow: visible;
        }

        /* The dimmed, blurred layer behind the dialog. `::backdrop` is a separate
         * box from the element, so the blur belongs on it - a blurred element would
         * blur its own contents. */
        dialog.tracker-dialog::backdrop {
            background: rgba(9, 12, 20, 0.55);
            backdrop-filter: blur(6px) saturate(120%);
            -webkit-backdrop-filter: blur(6px) saturate(120%);
        }

        /* Entrance: a short fade and lift, in and out, so the dialog does not
         * appear as a hard cut. */
        dialog.tracker-dialog[open] {
            animation: tracker-dialog-in 180ms cubic-bezier(0.16, 1, 0.3, 1);
        }

        dialog.tracker-dialog[open]::backdrop {
            animation: tracker-backdrop-in 180ms ease-out;
        }

        @keyframes tracker-dialog-in {
            from { opacity: 0; transform: translateY(8px) scale(0.98); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        @keyframes tracker-backdrop-in {
            from { opacity: 0; }
            to   { opacity: 1; }
        }

        @media (prefers-reduced-motion: reduce) {
            dialog.tracker-dialog[open],
            dialog.tracker-dialog[open]::backdrop {
                animation: none;
            }
        }
    </style>
@endonce

{{-- One native <dialog> per asset, outside the table.

     These used to be Alpine components driven by a $dispatch from the button.
     That made the button depend on Alpine having started, the element being
     inside a hydrated tree, and the modal having already mounted - and when any
     of those was false the button did nothing and said nothing. A <dialog> is
     opened by showModal() from a plain onclick, so the only thing that can fail
     is the browser, and every browser this ships to supports it. --}}
@foreach($assets as $asset)
    @can('forceDelete', $asset)
        <dialog id="delete-asset-{{ $asset->id }}" class="tracker-dialog w-full max-w-md">
            <form method="POST" action="{{ route('assets.force-destroy', $asset) }}"
                  class="rounded-2xl border border-gray-200 bg-white p-6 text-left shadow-2xl dark:border-gray-700 dark:bg-gray-900">
                @csrf
                @method('DELETE')

                <div class="flex items-start gap-4">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-error-50 text-error-600 dark:bg-error-500/10 dark:text-error-400">
                        <x-icon name="trash" class="h-5 w-5" />
                    </div>
                    <div class="min-w-0">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Hapus aset permanen</h3>
                        <p class="mt-1 text-theme-sm text-gray-600 dark:text-gray-300">
                            <span class="font-mono font-semibold">{{ $asset->asset_id }}</span>
                            @if($asset->brand || $asset->model)
                                · {{ trim($asset->brand.' '.$asset->model) }}
                            @endif
                        </p>
                    </div>
                </div>

                <div class="mt-5 rounded-lg border border-error-200 bg-error-50 px-4 py-3 dark:border-error-500/30 dark:bg-error-500/10">
                    <p class="text-theme-xs text-error-700 dark:text-error-300">
                        Tindakan ini <span class="font-semibold">tidak bisa dibatalkan</span>.
                        Riwayat audit, transfer, perbaikan dan dokumen aset ini ikut terhapus.
                        Untuk sekadar menonaktifkan, gunakan Arsipkan.
                    </p>
                </div>

                <label class="mt-5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300" for="confirm-{{ $asset->id }}">
                    Ketik <span class="font-mono">{{ $asset->asset_id }}</span> untuk konfirmasi
                </label>
                <input id="confirm-{{ $asset->id }}" type="text" name="confirm_asset_id"
                       autocomplete="off" spellcheck="false" required
                       data-expect="{{ $asset->asset_id }}"
                       oninput="this.closest('form').querySelector('button[data-confirm]').disabled = this.value.trim() !== this.dataset.expect"
                       class="mt-2 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-theme-sm text-gray-900 transition focus:border-error-500 focus:ring-2 focus:ring-error-500/20 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                       placeholder="{{ $asset->asset_id }}">

                <div class="mt-6 flex justify-end gap-2">
                    <button type="button"
                            onclick="this.closest('dialog').close()"
                            class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
                        Batal
                    </button>
                    <button type="submit" disabled data-confirm
                            class="rounded-lg bg-error-600 px-4 py-2 text-theme-sm font-semibold text-white transition hover:bg-error-700 disabled:cursor-not-allowed disabled:opacity-40">
                        Hapus permanen
                    </button>
                </div>
            </form>
        </dialog>
    @endcan
@endforeach

<script>
    // A named function rather than an Alpine expression, so the button does not
    // depend on any framework having loaded.
    window.trackerDeleteAsset = function (id) {
        var dialog = document.getElementById(id);

        if (!dialog) {
            console.error('Delete dialog not found:', id);
            return;
        }

        dialog.showModal();

        var field = dialog.querySelector('input[name="confirm_asset_id"]');
        field.value = '';
        field.focus();
    };
</script>
