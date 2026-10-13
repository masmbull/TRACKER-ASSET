@props(['variant' => 'primary', 'size' => 'md', 'href' => null, 'type' => 'button', 'loading' => false])
@php
$variants = [
    'primary' => 'bg-brand-500 text-white shadow-theme-xs hover:bg-brand-600',
    'secondary' => 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-gray-700',
    'danger' => 'bg-error-500 text-white shadow-theme-xs hover:bg-error-600',
    'success' => 'bg-success-500 text-white shadow-theme-xs hover:bg-success-600',
    'warning' => 'bg-warning-500 text-white shadow-theme-xs hover:bg-warning-600',
    'ghost' => 'text-gray-500 hover:text-gray-700 hover:bg-gray-100 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:bg-white/5',
];
$sizes = [
    'sm' => 'px-3 py-1.5 text-theme-xs',
    'md' => 'px-4 py-2.5 text-theme-sm',
    'lg' => 'px-5 py-3 text-theme-sm',
];
@endphp
@if($href)
<a href="{{ $href }}" {{ $attributes->class(['inline-flex items-center justify-center gap-2 rounded-lg font-medium transition-all duration-200 active:scale-[0.97] disabled:pointer-events-none disabled:opacity-60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500', $variants[$variant] ?? $variants['primary'], $sizes[$size] ?? $sizes['md']]) }}>
    {{ $slot }}
</a>
@else
<button type="{{ $type }}" {{ $attributes->class(['inline-flex items-center justify-center gap-2 rounded-lg font-medium transition-all duration-200 active:scale-[0.97] disabled:pointer-events-none disabled:opacity-60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500', $variants[$variant] ?? $variants['primary'], $sizes[$size] ?? $sizes['md']]) }}>
    {{ $slot }}
</button>
@endif
