@props(['paginator'])
@if($paginator->hasPages())
<div class="flex items-center justify-between py-4 px-4">
    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
        Showing {{ $paginator->firstItem() }} to {{ $paginator->lastItem() }} of {{ $paginator->total() }} results
    </p>
    <div class="flex items-center gap-1">
        @if($paginator->onFirstPage())
            <span class="flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-300 cursor-not-allowed dark:border-gray-700 dark:text-gray-600">
                <svg class="fill-current" width="16" height="16" viewBox="0 0 16 16"><path d="M10.4 12.4L6.8 8.8L10.4 5.2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 hover:text-gray-800 transition-all duration-200 active:scale-95 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800">
                <svg class="fill-current" width="16" height="16" viewBox="0 0 16 16"><path d="M10.4 12.4L6.8 8.8L10.4 5.2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
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
                <svg class="fill-current" width="16" height="16" viewBox="0 0 16 16"><path d="M5.6 12.4L9.2 8.8L5.6 5.2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
        @else
            <span class="flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-300 cursor-not-allowed dark:border-gray-700 dark:text-gray-600">
                <svg class="fill-current" width="16" height="16" viewBox="0 0 16 16"><path d="M5.6 12.4L9.2 8.8L5.6 5.2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
        @endif
    </div>
</div>
@endif
