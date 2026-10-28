@props(['size' => 'md'])
@php
$sizes = [
    'sm' => ['box' => 'h-8 w-8', 'logo' => 'h-5 w-5', 'ring' => '-inset-1', 'ringw' => '2px', 'pad' => 'rounded-xl'],
    'md' => ['box' => 'h-12 w-12', 'logo' => 'h-7 w-7', 'ring' => '-inset-1.5', 'ringw' => '2.5px', 'pad' => 'rounded-2xl'],
    'lg' => ['box' => 'h-16 w-16', 'logo' => 'h-10 w-10', 'ring' => '-inset-2', 'ringw' => '3px', 'pad' => 'rounded-2xl'],
    'xl' => ['box' => 'h-20 w-20', 'logo' => 'h-12 w-12', 'ring' => '-inset-2.5', 'ringw' => '3.5px', 'pad' => 'rounded-3xl'],
];
$s = $sizes[$size] ?? $sizes['md'];
@endphp
<div {{ $attributes->class(['relative flex items-center justify-center', $s['box']]) }} role="status" aria-label="Loading">
    {{-- Breathing halo behind the plate: the orbit ring says "spinning", the
         halo says "alive", together they read as a brand spinner and not a
         generic wheel. --}}
    <span class="mito-loader-halo absolute -inset-3 {{ $s['pad'] }} bg-brand-500/20 blur-md" aria-hidden="true"></span>
    <span class="absolute inset-0 {{ $s['pad'] }} bg-white shadow-theme-md dark:bg-gray-900" aria-hidden="true"></span>
    <img src="{{ asset('images/mito-icon.png') }}" alt="" aria-hidden="true" class="mito-loader-float {{ $s['logo'] }} relative rounded-lg" draggable="false">
    {{-- The orbit itself: a masked conic sweep. Thickness travels via
         --ring-w into the CSS rule (see app.css). --}}
    <span class="mito-loader-ring absolute {{ $s['ring'] }} {{ $s['pad'] }}" style="--ring-w: {{ $s['ringw'] }}" aria-hidden="true"></span>
</div>
