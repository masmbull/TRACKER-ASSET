@props(['paginator'])
@if($paginator->hasPages())
<nav aria-label="Pagination" class="flex items-center justify-between gap-2 border-t border-gray-200 px-3 py-3 dark:border-gray-800">
    @if($paginator->onFirstPage())
        <span class="inline-flex h-9 cursor-not-allowed items-center gap-1 rounded-lg border border-gray-200 px-3 text-theme-xs font-medium text-gray-300 dark:border-gray-800 dark:text-gray-600">
            <svg fill="none" width="14" height="14" viewBox="0 0 16 16" aria-hidden="true"><path d="M10 12.4 6.4 8.8 10 5.2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Prev
        </span>
    @else
        <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="inline-flex h-9 items-center gap-1 rounded-lg border border-gray-200 px-3 text-theme-xs font-medium text-gray-600 transition hover:bg-gray-50 hover:text-gray-800 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
            <svg fill="none" width="14" height="14" viewBox="0 0 16 16" aria-hidden="true"><path d="M10 12.4 6.4 8.8 10 5.2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Prev
        </a>
    @endif

    <span class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>

    @if($paginator->hasMorePages())
        <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="inline-flex h-9 items-center gap-1 rounded-lg border border-gray-200 px-3 text-theme-xs font-medium text-gray-600 transition hover:bg-gray-50 hover:text-gray-800 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
            Next
            <svg fill="none" width="14" height="14" viewBox="0 0 16 16" aria-hidden="true"><path d="M6 12.4 9.6 8.8 6 5.2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </a>
    @else
        <span class="inline-flex h-9 cursor-not-allowed items-center gap-1 rounded-lg border border-gray-200 px-3 text-theme-xs font-medium text-gray-300 dark:border-gray-800 dark:text-gray-600">
            Next
            <svg fill="none" width="14" height="14" viewBox="0 0 16 16" aria-hidden="true"><path d="M6 12.4 9.6 8.8 6 5.2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </span>
    @endif
</nav>
@endif