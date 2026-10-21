@props(['variant' => 'gray', 'dot' => false])
@php
$colors = [
    'brand' => 'bg-brand-50 text-brand-500 dark:bg-brand-500/15 dark:text-brand-400',
    'success' => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
    'error' => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
    'warning' => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-500',
    'orange' => 'bg-orange-50 text-orange-600 dark:bg-orange-500/15 dark:text-orange-500',
    'danger' => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
    'gray' => 'bg-gray-100 text-gray-600 dark:bg-gray-700/50 dark:text-gray-300',
];
@endphp
<span {{ $attributes->class(['inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-theme-xs font-medium transition-colors duration-200', $colors[$variant] ?? $colors['gray']]) }}>
    @if($dot)<span @class(['h-1.5 w-1.5 rounded-full bg-current', 'pulse-dot' => in_array($variant, ['success', 'error'], true)])></span>@endif
    {{ $slot }}
</span>
