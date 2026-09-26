@props(['label' => null, 'error' => null, 'rows' => 3])
<div>
    @if($label)<label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-400">{{ $label }}</label>@endif
    <textarea rows="{{ $rows }}" {{ $attributes->class([
        'w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-theme-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:ring-brand-500/10 focus:ring-3 focus:outline-none dark:border-gray-700 dark:text-white/90',
        'border-error-300' => $error,
    ]) }}></textarea>
    @if($error)<p class="mt-1 text-theme-xs text-error-500">{{ $error }}</p>@endif
</div>
