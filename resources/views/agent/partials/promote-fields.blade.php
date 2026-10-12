{{-- Register fields for a device promoted off the fleet. Shared with nothing
     yet, but kept as a partial because the same six dropdowns are the part most
     likely to gain a field next, and editing a 200-line form to add one input is
     how the two copies start disagreeing. --}}
<div>
    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Generated asset code</label>
    <input type="hidden" name="asset_id" id="generated_asset_id" value="{{ old('asset_id') }}">
    <div id="generated_asset_code" class="w-full rounded-lg border border-brand-200 bg-brand-50 px-3 py-2.5 text-theme-sm font-medium text-brand-700 dark:border-brand-500/30 dark:bg-brand-500/10 dark:text-brand-200">
        {{ old('asset_id') ?: 'Will be generated automatically after selection' }}
    </div>
    <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">Code uses entity + item type + purchase year, for example MSI-LAP-2026-0001.</p>
</div>
<div>
    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Company *</label>
    <select name="company_id" id="company_id" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
        <option value="">Select company</option>
        @foreach($companies as $company)
            <option value="{{ $company->id }}" data-company-code="{{ $company->code }}" {{ (string) old('company_id') === (string) $company->id ? 'selected' : '' }}>{{ $company->name }}</option>
        @endforeach
    </select>
    @error('company_id') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
</div>
<div>
    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Category *</label>
    <select name="category_id" id="category_id" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
        <option value="">Select category</option>
        @foreach($categories as $category)
            <option value="{{ $category->id }}" data-category-name="{{ $category->name }}" {{ (string) old('category_id') === (string) $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
        @endforeach
    </select>
    @error('category_id') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
</div>
<div>
    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Location *</label>
    <select name="location_id" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
        <option value="">Select location</option>
        @foreach($locations as $location)
            <option value="{{ $location->id }}" {{ (string) old('location_id') === (string) $location->id ? 'selected' : '' }}>{{ $location->name }}</option>
        @endforeach
    </select>
    @error('location_id') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
</div>
<div>
    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Status *</label>
    <select name="asset_status_id" required class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
        <option value="">Select status</option>
        @foreach($statuses as $status)
            <option value="{{ $status->id }}" {{ (string) old('asset_status_id') === (string) $status->id ? 'selected' : '' }}>{{ $status->name }}</option>
        @endforeach
    </select>
    @error('asset_status_id') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
</div>
<div>
    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Used by</label>
    <select name="current_user_id" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
        <option value="">Not assigned yet</option>
        @foreach($users as $user)
            <option value="{{ $user->id }}" {{ (string) old('current_user_id') === (string) $user->id ? 'selected' : '' }}>{{ $user->name }}</option>
        @endforeach
    </select>
    {{-- Naming a holder here opens the assignment history at that person, which
         is what the FAT documents read from. Said on the page so the choice is
         not a trap. --}}
    <p class="mt-1 text-theme-xs text-gray-400">Opens the assignment history, so transfer documents name a first party.</p>
    @error('current_user_id') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
</div>
