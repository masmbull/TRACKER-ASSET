@props(['type' => 'success', 'dismissible' => true])
@php
$types = [
    'success' => 'border-success-200 bg-success-50 text-success-700 dark:border-success-500/20 dark:bg-success-500/10 dark:text-success-300',
    'error' => 'border-error-200 bg-error-50 text-error-700 dark:border-error-500/20 dark:bg-error-500/10 dark:text-error-400',
    'warning' => 'border-warning-200 bg-warning-50 text-warning-700 dark:border-warning-500/20 dark:bg-warning-500/10 dark:text-warning-300',
    'info' => 'border-blue-light-200 bg-blue-light-50 text-blue-light-700 dark:border-blue-light-500/20 dark:bg-blue-light-500/10 dark:text-blue-light-300',
];
@endphp
<div x-data="{ show: true, progress: 100 }" x-show="show"
     x-init="const t = setInterval(() => { progress -= 2; if (progress <= 0) { clearInterval(t); show = false; } }, 100)"
     x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
     x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
     {{ $attributes->class(['relative flex items-center gap-3 overflow-hidden border px-4 py-3 rounded-xl text-theme-sm font-medium mb-4', $types[$type] ?? $types['success']]) }}>
    @if($type === 'success')
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    @elseif($type === 'error')
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    @elseif($type === 'warning')
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
    @endif
    <span class="flex-1">{{ $slot }}</span>
    @if($dismissible)
    <button @click="show = false" aria-label="Dismiss" class="ml-auto rounded p-0.5 opacity-60 transition-all hover:scale-110 hover:opacity-100">&times;</button>
    @endif
    <span class="absolute bottom-0 left-0 h-0.5 bg-current opacity-30" :style="`width: ${progress}%`"></span>
</div>
