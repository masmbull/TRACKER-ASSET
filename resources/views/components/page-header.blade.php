@props(['title' => '', 'subtitle' => ''])
<div class="animate-fade-up mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
    <div class="min-w-0">
        <h1 class="text-title-xs font-bold tracking-tight text-gray-800 dark:text-white">{{ $title }}</h1>
        @if($subtitle)<p class="mt-0.5 text-theme-sm text-gray-500 dark:text-gray-400">{{ $subtitle }}</p>@endif
    </div>
    @if(isset($actions))<div class="flex w-full flex-wrap items-center gap-2 sm:w-auto sm:justify-end xl:px-0">{{ $actions }}</div>@endif
</div>
