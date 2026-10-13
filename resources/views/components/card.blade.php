@props(['title' => null, 'subtitle' => null])
<div {{ $attributes->class(['reveal rounded-2xl border border-gray-200 bg-white p-4 transition-shadow duration-200 hover:shadow-theme-md md:p-6 dark:border-gray-800 dark:bg-white/3']) }}>
    @if($title || $subtitle)
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ $title }}</h3>
            @if($subtitle)<p class="text-theme-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ $subtitle }}</p>@endif
        </div>
    </div>
    @endif
    {{ $slot }}
</div>
