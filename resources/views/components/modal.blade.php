@props(['id' => 'modal', 'title' => '', 'maxWidth' => 'md'])

<div x-data="{ open: false }"
     x-on:open-modal.window="$event.detail === '{{ $id }}' ? open = true : null"
     x-on:close-modal.window="$event.detail === '{{ $id }}' ? open = false : null"
     x-on:keydown.escape.window="open = false"
     x-show="open" x-cloak
     class="fixed inset-0 z-[99999] overflow-y-auto">

    <div x-show="open" x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="fixed inset-0 bg-gray-900/50 backdrop-blur-sm" x-on:click="open = false"></div>

    <div class="flex min-h-full items-center justify-center p-4">
        <div x-show="open" x-cloak
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
             @class([
                 'relative w-full bg-white rounded-2xl shadow-theme-lg transform dark:bg-gray-900',
                 'max-w-sm' => $maxWidth === 'sm',
                 'max-w-md' => $maxWidth === 'md',
                 'max-w-lg' => $maxWidth === 'lg',
                 'max-w-xl' => $maxWidth === 'xl',
                 'max-w-2xl' => $maxWidth === '2xl',
             ])>
            @if($title)
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-800">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-white">{{ $title }}</h3>
                <button @click="open = false" class="text-gray-400 hover:text-gray-600">
                    <svg class="fill-current" width="24" height="24" viewBox="0 0 24 24"><path fill-rule="evenodd" clip-rule="evenodd" d="M6.21967 7.28131C5.92678 6.98841 5.92678 6.51354 6.21967 6.22065C6.51256 5.92775 6.98744 5.92775 7.28033 6.22065L11.999 10.9393L16.7176 6.22078C17.0105 5.92789 17.4854 5.92788 17.7782 6.22078C18.0711 6.51367 18.0711 6.98855 17.7782 7.28144L13.0597 12L17.7782 16.7186C18.0711 17.0115 18.0711 17.4863 17.7782 17.7792C17.4854 18.0721 17.0105 18.0721 16.7176 17.7792L11.999 13.0607L7.28033 17.7794C6.98744 18.0722 6.51256 18.0722 6.21967 17.7794C5.92678 17.4865 5.92678 17.0116 6.21967 16.7187L10.9384 12L6.21967 7.28131Z"/></svg>
                </button>
            </div>
            @endif
            <div class="px-6 py-4">{{ $slot }}</div>
            @if(isset($footer))
            <div class="px-6 py-4 border-t border-gray-100 dark:border-gray-800 flex justify-end gap-3">{{ $footer }}</div>
            @endif
        </div>
    </div>
</div>
