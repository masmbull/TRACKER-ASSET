@props(['variant' => 'neutral', 'href' => null, 'type' => 'button', 'icon' => null])

{{-- One action in a table row.

     Rows that offer several actions used to render each one as coloured text on
     a transparent background, which reads as a soup of unrelated links: nothing
     groups, the colour carries no meaning because everything is coloured, and a
     destructive action sits flush against a harmless one. A tinted chip makes
     each action an object the eye can count, and lets colour mean something
     again - by reserving it for state (Update is amber because the device is
     behind) and for danger. Everything routine stays neutral.

     The lift on hover is small and deliberate: a row of eight actions wants
     the one under the cursor to acknowledge it without the table twitching. --}}

@php
$variants = [
    'neutral' => 'bg-gray-100 text-gray-600 hover:bg-gray-200 hover:text-gray-800 dark:bg-gray-700/60 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white',
    'brand' => 'bg-brand-50 text-brand-700 hover:bg-brand-100 dark:bg-brand-500/15 dark:text-brand-300 dark:hover:bg-brand-500/25',
    'info' => 'bg-cyan-50 text-cyan-700 hover:bg-cyan-100 dark:bg-cyan-500/15 dark:text-cyan-300 dark:hover:bg-cyan-500/25',
    'warning' => 'bg-warning-50 text-warning-700 hover:bg-warning-100 dark:bg-warning-500/15 dark:text-warning-300 dark:hover:bg-warning-500/25',
    'danger' => 'bg-error-50 text-error-700 hover:bg-error-100 dark:bg-error-500/15 dark:text-error-300 dark:hover:bg-error-500/25',
];

$base = 'inline-flex items-center justify-center gap-1 rounded-md px-2 py-1 text-theme-xs font-semibold leading-none whitespace-nowrap '
    .'transition duration-150 ease-out '
    .'hover:-translate-y-px hover:shadow-sm active:translate-y-0 active:scale-95 '
    .'focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brand-500';
@endphp

@if($href)
<a href="{{ $href }}" {{ $attributes->class([$base, $variants[$variant] ?? $variants['neutral']]) }}>@if($icon)<x-icon :name="$icon" class="h-3.5 w-3.5 shrink-0" />@endif{{ $slot }}</a>
@else
<button type="{{ $type }}" {{ $attributes->class([$base, $variants[$variant] ?? $variants['neutral']]) }}>@if($icon)<x-icon :name="$icon" class="h-3.5 w-3.5 shrink-0" />@endif{{ $slot }}</button>
@endif
