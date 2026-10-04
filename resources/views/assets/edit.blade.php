@extends('layouts.app')

@section('title', 'Edit Asset')

@section('content')
<div class="mx-auto max-w-5xl space-y-6">
    <x-page-header :title="'Edit Asset ' . $asset->asset_id" subtitle="Update asset information and ownership details.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" :href="route('assets.show', $asset)">Back to Asset</x-button>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="/assets/{{ $asset->id }}" class="space-y-6">
        @csrf
        @method('PUT')

        {{-- Every field below can fail server-side (asset_id unique, brand/model/
             serial/hostname required). Without this the browser's `required`
             attribute is the only guard, so a duplicate Asset ID or an empty
             required field rejected by `$request->validate()` returns to this
             form with no message and the same values - the user resubmits and
             fails again, with nothing on screen saying why. --}}
        @if($errors->any())
            <div class="rounded-xl border border-error-200 bg-error-50 px-4 py-3 text-theme-sm text-error-700 dark:border-error-800/40 dark:bg-error-500/10 dark:text-error-400">
                <p class="font-semibold">Please fix the following before saving:</p>
                <ul class="mt-1 list-inside list-disc space-y-0.5">
                    @foreach($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <x-card title="Basic Information">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Asset ID *</label>
                    <input type="text" name="asset_id" value="{{ old('asset_id', $asset->asset_id) }}" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 @error('asset_id') border-error-400 focus:border-error-500 focus:ring-error-500/10 @enderror">
                    @error('asset_id') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Company *</label>
                    <select name="company_id" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        <option value="">Select</option>
                        @foreach($companies as $company)
                            <option value="{{ $company->id }}" {{ old('company_id', $asset->company_id) == $company->id ? 'selected' : '' }}>{{ $company->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Category *</label>
                    <select name="category_id" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        <option value="">Select</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}" {{ old('category_id', $asset->category_id) == $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Status *</label>
                    <select name="asset_status_id" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        <option value="">Select</option>
                        @foreach($statuses as $status)
                            <option value="{{ $status->id }}" {{ old('asset_status_id', $asset->asset_status_id) == $status->id ? 'selected' : '' }}>{{ $status->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </x-card>

        <x-card title="Device Details">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Brand *</label>
                    <input type="text" name="brand" value="{{ old('brand', $asset->brand) }}" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 @error('brand') border-error-400 @enderror">
                    @error('brand') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Model *</label>
                    <input type="text" name="model" value="{{ old('model', $asset->model) }}" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 @error('model') border-error-400 @enderror">
                    @error('model') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Serial Number *</label>
                    <input type="text" name="serial_number" value="{{ old('serial_number', $asset->serial_number) }}" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 @error('serial_number') border-error-400 @enderror">
                    @error('serial_number') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Hostname *</label>
                    <input type="text" name="hostname" value="{{ old('hostname', $asset->hostname) }}" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 @error('hostname') border-error-400 @enderror">
                    @error('hostname') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Location *</label>
                    <select name="location_id" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 @error('location_id') border-error-400 @enderror">
                        <option value="">Select</option>
                        @foreach($locations as $location)
                            <option value="{{ $location->id }}" {{ old('location_id', $asset->location_id) == $location->id ? 'selected' : '' }}>{{ $location->name }}</option>
                        @endforeach
                    </select>
                    @error('location_id') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Purchase Year</label>
                    <input type="number" name="purchase_year" value="{{ old('purchase_year', $asset->purchase_year) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Vendor</label>
                    <input type="text" name="vendor" value="{{ old('vendor', $asset->vendor) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Purchase Price</label>
                    <input type="number" name="purchase_price" step="0.01" value="{{ old('purchase_price', $asset->purchase_price) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
            </div>
        </x-card>

        <x-card title="Network & Software">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">OS Version</label>
                    <input type="text" name="os_version" value="{{ old('os_version', $asset->os_version) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">MAC Address</label>
                    <input type="text" name="mac_address" value="{{ old('mac_address', $asset->mac_address) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Static IP</label>
                    <input type="text" name="static_ip" value="{{ old('static_ip', $asset->static_ip) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Current User</label>
                    <select name="current_user_id" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        <option value="">Select</option>
                        @foreach($users as $user)
                            <option value="{{ $user->id }}" {{ old('current_user_id', $asset->current_user_id) == $user->id ? 'selected' : '' }}>{{ $user->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Admin IT PIC</label>
                    <select name="admin_it_pic" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        <option value="">Select</option>
                        @foreach($users as $user)
                            <option value="{{ $user->id }}" {{ old('admin_it_pic', $asset->admin_it_pic) == $user->id ? 'selected' : '' }}>{{ $user->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </x-card>

        <x-card title="Notes">
            <div>
                <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Repair Notes</label>
                <textarea name="repair_notes" rows="4" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">{{ old('repair_notes', $asset->repair_notes) }}</textarea>
            </div>
        </x-card>

        <div class="flex justify-end gap-3">
            <a href="/assets" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</a>
            <x-button type="submit" variant="primary">Update Asset</x-button>
        </div>
    </form>
</div>
@endsection
