@props(['id' => 'modal', 'title' => '', 'maxWidth' => 'md'])

{{--
    Teleported to <body> on purpose.

    `position: fixed` is only relative to the viewport when no ancestor has a
    transform, filter or perspective - any of those makes that ancestor the
    containing block instead. Cards here carry the `.reveal` scroll animation,
    which leaves `transform: translateY(0)` on the card even after it has played,
    so a modal rendered inside a card was positioned relative to the card: it
    centred on the row rather than the screen. The dialog is therefore teleported
    to <body>, out of reach of any ancestor's containing block, and centres on the
    viewport wherever it is included. x-data stays on the outer wrapper (in place)
    and the inner template is what moves, so the Alpine scope the teleported
    markup reads is preserved.
--}}
<div x-data="{ open: false }"
     x-on:open-modal.window="$event.detail === '{{ $id }}' ? open = true : null"
     x-on:close-modal.window="$event.detail === '{{ $id }}' ? open = false : null"
     x-on:keydown.escape.window="open = false"
     x-effect="document.body.style.overflow = open ? 'hidden' : ''">
    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-[100010] overflow-y-auto" style="z-index:100010 !important;">
            <div x-show="open" x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 class="fixed inset-0 bg-gray-900/50 backdrop-blur-sm" x-on:click="open = false"></div>

            <div class="flex min-h-full items-center justify-center p-4">
                <div x-show="open" x-cloak style="z-index:100010 !important;"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 scale-95 translate-y-2" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100 scale-100 translate-y-0" x-transition:leave-end="opacity-0 scale-95 translate-y-2"
                     @class([
                         'relative z-[100010] w-full bg-white rounded-2xl shadow-theme-lg dark:bg-gray-900',
                         'relative w-full bg-white rounded-2xl shadow-theme-lg dark:bg-gray-900',
                         'max-w-sm' => $maxWidth === 'sm',
                         'max-w-md' => $maxWidth === 'md',
                         'max-w-lg' => $maxWidth === 'lg',
                         'max-w-xl' => $maxWidth === 'xl',
                         'max-w-2xl' => $maxWidth === '2xl',
                         'max-w-3xl' => $maxWidth === '3xl',
                         'max-w-5xl' => $maxWidth === '5xl',
                         // Near-fullscreen: the device detail carries three columns of
                         // readings and a long inventory, so it takes the full height
                         // and near-full width of a desktop viewport.
                         'h-[calc(100vh-2rem)] max-w-[min(1600px,calc(100vw-2rem))] flex flex-col' => $maxWidth === 'full',
                     ])>
                    @if($title)
                    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-800 {{ $maxWidth === 'full' ? 'shrink-0' : '' }}">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white">{{ $title }}</h3>
                        <button @click="open = false" class="rounded-lg p-1 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-white/5 dark:hover:text-gray-300">
                            <svg class="fill-current" width="24" height="24" viewBox="0 0 24 24"><path fill-rule="evenodd" clip-rule="evenodd" d="M6.21967 7.28131C5.92678 6.98841 5.92678 6.51354 6.21967 6.22065C6.51256 5.92775 6.98744 5.92775 7.28033 6.22065L11.999 10.9393L16.7176 6.22078C17.0105 5.92789 17.4854 5.92788 17.7782 6.22078C18.0711 6.51367 18.0711 6.98855 17.7782 7.28144L13.0597 12L17.7782 16.7186C18.0711 17.0115 18.0711 17.4863 17.7782 17.7792C17.4854 18.0721 17.0105 18.0721 16.7176 17.7792L11.999 13.0607L7.28033 17.7794C6.98744 18.0722 6.51256 18.0722 6.21967 17.7794C5.92678 17.4865 5.92678 17.0116 6.21967 16.7187L10.9384 12L6.21967 7.28131Z"/></svg>
                        </button>
                    </div>
                    @endif
                    <div class="px-6 py-4 {{ $maxWidth === 'full' ? 'min-h-0 flex-1 overflow-y-auto' : '' }}">{{ $slot }}</div>
                    @if(isset($footer))
                    <div class="px-6 py-4 border-t border-gray-100 dark:border-gray-800 flex justify-end gap-3 {{ $maxWidth === 'full' ? 'shrink-0' : '' }}">{{ $footer }}</div>
                    @endif
                </div>
            </div>
        </div>
    </template>
</div>