@props(['paginator'])
@if($paginator->hasPages())
<div class="flex items-center justify-between py-4 px-4">
    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
        Showing {{ $paginator->firstItem() }} to {{ $paginator->lastItem() }} of {{ $paginator->total() }} results
    </p>
    <div class="flex items-center gap-1">
        @if($paginator->onFirstPage())
            <span class="flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-300 cursor-not-allowed dark:border-gray-700 dark:text-gray-600">
                <x-icon name="chevron-left" class="h-4 w-4" />
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 hover:text-gray-800 transition-all duration-200 active:scale-95 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800">
                <x-icon name="chevron-left" class="h-4 w-4" />
            </a>
        @endif

        @foreach($elements as $element)
            @if(is_string($element))
                <span class="flex h-9 w-9 items-center justify-center text-gray-400">{{ $element }}</span>
            @endif
            @if(is_array($element))
                @foreach($element as $page => $url)
                    @if($page == $paginator->currentPage())
                        <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-500 text-white font-medium">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" class="flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 transition-all duration-200 active:scale-95 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 transition-all duration-200 active:scale-95 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800">
                <x-icon name="chevron-right" class="h-4 w-4" />
            </a>
        @else
            <span class="flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-300 cursor-not-allowed dark:border-gray-700 dark:text-gray-600">
                <x-icon name="chevron-right" class="h-4 w-4" />
            </span>
        @endif
    </div>
</div>
@endif
