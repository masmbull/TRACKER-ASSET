@props(['size' => 'md'])
@php
$sizes = [
    'sm' => ['box' => 'h-8 w-8', 'logo' => 'h-5 w-5', 'ring' => 'border-2', 'pad' => 'rounded-xl'],
    'md' => ['box' => 'h-12 w-12', 'logo' => 'h-7 w-7', 'ring' => 'border-[3px]', 'pad' => 'rounded-2xl'],
    'lg' => ['box' => 'h-16 w-16', 'logo' => 'h-10 w-10', 'ring' => 'border-4', 'pad' => 'rounded-2xl'],
    'xl' => ['box' => 'h-20 w-20', 'logo' => 'h-12 w-12', 'ring' => 'border-4', 'pad' => 'rounded-3xl'],
];
$s = $sizes[$size] ?? $sizes['md'];
@endphp
<div {{ $attributes->class(['relative flex items-center justify-center', $s['box']]) }} role="status" aria-label="Loading">
    <span class="absolute inset-0 {{ $s['pad'] }} bg-white shadow-theme-md dark:bg-gray-900" aria-hidden="true"></span>
    <img src="{{ asset('images/mito-icon.png') }}" alt="" aria-hidden="true" class="{{ $s['logo'] }} relative rounded-lg" draggable="false">
    <span class="mito-loader-ring absolute -inset-1.5 {{ $s['pad'] }} {{ $s['ring'] }} border-gray-200 border-t-brand-500 dark:border-gray-700 dark:border-t-brand-500" aria-hidden="true"></span>
</div>
