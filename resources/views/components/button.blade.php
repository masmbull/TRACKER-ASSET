@props(['variant' => 'primary', 'size' => 'md', 'href' => null, 'type' => 'button', 'loading' => false])
@php
$variants = [
    'primary' => 'bg-brand-500 text-white hover:bg-brand-600 shadow-theme-xs',
    'secondary' => 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400',
    'danger' => 'bg-error-500 text-white hover:bg-error-600 shadow-theme-xs',
    'success' => 'bg-success-500 text-white hover:bg-success-600 shadow-theme-xs',
    'warning' => 'bg-warning-500 text-white hover:bg-warning-600 shadow-theme-xs',
    'ghost' => 'text-gray-500 hover:text-gray-700 hover:bg-gray-100',
];
$sizes = [
    'sm' => 'px-3 py-1.5 text-theme-xs',
    'md' => 'px-4 py-2.5 text-theme-sm',
    'lg' => 'px-5 py-3 text-theme-sm',
];
@endphp
@if($href)
<a href="{{ $href }}" {{ $attributes->class(['inline-flex items-center justify-center gap-2 rounded-lg font-medium transition', $variants[$variant] ?? $variants['primary'], $sizes[$size] ?? $sizes['md']]) }}>
    {{ $slot }}
</a>
@else
<button type="{{ $type }}" {{ $attributes->class(['inline-flex items-center justify-center gap-2 rounded-lg font-medium transition', $variants[$variant] ?? $variants['primary'], $sizes[$size] ?? $sizes['md']]) }}>
    {{ $slot }}
</button>
@endif
