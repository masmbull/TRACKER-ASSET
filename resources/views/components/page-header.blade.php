@props(['title' => '', 'subtitle' => ''])
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div>
        <h1 class="text-title-xs font-bold text-gray-800 dark:text-white">{{ $title }}</h1>
        @if($subtitle)<p class="text-theme-sm text-gray-500 mt-0.5 dark:text-gray-400">{{ $subtitle }}</p>@endif
    </div>
    @if(isset($actions))<div class="flex items-center gap-2">{{ $actions }}</div>@endif
</div>
