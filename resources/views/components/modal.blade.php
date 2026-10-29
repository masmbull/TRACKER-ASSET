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
         <div x-show="open" x-cloak class="modal-overlay fixed inset-0 z-[9998] overflow-y-auto" style="z-index:9998 !important;">
            <div x-show="open"
                 x-transition:enter="transition ease-[cubic-bezier(0.21,0.9,0.35,1)] duration-200"
                 x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 class="modal-backdrop fixed inset-0 bg-gray-950/55 backdrop-blur-md" x-on:click="open = false"></div>

            <div class="flex min-h-full items-center justify-center p-4">
                <div x-show="open" x-cloak style="z-index:9998 !important;"
                     x-transition:enter="transition ease-[cubic-bezier(0.22,1.36,0.36,1)] duration-250"
                     x-transition:enter-start="opacity-0 scale-95 translate-y-3" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100 scale-100 translate-y-0" x-transition:leave-end="opacity-0 scale-95 translate-y-2"
                     @class([
                         'modal-panel modal-dialog relative z-[9998] w-full bg-white rounded-2xl shadow-theme-xl dark:bg-gray-900',
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
                     ]) role="dialog" aria-modal="true" @if($title) aria-labelledby="modal-title-{{ $id }}" @endif>
                    @if($title)
                     <div class="modal-header flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-800 {{ $maxWidth === 'full' ? 'shrink-0' : '' }}">
                         <h3 id="modal-title-{{ $id }}" class="text-lg font-semibold text-gray-800 dark:text-white">{{ $title }}</h3>
                         <button type="button" aria-label="Close dialog" @click="open = false" class="modal-close rounded-lg p-1.5 text-gray-400 transition-all duration-200 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/5 dark:hover:text-gray-200">
                            <x-icon name="close" class="h-6 w-6" />
                        </button>
                    </div>
                    @endif
                     <div class="modal-body px-6 py-4 {{ $maxWidth === 'full' ? 'min-h-0 flex-1 overflow-y-auto' : '' }}">{{ $slot }}</div>
                    @if(isset($footer))
                     <div class="modal-footer px-6 py-4 border-t border-gray-100 dark:border-gray-800 flex justify-end gap-3 {{ $maxWidth === 'full' ? 'shrink-0' : '' }}">{{ $footer }}</div>
                    @endif
                </div>
            </div>
        </div>
    </template>
</div>

