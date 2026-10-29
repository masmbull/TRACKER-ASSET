@extends('layouts.app')

@section('title', 'Assets')
@section('header', 'All Assets')

@section('content')
<x-page-header title="Assets" :subtitle="$assets->total() . ' total'">
    <x-slot:actions>
        @can('export-assets')
            <x-button variant="success" size="sm" :href="route('assets.export', request()->query())">Export</x-button>
        @endcan
        @can('import-assets')
            <x-button variant="secondary" size="sm" :href="route('assets.import')">Import</x-button>
        @endcan
        @can('create', App\Models\Asset::class)
            <x-button variant="primary" size="sm" :href="route('assets.create')">+ Add Asset</x-button>
        @endcan
    </x-slot:actions>
</x-page-header>

<x-card class="mb-6" x-data="{ showFilters: {{ request()->hasAny(['status','category','company','location']) ? 'true' : 'false' }} }">
    <form method="GET" action="{{ route('assets.index') }}">
        <div class="flex flex-col gap-3 sm:flex-row">
            <div class="flex-1 relative">
                <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2">
                    <x-icon name="search" class="h-5 w-5 shrink-0 text-gray-400" />
                </span>
                <input type="text" name="search" value="{{ request('search') }}" data-autosearch autocomplete="off"
                       placeholder="Search or type command..."
                       class="dark:bg-dark-900 w-full rounded-lg border border-gray-200 bg-transparent py-2.5 pl-12 pr-4 text-theme-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:ring-brand-500/10 focus:ring-3 focus:outline-none dark:border-gray-800 dark:text-white/90 dark:placeholder:text-white/30">
            </div>
            <div class="grid grid-cols-3 gap-2 sm:flex">
                <x-button type="submit" size="md" class="w-full sm:w-auto">Search</x-button>
                <x-button type="button" variant="secondary" size="md" class="w-full sm:w-auto" @click="showFilters = !showFilters">Filters</x-button>
                <a href="{{ route('assets.index') }}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-4 py-2.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">Reset</a>
            </div>
        </div>

        <div x-show="showFilters" x-collapse x-cloak class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <x-select name="status" label="Status">
                <option value="">All</option>
                @foreach($statuses as $status)
                    <option value="{{ $status->id }}" {{ (string) request('status') === (string) $status->id ? 'selected' : '' }}>{{ $status->name }}</option>
                @endforeach
            </x-select>
            <x-select name="category" label="Category">
                <option value="">All</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}" {{ (string) request('category') === (string) $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                @endforeach
            </x-select>
            <x-select name="company" label="Company">
                <option value="">All</option>
                @foreach($companies as $company)
                    <option value="{{ $company->id }}" {{ (string) request('company') === (string) $company->id ? 'selected' : '' }}>{{ $company->name }}</option>
                @endforeach
            </x-select>
            <x-select name="location" label="Location">
                <option value="">All</option>
                @foreach($locations as $location)
                    <option value="{{ $location->id }}" {{ (string) request('location') === (string) $location->id ? 'selected' : '' }}>{{ $location->name }}</option>
                @endforeach
            </x-select>
            <x-select name="sort" label="Sort">
                @foreach(['updated_at' => 'Updated', 'asset_id' => 'Asset ID', 'serial_number' => 'Serial', 'hostname' => 'Hostname'] as $value => $label)
                    <option value="{{ $value }}" {{ request('sort', 'updated_at') === $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </x-select>
        </div>
        <div x-show="showFilters" x-collapse x-cloak class="mt-3">
            <label class="inline-flex items-center gap-2 text-theme-sm text-gray-600 dark:text-gray-400 cursor-pointer">
                <input type="checkbox" name="archived" value="include" {{ request('archived') === 'include' ? 'checked' : '' }}
                       class="h-4 w-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500/10">
                Include archived assets
            </label>
        </div>
    </form>
</x-card>

@if($assets->count() > 0)
    <x-card>
        <x-table :headers="['Asset ID', 'Category', 'Brand/Model', 'Serial', 'Hostname', 'Location', 'User', 'Status', 'QR', 'Actions']">
            @foreach($assets as $asset)
                <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-white/5 transition-colors">
                    <td class="py-3 px-4"><a href="{{ route('assets.show', $asset) }}" class="text-theme-sm font-semibold text-brand-500 hover:text-brand-600">{{ $asset->asset_id }}</a></td>
                    <td class="py-3 px-4 text-theme-sm text-gray-600 dark:text-gray-400">{{ $asset->category?->name }}</td>
                    <td class="py-3 px-4 text-theme-sm text-gray-700 dark:text-gray-300">{{ $asset->brand }} {{ $asset->model }}</td>
                    <td class="py-3 px-4 text-theme-xs text-gray-500 font-mono">{{ $asset->serial_number }}</td>
                    <td class="py-3 px-4 text-theme-xs text-gray-500 font-mono">{{ $asset->hostname }}</td>
                    <td class="py-3 px-4 text-theme-sm text-gray-600">{{ $asset->location?->name }}</td>
                    <td class="py-3 px-4 text-theme-sm text-gray-600">{{ $asset->currentUser?->name }}</td>
                    <td class="py-3 px-4">
                        @php
                            $badgeMap = ['Active' => 'success', 'Available' => 'brand', 'Assigned' => 'warning', 'Repair' => 'orange', 'Retired' => 'gray', 'Disposed' => 'error'];
                        @endphp
                        <x-badge :variant="$badgeMap[$asset->status?->name] ?? 'gray'">{{ $asset->status?->name }}</x-badge>
                    </td>
                    <td class="py-3 px-4">
                        @can('update', $asset)
                            @if($asset->hasQr())
                                <div class="flex items-center gap-2 whitespace-nowrap">
                                    <a href="{{ route('assets.public.pdf', $asset->qr_token) }}" class="text-theme-xs font-medium text-brand-500 hover:text-brand-600" title="Download the asset document PDF this QR opens">PDF</a>
                                    <a href="{{ route('assets.qr', $asset) }}" class="text-theme-xs font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400" title="Download the QR image">QR</a>
                                    <form method="POST" action="{{ route('assets.qr.generate', $asset) }}" onsubmit="return confirm('Re-create the QR for {{ $asset->asset_id }}? Any label already printed for it will stop working.');">
                                        @csrf
                                        <input type="hidden" name="regenerate" value="1">
                                        <button type="submit" class="text-theme-xs font-medium text-warning-600 hover:text-warning-700" title="Mint a new token; old labels stop working">Re-create</button>
                                    </form>
                                </div>
                            @else
                                <form method="POST" action="{{ route('assets.qr.generate', $asset) }}">
                                    @csrf
                                    <button type="submit" class="text-theme-xs font-medium text-brand-500 hover:text-brand-600">+ Create QR</button>
                                </form>
                            @endif
                        @else
                            <span class="text-theme-xs text-gray-300">—</span>
                        @endcan
                    </td>
                    <td class="py-3 px-4">
                        <div class="flex items-center gap-2">
                            <a href="{{ route('assets.show', $asset) }}" class="text-theme-sm font-medium text-brand-500 hover:text-brand-600">View</a>
                            @can('update', $asset)
                                <a href="{{ route('assets.edit', $asset) }}" class="text-theme-sm text-gray-400 hover:text-gray-700 dark:hover:text-gray-300">Edit</a>
                            @endcan
                            {{-- Delete, not Archive. Deletion is permanent, so it asks for
                                 the asset number in a modal: a browser confirm/prompt is
                                 one stray Enter away from destroying a device record, and
                                 it cannot be styled, translated or made to show the asset
                                 it is about. The form below stays the submit target; the
                                 modal only fills its confirm field. --}}
                            @can('forceDelete', $asset)
                                {{-- A plain onclick, not x-on:click. The Alpine
                                     version relied on three things being true at
                                     once - Alpine started, this element inside a
                                     hydrated tree, and the modal already mounted -
                                     and when any of them was false the button was
                                     simply dead with nothing in the console. This
                                     shows the dialog and focuses the field directly,
                                     which cannot fail for a missing framework. --}}
                                <button type="button"
                                        class="text-theme-sm font-medium text-error-600 hover:text-error-700 dark:text-error-400"
                                        title="Hapus aset secara permanen"
                                        onclick="window.trackerDeleteAsset && window.trackerDeleteAsset('delete-asset-{{ $asset->id }}')">Delete</button>


                            @endcan
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-table>

        {{-- One native <dialog> per asset, outside the table.

             These used to be Alpine components driven by a $dispatch from the
             button. That made the button depend on Alpine having started, the
             element being inside a hydrated tree, and the modal having already
             mounted - and when any of those was false the button did nothing and
             said nothing. A <dialog> is opened by showModal() from a plain onclick,
             so the only thing that can fail is the browser, and every browser this
             ships to supports it. --}}
        @foreach($assets as $asset)
            @can('forceDelete', $asset)
                <dialog id="delete-asset-{{ $asset->id }}"
                        class="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-0 text-left shadow-xl backdrop:bg-gray-950/60 dark:border-gray-700 dark:bg-gray-900">
                    <form method="POST" action="{{ route('assets.force-destroy', $asset) }}" class="p-6">
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
            // A named function rather than an Alpine expression, so the button does
            // not depend on any framework having loaded.
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


        @if($assets->hasPages())
            <div class="border-t border-gray-100 dark:border-gray-800">
                {{ $assets->links('components.pagination') }}
            </div>
        @endif
    </x-card>
@else
    <x-card class="text-center py-12">
        <p class="text-theme-sm text-gray-400">No assets found.</p>
    </x-card>
@endif
@endsection
