@props(['type' => 'success', 'dismissible' => true])
@php
$types = [
    'success' => 'border-success-200 bg-success-50 text-success-700 dark:border-success-500/20 dark:bg-success-500/10 dark:text-success-300',
    'error' => 'border-error-200 bg-error-50 text-error-700 dark:border-error-500/20 dark:bg-error-500/10 dark:text-error-400',
    'warning' => 'border-warning-200 bg-warning-50 text-warning-700 dark:border-warning-500/20 dark:bg-warning-500/10 dark:text-warning-300',
    'info' => 'border-blue-light-200 bg-blue-light-50 text-blue-light-700 dark:border-blue-light-500/20 dark:bg-blue-light-500/10 dark:text-blue-light-300',
];
$iconName = match ($type) {
    'success' => 'success',
    'error' => 'error',
    'warning' => 'warning',
    default => 'fallback',
};
@endphp
<div x-data="{ show: true, progress: 100 }" x-show="show"
     x-init="const t = setInterval(() => { progress -= 2; if (progress <= 0) { clearInterval(t); show = false; } }, 100)"
     x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
     x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
     {{ $attributes->class(['relative flex items-center gap-3 overflow-hidden border px-4 py-3 rounded-xl text-theme-sm font-medium mb-4', $types[$type] ?? $types['success']]) }}>
    @if(in_array($type, ['success', 'error', 'warning'], true))
        <x-icon :name="$iconName" class="h-5 w-5 shrink-0" />
    @endif
    <span class="flex-1">{{ $slot }}</span>
    @if($dismissible)
    <button @click="show = false" aria-label="Dismiss" class="ml-auto rounded p-0.5 opacity-60 transition-all hover:scale-110 hover:opacity-100"><x-icon name="close" class="h-4 w-4" /></button>
    @endif
    <span class="absolute bottom-0 left-0 h-0.5 bg-current opacity-30" :style="`width: ${progress}%`"></span>
</div>
