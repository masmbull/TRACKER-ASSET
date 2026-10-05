@extends('layouts.app')

@section('title', 'Transfer Asset')
@section('header', 'Transfer Asset')

@section('content')
<div class="mx-auto max-w-5xl space-y-6">
    <x-page-header title="Transfer Asset" subtitle="Move this asset to a different user or location while keeping the assignment record complete.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" :href="'/assets/' . $asset->id">Back to Asset</x-button>
        </x-slot:actions>
    </x-page-header>

    <x-card title="Current Assignment">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div>
                <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-500">Current User</p>
                <p class="mt-1 text-theme-sm font-semibold text-gray-800 dark:text-gray-200">{{ $asset->currentUser?->name ?? '—' }}</p>
            </div>
            <div>
                <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-500">Current Location</p>
                <p class="mt-1 text-theme-sm font-semibold text-gray-800 dark:text-gray-200">{{ $asset->location?->name ?? '—' }}</p>
            </div>
            <div>
                <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-500">Company</p>
                <p class="mt-1 text-theme-sm font-semibold text-gray-800 dark:text-gray-200">{{ $asset->company?->name ?? '—' }}</p>
            </div>
        </div>
    </x-card>

    <form method="POST" action="/assets/{{ $asset->id }}/transfer" class="space-y-6">
        @csrf

        {{-- submitTransfer validates four required fields. The browser catches an
             empty box, but not a date the user typed wrong, and it certainly does
             not explain which of four selects was rejected. The layout banner says
             what is wrong; these say where, so nobody has to match "The to user
             field is required" against four identical-looking dropdowns. --}}
        <x-card title="Transfer Details">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Transfer Date *</label>
                    <input type="date" name="transfer_date" required value="{{ old('transfer_date', now()->toDateString()) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 @error('transfer_date') border-error-400 @enderror">
                    @error('transfer_date') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Condition</label>
                    <input type="text" name="condition" value="{{ old('condition') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" placeholder="e.g. Good, Fair, Damaged">
                    @error('condition') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-card>

        <x-card title="Transfer To">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">From Location *</label>
                    <select name="from_location_id" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 @error('from_location_id') border-error-400 @enderror">
                        <option value="">Select location</option>
                        @foreach($locations as $location)
                            {{-- Defaults to where the asset actually is. Its two
                                 siblings on this form already read old(); without
                                 it a rejected transfer silently sent the asset
                                 from somewhere the user never picked. --}}
                            <option value="{{ $location->id }}" {{ old('from_location_id', $asset->location_id) == $location->id ? 'selected' : '' }}>{{ $location->name }}</option>
                        @endforeach
                    </select>
                    @error('from_location_id') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">To Location *</label>
                    <select name="to_location_id" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 @error('to_location_id') border-error-400 @enderror">
                        <option value="">Select location</option>
                        @foreach($locations as $location)
                            <option value="{{ $location->id }}" {{ old('to_location_id') == $location->id ? 'selected' : '' }}>{{ $location->name }}</option>
                        @endforeach
                    </select>
                    @error('to_location_id') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">To User *</label>
                    <select name="to_user_id" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 @error('to_user_id') border-error-400 @enderror">
                        <option value="">Select user</option>
                        @foreach($users as $user)
                            <option value="{{ $user->id }}" {{ old('to_user_id') == $user->id ? 'selected' : '' }}>{{ $user->name }} ({{ $user->email }})</option>
                        @endforeach
                    </select>
                    @error('to_user_id') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">To Department</label>
                    <input type="text" name="to_department" value="{{ old('to_department') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" placeholder="e.g. Finance, HR">
                </div>
            </div>
        </x-card>

        <x-card title="Additional Notes">
            <div class="space-y-4">
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Accessories</label>
                    <textarea name="accessories" rows="2" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" placeholder="Charger, bag, etc.">{{ old('accessories') }}</textarea>
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                    <textarea name="notes" rows="2" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" placeholder="Additional notes...">{{ old('notes') }}</textarea>
                </div>
            </div>
            <p class="mt-4 text-theme-xs text-gray-500">Submitted by: <span class="font-medium text-gray-700 dark:text-gray-200">{{ Auth::user()?->name }}</span></p>
        </x-card>

        <div class="flex justify-end gap-3">
            <a href="/assets/{{ $asset->id }}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</a>
            <x-button type="submit" variant="primary">Submit Transfer</x-button>
        </div>
    </form>
</div>
@endsection
