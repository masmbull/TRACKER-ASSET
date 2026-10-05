<div>
    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">IT PIC</label>
    <select name="admin_it_pic" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
        <option value="">—</option>
        @foreach($users as $user)
            <option value="{{ $user->id }}" {{ (string) old('admin_it_pic') === (string) $user->id ? 'selected' : '' }}>{{ $user->name }}</option>
        @endforeach
    </select>
    @error('admin_it_pic') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
</div>
<div>
    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Vendor</label>
    <input type="text" name="vendor" value="{{ old('vendor') }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
    @error('vendor') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
</div>
<div>
    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Purchase year</label>
    <input type="number" name="purchase_year" value="{{ old('purchase_year') }}" min="1900" max="{{ date('Y') + 2 }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
    @error('purchase_year') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
</div>
<div>
    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Purchase price</label>
    <input type="number" name="purchase_price" value="{{ old('purchase_price') }}" min="0" step="0.01" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
    @error('purchase_price') <p class="mt-1 text-theme-xs text-error-500">{{ $message }}</p> @enderror
</div>
