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
                    <label for="asset_id" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Asset ID *</label>
                    <input id="asset_id" type="text" name="asset_id" value="{{ old('asset_id', $asset->asset_id) }}" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 @error('asset_id') border-error-400 focus:border-error-500 focus:ring-error-500/10 @enderror">
                    @error('asset_id') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="company_id" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Company *</label>
                    <select id="company_id" name="company_id" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        <option value="">Select</option>
                        @foreach($companies as $company)
                            <option value="{{ $company->id }}" {{ old('company_id', $asset->company_id) == $company->id ? 'selected' : '' }}>{{ $company->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="category_id" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Category *</label>
                    <select id="category_id" name="category_id" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        <option value="">Select</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}" {{ old('category_id', $asset->category_id) == $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="asset_status_id" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Status *</label>
                    <select id="asset_status_id" name="asset_status_id" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
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
                    <label for="brand" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Brand *</label>
                    <input id="brand" type="text" name="brand" value="{{ old('brand', $asset->brand) }}" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 @error('brand') border-error-400 @enderror">
                    @error('brand') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="model" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Model *</label>
                    <input id="model" type="text" name="model" value="{{ old('model', $asset->model) }}" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 @error('model') border-error-400 @enderror">
                    @error('model') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="serial_number" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Serial Number *</label>
                    <input id="serial_number" type="text" name="serial_number" value="{{ old('serial_number', $asset->serial_number) }}" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 @error('serial_number') border-error-400 @enderror">
                    @error('serial_number') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="hostname" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Hostname *</label>
                    <input id="hostname" type="text" name="hostname" value="{{ old('hostname', $asset->hostname) }}" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 @error('hostname') border-error-400 @enderror">
                    @error('hostname') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="location_id" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Location *</label>
                    <select id="location_id" name="location_id" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 @error('location_id') border-error-400 @enderror">
                        <option value="">Select</option>
                        @foreach($locations as $location)
                            <option value="{{ $location->id }}" {{ old('location_id', $asset->location_id) == $location->id ? 'selected' : '' }}>{{ $location->name }}</option>
                        @endforeach
                    </select>
                    @error('location_id') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="purchase_year" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Purchase Year</label>
                    <input id="purchase_year" type="number" name="purchase_year" value="{{ old('purchase_year', $asset->purchase_year) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label for="vendor" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Vendor</label>
                    <input id="vendor" type="text" name="vendor" value="{{ old('vendor', $asset->vendor) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label for="purchase_price" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Purchase Price</label>
                    <input id="purchase_price" type="number" name="purchase_price" step="0.01" value="{{ old('purchase_price', $asset->purchase_price) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
            </div>
        </x-card>

        <x-card title="Network & Software">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="os_version" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">OS Version</label>
                    <input id="os_version" type="text" name="os_version" value="{{ old('os_version', $asset->os_version) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label for="mac_address" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">MAC Address</label>
                    <input id="mac_address" type="text" name="mac_address" value="{{ old('mac_address', $asset->mac_address) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label for="static_ip" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Static IP</label>
                    <input id="static_ip" type="text" name="static_ip" value="{{ old('static_ip', $asset->static_ip) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label for="current_user_id" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Current User</label>
                    <select id="current_user_id" name="current_user_id" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        <option value="">Select</option>
                        @foreach($users as $user)
                            <option value="{{ $user->id }}" {{ old('current_user_id', $asset->current_user_id) == $user->id ? 'selected' : '' }}>{{ $user->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="admin_it_pic" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Admin IT PIC</label>
                    <select id="admin_it_pic" name="admin_it_pic" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
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
                <label for="repair_notes" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Repair Notes</label>
                <textarea id="repair_notes" name="repair_notes" rows="4" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">{{ old('repair_notes', $asset->repair_notes) }}</textarea>
            </div>
        </x-card>

        <div class="flex justify-end gap-3">
            <a href="/assets" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</a>
            <x-button type="submit" variant="primary">Update Asset</x-button>
        </div>
    </form>
</div>
@endsection
