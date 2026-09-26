@props(['variant' => 'gray', 'dot' => false])
@php
$colors = [
    'brand' => 'bg-brand-50 text-brand-500 dark:bg-brand-500/15 dark:text-brand-400',
    'success' => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
    'error' => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
    'warning' => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-500',
    'orange' => 'bg-orange-50 text-orange-600',
    'gray' => 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-400',
];
@endphp
<span {{ $attributes->class(['inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-theme-xs font-medium', $colors[$variant] ?? $colors['gray']]) }}>
    @if($dot)<span class="h-1.5 w-1.5 rounded-full bg-current"></span>@endif
    {{ $slot }}
</span>
