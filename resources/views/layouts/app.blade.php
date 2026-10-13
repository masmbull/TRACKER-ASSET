<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="theme-color" content="#dc2626">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>@yield('title', 'TRACKER-ASSET')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{ \Illuminate\Support\Facades\Vite::fonts('outfit') }}
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/mito-icon.png') }}">
    <script>
        (function() {
            try {
                var darkMode = localStorage.getItem('darkMode');
                if (darkMode === 'true') {
                    document.documentElement.classList.add('dark');
                } else {
                    document.documentElement.classList.remove('dark');
                }
                // Splash shows once per session: skip it before first paint
                // when already seen, so repeat visits stay instant.
                if (sessionStorage.getItem('mito-splash-seen') === '1') {
                    document.documentElement.classList.add('mito-splash-seen');
                }
            } catch (e) {}
        })();
    </script>
    <style>.mito-splash-seen #mito-splash{display:none!important}</style>
</head>
<body
    x-data="{
        darkMode: JSON.parse(localStorage.getItem('darkMode') || 'false'),
        sidebarToggle: false,
        sidebarExpanded: (() => {
            try {
                const stored = localStorage.getItem('sidebarExpanded');
                if (stored !== null) {
                    return JSON.parse(stored);
                }
            } catch (e) {}
            return true;
        })(),
        selectedMenu: $persist('{{ request()->route()->getName() ?? 'dashboard' }}')
    }"
    x-init="$watch('darkMode', v => { localStorage.setItem('darkMode', JSON.stringify(v)); document.documentElement.classList.toggle('dark', v) }); $watch('sidebarExpanded', v => { if (window.innerWidth >= 1280) { localStorage.setItem('sidebarExpanded', JSON.stringify(v)); } }); $watch('sidebarToggle', v => { if (window.innerWidth >= 1280) { sidebarToggle = false; } }); const syncSidebarState = () => { const isDesktop = window.innerWidth >= 1280; if (isDesktop) { sidebarToggle = false; const stored = localStorage.getItem('sidebarExpanded'); sidebarExpanded = stored === null ? true : JSON.parse(stored); } else { sidebarToggle = false; } }; syncSidebarState(); window.addEventListener('resize', syncSidebarState); document.documentElement.classList.toggle('dark', darkMode)"
    :class="{'bg-gray-900': darkMode}"
    class="font-outfit bg-gray-50"
>
    <!-- Session splash: MITO brand loader, once per browser session.
         Faded out by app.js shortly after first paint (see markSplashSeen). -->
    <div id="mito-splash" class="fixed inset-0 z-[1000000] flex items-center justify-center bg-white dark:bg-black" aria-hidden="true">
        <div class="animate-scale-in flex flex-col items-center gap-4">
            <x-mito-loader size="xl" />
            <p class="text-theme-sm font-semibold tracking-widest text-gray-400 uppercase dark:text-gray-500">Tracker-Asset</p>
        </div>
    </div>

    <!-- Slim top progress bar: shown during navigations, async posts and
         soft polls (see app.js). -->
    <div id="top-progress" aria-hidden="true"></div>

    <!-- Toast stack: non-blocking notices, filled by app.js. -->
    <div id="toast-stack" aria-live="polite"></div>

    <!-- Page Wrapper -->
    <div class="flex min-h-screen overflow-x-hidden bg-gray-50">
        <!-- Sidebar Overlay (mobile) -->
        <div @click="sidebarToggle = false"
             x-show="sidebarToggle"
             x-transition.opacity
             x-cloak
             class="fixed inset-0 z-[9998] bg-gray-900/50 xl:hidden"></div>

        <!-- Sidebar -->
        @php
            $route = request()->route()->getName();

            // Section matching, not string equality.
            //
            // The menu wrote `$route === 'templates.*'`, which is a comparison,
            // not a pattern: "templates.index" === "templates.*" is false for
            // every page that exists, so none of those entries ever lit up. The
            // exact match is kept first so a section named for one route still
            // resolves without Str at all.
            $isSection = fn (string $pattern): bool => $route === $pattern
                || \Illuminate\Support\Str::is($pattern, (string) $route);
            $activeRouteAliases = [
                'assets.index' => ['assets.show', 'assets.edit', 'assets.audit', 'assets.audit.device', 'assets.transfer', 'assets.repair.create'],
                'assets.qr.sheet' => ['assets.qr'],
                'documents.index' => ['documents.preview', 'documents.download'],
                'reports.index' => ['reports.export'],
            ];
            $navGroups = [
                'MENU' => [
                    ['route' => 'dashboard', 'label' => 'Dashboard', 'icon' => '<path fill-rule="evenodd" clip-rule="evenodd" d="M5.5 3.25C4.25736 3.25 3.25 4.25736 3.25 5.5V8.99998C3.25 10.2426 4.25736 11.25 5.5 11.25H9C10.2426 11.25 11.25 10.2426 11.25 8.99998V5.5C11.25 4.25736 10.2426 3.25 9 3.25H5.5ZM4.75 5.5C4.75 5.08579 5.08579 4.75 5.5 4.75H9C9.41421 4.75 9.75 5.08579 9.75 5.5V8.99998C9.75 9.41419 9.41421 9.74998 9 9.74998H5.5C5.08579 9.74998 4.75 9.41419 4.75 8.99998V5.5ZM5.5 12.75C4.25736 12.75 3.25 13.7574 3.25 15V18.5C3.25 19.7426 4.25736 20.75 5.5 20.75H9C10.2426 20.75 11.25 19.7427 11.25 18.5V15C11.25 13.7574 10.2426 12.75 9 12.75H5.5ZM4.75 15C4.75 14.5858 5.08579 14.25 5.5 14.25H9C9.41421 14.25 9.75 14.5858 9.75 15V18.5C9.75 18.9142 9.41421 19.25 9 19.25H5.5C5.08579 19.25 4.75 18.9142 4.75 18.5V15ZM12.75 5.5C12.75 4.25736 13.7574 3.25 15 3.25H18.5C19.7426 3.25 20.75 4.25736 20.75 5.5V8.99998C20.75 10.2426 19.7426 11.25 18.5 11.25H15C13.7574 11.25 12.75 10.2426 12.75 8.99998V5.5ZM15 4.75C14.5858 4.75 14.25 5.08579 14.25 5.5V8.58325L19.25 8.58325V5.5C19.25 5.08579 18.9142 4.75 18.5 4.75H5.5C5.08579 4.75 4.75 5.08579 4.75 5.5V8.58325L19.25 8.58325V5.5C19.25 5.08579 18.9142 4.75 18.5 4.75H5.5ZM19.25 10.0833H15.416V13.9165H19.25V10.0833ZM13.916 10.0833L10.083 10.0833V13.9165L13.916 13.9165V10.0833ZM8.58301 10.0833H4.75V13.9165H8.58301V10.0833ZM4.75 18.5V15.4165H8.58301V19.25H5.5C5.08579 19.25 4.75 18.9142 4.75 18.5ZM10.083 19.25V15.4165L13.916 15.4165V19.25H10.083ZM15.416 19.25V15.4165H19.25V18.5C19.25 18.9142 18.9142 19.25 18.5 19.25H15.416Z" fill="currentColor"/>'],
                    ['route' => 'assets.index', 'label' => 'Assets', 'icon' => '<path fill-rule="evenodd" clip-rule="evenodd" d="M5.25 4.5A2.25 2.25 0 0 0 3 6.75v10.5a2.25 2.25 0 0 0 2.25 2.25h13.5A2.25 2.25 0 0 0 21 17.25V6.75a2.25 2.25 0 0 0-2.25-2.25H5.25Zm.75 3h12v1.5H6V7.5Zm0 4.5h7.5v1.5H6V12Zm0 4.5h4.5V18H6v-1.5Z" fill="currentColor"/>'],
                    // Its own menu entry, not only a Quick-actions item: printing
                    // labels is a recurring job someone goes looking for in the
                    // sidebar, and burying it in a dropdown made it look removed.
                    ['route' => 'assets.qr.sheet', 'label' => 'QR Labels', 'gate' => 'viewAny', 'model' => App\Models\Asset::class, 'icon' => '<path fill-rule="evenodd" clip-rule="evenodd" d="M4 4h6v6H4V4Zm2 2v2h2V6H6Zm8-2h6v6h-6V4Zm2 2v2h2V6h-2ZM8 8H4v6h4V8Z" fill="currentColor"/><circle cx="12" cy="12" r="1.2" fill="currentColor"/><rect x="14" y="14" width="6" height="6" rx="1" fill="currentColor"/>'],
                ],
                'MANAGEMENT' => [
                    ['route' => 'transfers.index', 'label' => 'Transfers', 'icon' => '<path fill-rule="evenodd" clip-rule="evenodd" d="M12.9895 3.53033C13.2824 3.23744 13.7573 3.23744 14.0502 3.53033L20.0502 9.53033C20.3431 9.82322 20.3431 10.2981 20.0502 10.591L14.0502 16.591C13.7573 16.8839 13.2824 16.8839 12.9895 16.591C12.6966 16.2981 12.6966 15.8232 12.9895 15.5303L17.4593 11.0607H4.75C4.33579 11.0607 4 10.7249 4 10.3107C4 9.89646 4.33579 9.56067 4.75 9.56067H17.4593L12.9895 5.091C12.6966 4.79811 12.6966 4.32322 12.9895 3.53033ZM20.0502 3.53033C20.3431 3.82322 20.3431 4.29808 20.0502 4.59097L14.0502 10.591C13.7573 10.8839 13.2824 10.8839 12.9895 10.591C12.6966 10.2981 12.6966 9.82322 12.9895 9.53033L18.9895 3.53033C19.2824 3.23744 19.7573 3.23744 20.0502 3.53033Z" fill="currentColor"/>'],
                    ['route' => 'assets.import', 'label' => 'Import Excel', 'icon' => '<path fill-rule="evenodd" clip-rule="evenodd" d="M12 2.25C12.4142 2.25 12.75 2.58579 12.75 3V10.4393L14.7197 8.46967C15.0126 8.17678 15.4874 8.17678 15.7803 8.46967C16.0732 8.76256 16.0732 9.23744 15.7803 9.53033L12.5303 12.7803C12.2374 13.0732 11.7626 13.0732 11.4697 12.7803L8.21967 9.53033C7.92678 9.23744 7.92678 8.76256 8.21967 8.46967C8.51256 8.17678 8.98744 8.17678 9.28033 8.46967L11.25 10.4393V3C11.25 2.58579 11.5858 2.25 12 2.25ZM4.5 9.75C4.08579 9.75 3.75 10.0858 3.75 10.5V19.5C3.75 19.9142 4.08579 20.25 4.5 20.25H19.5C19.9142 20.25 20.25 19.9142 20.25 19.5V10.5C20.25 10.0858 19.9142 9.75 19.5 9.75H4.5Z" fill="currentColor"/>'],
                    ['route' => 'reports.index', 'label' => 'Reports', 'gate' => 'export-assets', 'icon' => '<path fill-rule="evenodd" clip-rule="evenodd" d="M3.75 3C3.33579 3 3 3.33579 3 3.75V20.25C3 20.6642 3.33579 21 3.75 21H20.25C20.6642 21 21 20.6642 21 20.25V3.75C21 3.33579 20.6642 3 20.25 3H3.75ZM5.25 5.25H18.75V18.75H5.25V5.25ZM7.5 7.5V16.5H10.5V7.5H7.5ZM12 7.5V16.5H15V7.5H12ZM16.5 7.5V13.5H18.75V7.5H16.5Z" fill="currentColor"/>'],
                    ['route' => 'activity.index', 'label' => 'Activity Log', 'gate' => 'view-activity-log', 'icon' => '<path fill-rule="evenodd" clip-rule="evenodd" d="M12 2.25C6.61522 2.25 2.25 6.61522 2.25 12C2.25 17.3848 6.61522 21.75 12 21.75C17.3848 21.75 21.75 17.3848 21.75 12C21.75 6.61522 17.3848 2.25 12 2.25ZM3.75 12C3.75 7.44365 7.44365 3.75 12 3.75C16.5563 3.75 20.25 7.44365 20.25 12C20.25 16.5563 16.5563 20.25 12 20.25C7.44365 20.25 3.75 16.5563 3.75 12ZM12.75 7.25C12.75 6.83579 12.4142 6.5 12 6.5C11.5858 6.5 11.25 6.83579 11.25 7.25V12C11.25 12.4142 11.5858 12.75 12 12.75C12.4142 12.75 12.75 12.4142 12.75 12V7.25Z" fill="currentColor"/>'],
                    ['route' => 'ike-bast.inspection.index', 'label' => 'Inspections', 'gate' => 'manage-master-data', 'icon' => '<path fill-rule="evenodd" clip-rule="evenodd" d="M12.75 2.25C12.75 1.83579 12.4142 1.5 12 1.5C11.5858 1.5 11.25 1.83579 11.25 2.25V3C11.25 3 7.5 3 5.25 3C4.00736 3 3 4.00736 3 5.25V18.75C3 19.9926 4.00736 21 5.25 21H18.75C19.9926 21 21 19.9926 21 18.75V5.25C21 4.00736 19.9926 3 18.75 3C16.5 3 12.75 3 12.75 3V2.25ZM7.5 9.75C7.08579 9.75 6.75 10.0858 6.75 10.5V16.5C6.75 16.9142 7.08579 17.25 7.5 17.25H10.5C10.9142 17.25 11.25 16.9142 11.25 16.5V10.5C11.25 10.0858 10.9142 9.75 10.5 9.75H7.5ZM13.5 9.75C13.0858 9.75 12.75 10.0858 12.75 10.5V16.5C12.75 16.9142 13.0858 17.25 13.5 17.25H16.5C16.9142 17.25 17.25 16.9142 17.25 16.5V10.5C17.25 10.0858 16.9142 9.75 16.5 9.75H13.5Z" fill="currentColor"/>'],
                    ['route' => 'barcode.form', 'label' => 'Barcode Scan', 'icon' => '<path fill-rule="evenodd" clip-rule="evenodd" d="M3 5.25C3 4.00736 4.00736 3 5.25 3H8.25C8.66421 3 9 3.33579 9 3.75C9 4.16421 8.66421 4.5 8.25 4.5H5.25C4.83579 4.5 4.5 4.83579 4.5 5.25V8.25C4.5 8.66421 4.16421 9 3.75 9C3.33579 9 3 8.66421 3 8.25V5.25ZM3 15.75C3 15.3358 3.33579 15 3.75 15C4.16421 15 4.5 15.3358 4.5 15.75V18.75C4.5 19.1642 4.83579 19.5 5.25 19.5H8.25C8.66421 19.5 9 19.1642 9 18.75C9 18.3358 8.66421 18 8.25 18H5.25V15.75ZM15 3.75C15 3.33579 15.3358 3 15.75 3H18.75C19.1642 3 19.5 3.33579 19.5 3.75C19.5 4.16421 19.1642 4.5 18.75 4.5H15.75C15.3358 4.5 15 4.16421 15 3.75ZM15 18.75C15 18.3358 15.3358 18 15.75 18C16.1642 18 16.5 18.3358 16.5 18.75V18.75C16.5 19.1642 16.8358 19.5 17.25 19.5H18.75C19.1642 19.5 19.5 19.1642 19.5 18.75C19.5 18.3358 19.1642 18 18.75 18H15.75C15.3358 18 15 18.3358 15 18.75ZM4.5 12C4.5 11.5858 4.83579 11.25 5.25 11.25H18.75C19.1642 11.25 19.5 11.5858 19.5 12C19.5 12.4142 19.1642 12.75 18.75 12.75H5.25C4.83579 12.75 4.5 12.4142 4.5 12Z" fill="currentColor"/>'],
                ],
                'DOCUMENTS' => [
                    ['route' => 'documents.index', 'label' => 'All Documents', 'icon' => '<path fill-rule="evenodd" clip-rule="evenodd" d="M3.75 4.5a.75.75 0 0 1 .75-.75h9.19l5.56 5.56a.75.75 0 0 1 .22.53v9.41a.75.75 0 0 1-.75.75h-14.25a.75.75 0 0 1-.75-.75V4.5Zm11.44 1.5H4.5v13.5h15V10.5h-3.56a.75.75 0 0 1-.53-.22L14 6.19V6Z" fill="currentColor"/>'],
                    ['route' => 'templates.index', 'label' => 'Templates', 'gate' => 'manage-master-data', 'icon' => '<path fill-rule="evenodd" clip-rule="evenodd" d="M9 4.5a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 4.5v.75H9v-.75ZM7.5 4.5A2.25 2.25 0 0 1 9.75 2.25h4.5A2.25 2.25 0 0 1 16.5 4.5v.75h1.5A2.25 2.25 0 0 1 20.25 7.5v12A2.25 2.25 0 0 1 18 21.75H6A2.25 2.25 0 0 1 3.75 19.5v-12A2.25 2.25 0 0 1 6 5.25h1.5v-.75Z" fill="currentColor"/>'],
                ],
            ];
            $masterItems = [
                ['type' => 'companies', 'label' => 'Companies'],
                ['type' => 'locations', 'label' => 'Locations'],
                ['type' => 'categories', 'label' => 'Categories'],
                ['type' => 'statuses', 'label' => 'Statuses'],
            ];
            $currentMasterType = request()->route('type');
            $masterSidebarRoute = route('master.index', $currentMasterType ?? 'companies');
        @endphp

        @php
            $navGroups['MANAGEMENT'] = array_values(array_filter(
                $navGroups['MANAGEMENT'],
                fn (array $item): bool => ! in_array($item['route'], ['assets.import', 'barcode.form'], true)
            ));
        @endphp

        <aside
            :class="[
                'sidebar z-[9999] flex h-screen max-w-[85vw] flex-col overflow-y-auto border-r border-gray-200 bg-white px-5 fixed top-0 left-0 transition-all duration-150 ease-out xl:static dark:border-gray-800 dark:bg-black',
                window.innerWidth >= 1280
                    ? 'translate-x-0'
                    : (sidebarToggle ? 'translate-x-0' : '-translate-x-full')
            ]"
            x-cloak
            @click.outside="if(window.innerWidth < 1280) sidebarToggle = false"
            :data-collapsed="window.innerWidth >= 1280 ? !sidebarExpanded : false"
        >
            <!-- Sidebar Header -->
            <div class="sidebar-header flex items-center justify-between gap-2 pt-8 pb-7">
                <a href="/" class="flex items-center justify-center gap-2 overflow-hidden">
                    <span class="logo" x-show="window.innerWidth < 1280 || sidebarExpanded">
                        <div class="flex items-center gap-2">
                            <img src="{{ asset('images/mito-icon.png') }}" alt="MITO Electronic" width="32" height="32" class="w-8 h-8 rounded-lg flex-shrink-0">
                            <span class="brand-text text-lg font-bold text-gray-800 dark:text-white whitespace-nowrap">TRACKER-ASSET</span>
                        </div>
                    </span>
                    <div class="logo-icon" x-show="window.innerWidth >= 1280 ? !sidebarExpanded : false" x-cloak>
                        <img src="{{ asset('images/mito-icon.png') }}" alt="MITO Electronic" width="32" height="32" class="w-8 h-8 rounded-lg">
                    </div>
                </a>
                <button type="button" data-sidebar-toggle
                    @click="sidebarExpanded = !sidebarExpanded"
                    :aria-label="sidebarExpanded ? 'Collapse sidebar' : 'Expand sidebar'"
                    :title="sidebarExpanded ? 'Collapse sidebar' : 'Expand sidebar'"
                    class="hidden xl:flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-gray-200 bg-gray-50 text-gray-500 shadow-sm transition-all duration-200 hover:border-gray-300 hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-brand-500/40 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400 dark:hover:border-gray-600 dark:hover:bg-gray-800 dark:hover:text-white">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" class="transition-transform duration-200" :class="sidebarExpanded ? '' : 'rotate-180'" aria-hidden="true"><path d="M10 3.5 5.5 8l4.5 4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
            </div>

            <!-- Sidebar Nav -->
            <div class="no-scrollbar flex flex-col overflow-y-auto duration-300 ease-linear">
                @foreach($navGroups as $groupName => $items)
                    <nav class="mb-6">
                        <h3 class="mb-4 text-xs font-semibold leading-5 text-gray-400 uppercase dark:text-gray-500" :class="window.innerWidth >= 1280 ? (sidebarExpanded ? '' : 'text-center') : ''">
                            <span :class="window.innerWidth >= 1280 ? (sidebarExpanded ? '' : 'hidden') : ''">{{ $groupName }}</span>
                        </h3>
                        <ul class="flex flex-col gap-1">
                            @foreach($items as $item)
                                {{-- 'model' lets an entry use a policy ability
                                     ("create" on Asset) rather than a plain named
                                     gate; without it Gate::allows('create') has
                                     nothing to resolve a policy from and denies
                                     everybody. --}}
                                @if(!isset($item['gate']) || Gate::allows($item['gate'], $item['model'] ?? []))
                                    @php $isActive = $route === $item['route'] || in_array($route, $activeRouteAliases[$item['route']] ?? [], true); @endphp
                                    <li>
                                        <a href="{{ route($item['route']) }}"
                                           @click="selectedMenu = '{{ $item['route'] }}'"
                                           class="menu-item group {{ $isActive ? 'menu-item-active' : 'menu-item-inactive' }}">
                                            <svg class="{{ $isActive ? 'menu-item-icon-active' : 'menu-item-icon-inactive' }}" width="24" height="24" viewBox="0 0 24 24" fill="none">{!! $item['icon'] !!}</svg>
                                            <span class="menu-item-text" :class="window.innerWidth >= 1280 ? (sidebarExpanded ? '' : 'hidden') : ''">{{ $item['label'] }}</span>
                                        </a>
                                    </li>
                                @endif
                            @endforeach
                        </ul>
                    </nav>
                @endforeach

                @if(Gate::allows('manage-master-data'))
                    <nav class="mb-6">
                        <h3 class="mb-4 text-xs font-semibold leading-5 text-gray-400 uppercase dark:text-gray-500" :class="window.innerWidth >= 1280 ? (sidebarExpanded ? '' : 'text-center') : ''">
                            <span :class="window.innerWidth >= 1280 ? (sidebarExpanded ? '' : 'hidden') : ''">ADMINISTRATION</span>
                        </h3>
                        <ul class="flex flex-col gap-1">
                            @php $isActive = in_array($currentMasterType, array_column($masterItems, 'type'), true) || $isSection('master.*'); @endphp
                            <li>
                                <a href="{{ $masterSidebarRoute }}"
                                   @click="selectedMenu = 'master.index'"
                                   class="menu-item group {{ $isActive ? 'menu-item-active' : 'menu-item-inactive' }}">
                                    <svg class="{{ $isActive ? 'menu-item-icon-active' : 'menu-item-icon-inactive' }}" width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="8" cy="8" r="2" fill="currentColor"/><circle cx="16" cy="8" r="2" fill="currentColor"/><circle cx="8" cy="16" r="2" fill="currentColor"/><circle cx="16" cy="16" r="2" fill="currentColor"/></svg>
                                    <span class="menu-item-text" :class="window.innerWidth >= 1280 ? (sidebarExpanded ? '' : 'hidden') : ''">Master Data</span>
                                </a>
                            </li>
                            @if(Gate::allows('manage-users'))
                                @php $isActive = $route === 'users.access'; @endphp
                                <li>
                                    <a href="{{ route('users.access') }}"
                                       @click="selectedMenu = 'users.access'"
                                       class="menu-item group {{ $isActive ? 'menu-item-active' : 'menu-item-inactive' }}">
                                        <svg class="{{ $isActive ? 'menu-item-icon-active' : 'menu-item-icon-inactive' }}" width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M12 3.75a4.25 4.25 0 1 0 0 8.5 4.25 4.25 0 0 0 0-8.5ZM4.5 20.25c.7-3.25 3.48-5.5 7.5-5.5s6.8 2.25 7.5 5.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><path d="M18 4.5v4m-2-2h4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                                        <span class="menu-item-text" :class="window.innerWidth >= 1280 ? (sidebarExpanded ? '' : 'hidden') : ''">Access Control</span>
                                    </a>
                                </li>
                                @php $isActive = $isSection('users.*'); @endphp
                                <li>
                                    <a href="{{ route('users.index') }}"
                                       @click="selectedMenu = 'users.index'"
                                       class="menu-item group {{ $isActive ? 'menu-item-active' : 'menu-item-inactive' }}">
                                        <svg class="{{ $isActive ? 'menu-item-icon-active' : 'menu-item-icon-inactive' }}" width="24" height="24" viewBox="0 0 24 24" fill="none"><path fill-rule="evenodd" clip-rule="evenodd" d="M12 3.5C7.30558 3.5 3.5 7.30558 3.5 12C3.5 14.1526 4.3002 16.1184 5.61936 17.616C6.17279 15.3096 8.24852 13.5955 10.7246 13.5955H13.2746C15.7509 13.5955 17.8268 15.31 18.38 17.6167C19.6996 16.119 20.5 14.153 20.5 12C20.5 7.30558 16.6944 3.5 12 3.5ZM17.0246 18.8566V18.8455C17.0246 16.7744 15.3457 15.0955 13.2746 15.0955H10.7246C8.65354 15.0955 6.97461 16.7744 6.97461 18.8455V18.856C8.38223 19.8895 10.1198 20.5 12 20.5C13.8798 20.5 15.6171 19.8898 17.0246 18.8566ZM2 12C2 6.47715 6.47715 2 12 2C17.5228 2 22 6.47715 22 12C22 17.5228 17.5228 22 12 22C6.47715 22 2 17.5228 2 12ZM11.9991 7.25C10.8847 7.25 9.98126 8.15342 9.98126 9.26784C9.98126 10.3823 10.8847 11.2857 11.9991 11.2857C13.1135 11.2857 14.0169 10.3823 14.0169 9.26784C14.0169 8.15342 13.1135 7.25 11.9991 7.25ZM8.48126 9.26784C8.48126 7.32499 10.0563 5.75 11.9991 5.75C13.9419 5.75 15.5169 7.32499 15.5169 9.26784C15.5169 11.2107 13.9419 12.7857 11.9991 12.7857C10.0563 12.7857 8.48126 11.2107 8.48126 9.26784Z" fill="currentColor"/></svg>
                                        <span class="menu-item-text" :class="window.innerWidth >= 1280 ? (sidebarExpanded ? '' : 'hidden') : ''">Users</span>
                                    </a>
                                </li>
                            @endif
                            @if(Gate::allows('manage-agents'))
                                @php $isActive = $route === 'agent.index'; @endphp
                                <li>
                                    <a href="{{ route('agent.index') }}"
                                       @click="selectedMenu = 'agent.index'"
                                       class="menu-item group {{ $isActive ? 'menu-item-active' : 'menu-item-inactive' }}">
                                        <svg class="{{ $isActive ? 'menu-item-icon-active' : 'menu-item-icon-inactive' }}" width="24" height="24" viewBox="0 0 24 24" fill="none"><path fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.47715 2 2 6.47715 2 12C2 17.5228 6.47715 22 12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2ZM4.5 12C4.5 7.85786 7.85786 4.5 12 4.5C16.1421 4.5 19.5 7.85786 19.5 12C19.5 16.1421 16.1421 19.5 12 19.5C7.85786 19.5 4.5 16.1421 4.5 12ZM12 7C8.13401 7 5 10.134 5 14C5 14.2761 5.22386 14.5 5.5 14.5H18.5C18.7761 14.5 19 14.2761 19 14C19 10.134 15.866 7 12 7Z" fill="currentColor"/></svg>
                                        <span class="menu-item-text" :class="window.innerWidth >= 1280 ? (sidebarExpanded ? '' : 'hidden') : ''">Audit Agents</span>
                                    </a>
                                </li>
                                @php $isActive = $route === 'agent.fleet'; @endphp
                                <li>
                                    <a href="{{ route('agent.fleet') }}"
                                       @click="selectedMenu = 'agent.fleet'"
                                       class="menu-item group {{ $isActive ? 'menu-item-active' : 'menu-item-inactive' }}">
                                        <svg class="{{ $isActive ? 'menu-item-icon-active' : 'menu-item-icon-inactive' }}" width="24" height="24" viewBox="0 0 24 24" fill="none"><path fill-rule="evenodd" clip-rule="evenodd" d="M4 5.5C4 4.25736 5.00736 3.25 6.25 3.25H9.25C10.4926 3.25 11.5 4.25736 11.5 5.5V8.5C11.5 9.74264 10.4926 10.75 9.25 10.75H6.25C5.00736 10.75 4 9.74264 4 8.5V5.5ZM6.25 4.75C5.83579 4.75 5.5 5.08579 5.5 5.5V8.5C5.5 8.91421 5.83579 9.25 6.25 9.25H9.25C9.66421 9.25 10 8.91421 10 8.5V5.5C10 5.08579 9.66421 4.75 9.25 4.75H6.25ZM4 15.5C4 14.2574 5.00736 13.25 6.25 13.25H9.25C10.4926 13.25 11.5 14.2574 11.5 15.5V18.5C11.5 19.7426 10.4926 20.75 9.25 20.75H6.25C5.00736 20.75 4 19.7426 4 18.5V15.5ZM6.25 14.75C5.83579 14.75 5.5 15.0858 5.5 15.5V18.5C5.5 18.9142 5.83579 19.25 6.25 19.25H9.25C9.66421 19.25 10 18.9142 10 18.5V15.5C10 15.0858 9.66421 14.75 9.25 14.75H6.25ZM12.5 6.25C12.5 5.83579 12.8358 5.5 13.25 5.5H20C20.4142 5.5 20.75 5.83579 20.75 6.25C20.75 6.66421 20.4142 7 20 7H13.25C12.8358 7 12.5 6.66421 12.5 6.25ZM12.5 12C12.5 11.5858 12.8358 11.25 13.25 11.25H20C20.4142 11.25 20.75 11.5858 20.75 12C20.75 12.4142 20.4142 12.75 20 12.75H13.25C12.8358 12.75 12.5 12.4142 12.5 12ZM12.5 17.75C12.5 17.3358 12.8358 17 13.25 17H20C20.4142 17 20.75 17.3358 20.75 17.75C20.75 18.1642 20.4142 18.5 20 18.5H13.25C12.8358 18.5 12.5 18.1642 12.5 17.75ZM4.25 12C4.25 11.5858 4.58579 11.25 5 11.25H11C11.4142 11.25 11.75 11.5858 11.75 12C11.75 12.4142 11.4142 12.75 11 12.75H5C4.58579 12.75 4.25 12.4142 4.25 12Z" fill="currentColor"/></svg>
                                        <span class="menu-item-text" :class="window.innerWidth >= 1280 ? (sidebarExpanded ? '' : 'hidden') : ''">Live Fleet</span>
                                    </a>
                                </li>
                            @endif
                            @if(Gate::allows('manage-users'))
                                @php $isActive = $isSection('services.*'); @endphp
                                <li>
                                    <a href="{{ route('services.index') }}"
                                       @click="selectedMenu = 'services.index'"
                                       class="menu-item group {{ $isActive ? 'menu-item-active' : 'menu-item-inactive' }}">
                                        {{-- A heartbeat trace: this page is about whether
                                             the backing services are alive. --}}
                                        <svg class="{{ $isActive ? 'menu-item-icon-active' : 'menu-item-icon-inactive' }}" width="24" height="24" viewBox="0 0 24 24" fill="none"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M2.5 12h4l2.5-6 4 13 2.5-7h6" stroke="currentColor"/></svg>
                                        <span class="menu-item-text" :class="window.innerWidth >= 1280 ? (sidebarExpanded ? '' : 'hidden') : ''">Services</span>
                                    </a>
                                </li>
                            @endif
                        </ul>
                    </nav>
                @endif
            </div>
        </aside>

        <!-- Content Area -->
        <div class="relative flex flex-col flex-1 overflow-x-hidden overflow-y-auto">
            <!-- Header -->
            <header class="sticky top-0 z-99999 flex w-full border-b border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <div class="flex w-full flex-col gap-3 px-3 py-2.5 sm:px-4 sm:py-3 xl:flex-row xl:items-center xl:justify-between xl:px-6">
                    <div class="flex w-full items-center justify-between gap-2 border-b border-gray-200 pb-2.5 xl:justify-normal xl:border-b-0 xl:pb-0 dark:border-gray-800">
                        <!-- Hamburger Toggle -->
                        <button @click.stop="sidebarToggle = !sidebarToggle"
                            :aria-label="sidebarToggle ? 'Close navigation' : 'Open navigation'"
                                class="z-[99999] flex h-10 w-10 items-center justify-center rounded-lg border border-gray-200 text-gray-500 xl:hidden dark:border-gray-800 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 transition-colors">
                            <svg :class="sidebarToggle ? 'hidden' : 'block xl:hidden'" class="fill-current" width="24" height="24" viewBox="0 0 24 24" fill="none"><path fill-rule="evenodd" clip-rule="evenodd" d="M3.25 6C3.25 5.58579 3.58579 5.25 4 5.25L20 5.25C20.4142 5.25 20.75 5.58579 20.75 6C20.75 6.41421 20.4142 6.75 20 6.75L4 6.75C3.58579 6.75 3.25 6.41422 3.25 6ZM3.25 18C3.25 17.5858 3.58579 17.25 4 17.25L20 17.25C20.4142 17.25 20.75 17.5858 20.75 18C20.75 18.4142 20.4142 18.75 20 18.75L4 18.75C3.58579 18.75 3.25 18.4142 3.25 18ZM4 11.25C3.58579 11.25 3.25 11.5858 3.25 12C3.25 12.4142 3.58579 12.75 4 12.75L12 12.75C12.4142 12.75 12.75 12.4142 12.75 12C12.75 11.5858 12.4142 11.25 12 11.25L4 11.25Z" fill=""/></svg>
                            <svg :class="sidebarToggle ? 'block xl:hidden' : 'hidden'" class="fill-current" width="24" height="24" viewBox="0 0 24 24" fill="none"><path fill-rule="evenodd" clip-rule="evenodd" d="M6.21967 7.28131C5.92678 6.98841 5.92678 6.51354 6.21967 6.22065C6.51256 5.92775 6.98744 5.92775 7.28033 6.22065L11.999 10.9393L16.7176 6.22078C17.0105 5.92789 17.4854 5.92788 17.7782 6.22078C18.0711 6.51367 18.0711 6.98855 17.7782 7.28144L13.0597 12L17.7782 16.7186C18.0711 17.0115 18.0711 17.4863 17.7782 17.7792C17.4854 18.0721 17.0105 18.0721 16.7176 17.7792L11.999 13.0607L7.28033 17.7794C6.98744 18.0722 6.51256 18.0722 6.21967 17.7794C5.92678 17.4865 5.92678 17.0116 6.21967 16.7187L10.9384 12L6.21967 7.28131Z" fill=""/></svg>
                        </button>
                    </div>

                    <div class="flex w-full items-center justify-end gap-2 sm:gap-3 xl:px-0">
                        <button id="pwa-install-button" type="button" hidden
                                class="hidden h-10 items-center gap-2 rounded-lg border border-brand-200 bg-brand-50 px-2.5 text-xs font-semibold text-brand-700 transition-colors hover:bg-brand-100 dark:border-brand-500/30 dark:bg-brand-500/10 dark:text-brand-300 sm:h-11 sm:px-3 sm:text-sm">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 19h14" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <span class="hidden sm:inline">Install app</span>
                        </button>

                        <div class="relative" x-data="{ quickActionsOpen: false }" @click.outside="quickActionsOpen = false">
                            <button type="button" @click="quickActionsOpen = !quickActionsOpen"
                                    :aria-expanded="quickActionsOpen"
                                    class="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-brand-600 text-white transition-colors hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500/40 sm:h-11 sm:w-auto sm:px-3 sm:gap-2 sm:text-sm sm:font-semibold">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg>
                                <span class="hidden sm:inline">Quick actions</span>
                            </button>
                            <div x-cloak x-show="quickActionsOpen" x-transition
                                 class="absolute right-0 z-[100000] mt-2 w-48 rounded-lg border border-gray-200 bg-white p-1 shadow-theme-lg dark:border-gray-700 dark:bg-gray-900">
                                @can('create', App\Models\Asset::class)
                                    <a href="{{ route('assets.create') }}" class="block rounded-md px-3 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800">Add Asset</a>
                                @endcan
                                @can('viewAny', App\Models\Asset::class)
                                    <a href="{{ route('assets.qr.sheet') }}" class="block rounded-md px-3 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800">QR Labels</a>
                                @endcan
                                <a href="{{ route('assets.import') }}" class="block rounded-md px-3 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800">Import Excel</a>
                            </div>
                        </div>

                        <button type="button" @click.prevent="darkMode = !darkMode"
                                :aria-label="darkMode ? 'Switch to light mode' : 'Switch to dark mode'"
                                :title="darkMode ? 'Switch to light mode' : 'Switch to dark mode'"
                                class="relative flex h-10 w-10 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-500 transition-colors hover:bg-gray-100 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-400 sm:h-11 sm:w-11">
                            <svg class="hidden dark:block" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"/></svg>
                            <svg class="dark:hidden" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.9 13A9 9 0 0 1 11 3.1 9 9 0 1 0 20.9 13Z"/></svg>
                        </button>

                        <div class="relative" x-data="{ dropdownOpen: false }" @click.outside="dropdownOpen = false">
                            <a class="flex items-center text-gray-700 dark:text-gray-400" href="#" @click.prevent="dropdownOpen = !dropdownOpen">
                                <span class="mr-2 flex h-9 w-9 items-center justify-center overflow-hidden rounded-full bg-brand-100 text-brand-600 text-xs font-semibold dark:bg-brand-500/20 dark:text-brand-400 sm:mr-3 sm:h-11 sm:w-11 sm:text-sm">
                                    {{ strtoupper(substr(Auth::user()?->name ?? 'U', 0, 1)) }}
                                </span>
                                <span class="hidden text-theme-sm font-medium sm:block">{{ Auth::user()?->name }}</span>
                                <svg :class="dropdownOpen && 'rotate-180'" class="ml-1 hidden stroke-gray-500 sm:block" width="18" height="20" viewBox="0 0 18 20" fill="none"><path d="M4.3125 8.65625L9 13.3437L13.6875 8.65625" stroke="" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </a>

                            <div x-show="dropdownOpen"
                                 x-transition:enter="transition ease-out duration-100"
                                 x-transition:enter-start="opacity-0 scale-95"
                                 x-transition:enter-end="opacity-100 scale-100"
                                 class="shadow-theme-lg dark:bg-gray-dark absolute right-0 mt-4 w-65 flex flex-col rounded-2xl border border-gray-200 bg-white p-3 dark:border-gray-800">
                                <div>
                                    <span class="text-theme-sm block font-medium text-gray-700 dark:text-gray-400">{{ Auth::user()?->name }}</span>
                                    <span class="text-theme-xs mt-0.5 block text-gray-500 dark:text-gray-400">{{ Auth::user()?->email }}</span>
                                </div>
                                <ul class="flex flex-col gap-1 border-b border-gray-200 pt-4 pb-3 dark:border-gray-800">
                                    <li class="text-theme-xs px-3 text-gray-500 capitalize">Role: {{ Auth::user()?->role }}</li>
                                </ul>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="group text-theme-sm mt-3 flex items-center gap-3 rounded-lg px-3 py-2 font-medium text-gray-700 hover:bg-gray-100 w-full dark:text-gray-400 dark:hover:bg-white/5">
                                        <svg class="fill-gray-500 group-hover:fill-gray-700" width="24" height="24" viewBox="0 0 24 24" fill="none"><path fill-rule="evenodd" clip-rule="evenodd" d="M15.1007 19.247C14.6865 19.247 14.3507 18.9112 14.3507 18.497L14.3507 14.245H12.8507V18.497C12.8507 19.7396 13.8581 20.747 15.1007 20.747H18.5007C19.7434 20.747 20.7507 19.7396 20.7507 18.497L20.7507 5.49609C20.7507 4.25345 19.7433 3.24609 18.5007 3.24609H15.1007C13.8581 3.24609 12.8507 4.25345 12.8507 5.49609V9.74501L14.3507 9.74501V5.49609C14.3507 5.08188 14.6865 4.74609 15.1007 4.74609L18.5007 4.74609C18.9149 4.74609 19.2507 5.08188 19.2507 5.49609L19.2507 18.497C19.2507 18.9112 18.9149 19.247 18.5007 19.247H15.1007ZM3.25073 11.9984C3.25073 12.2144 3.34204 12.4091 3.48817 12.546L8.09483 17.1556C8.38763 17.4485 8.86251 17.4487 9.15549 17.1559C9.44848 16.8631 9.44863 16.3882 9.15583 16.0952L5.81116 12.7484L16.0007 12.7484C16.4149 12.7484 16.7507 12.4127 16.7507 11.9984C16.7507 11.5842 16.4149 11.2484 16.0007 11.2484L5.81528 11.2484L9.15585 7.90554C9.44864 7.61255 9.44847 7.13767 9.15547 6.84488C8.86248 6.55209 8.3876 6.55226 8.09481 6.84525L3.52309 11.4202C3.35673 11.5577 3.25073 11.7657 3.25073 11.9984Z" fill=""/></svg>
                                        Sign out
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Main Content -->
            <main>
                <div class="animate-fade-up mx-auto w-full max-w-7xl p-4 md:p-6">
                    <!-- Flash messages become toasts via app.js: no banner push,
                         no layout shift, and the notice survives as a toast. -->
                    @if(session('success'))
                        <div data-flash-toast="{{ session('success') }}" data-flash-type="success" class="hidden"></div>
                    @endif
                    @if(session('error'))
                        <div data-flash-toast="{{ session('error') }}" data-flash-type="error" class="hidden"></div>
                    @endif
                    @if(session('warning'))
                        <div data-flash-toast="{{ session('warning') }}" data-flash-type="info" class="hidden"></div>
                    @endif

                    {{--
                        Validation errors.

                        The flash messages above are all this layout used to show, so a
                        rejected form came back looking exactly like a form that had
                        been saved: the page reloaded, the fields showed their stored
                        values, and nothing said why. The asset edit form had no
                        @error anywhere and no old() either, so every field the user
                        had just filled in was silently replaced by what was already
                        in the database.

                        Rendered here rather than per form so a view cannot forget:
                        a form that shows errors nowhere is indistinguishable from a
                        form that worked. Deliberately not the <x-alert> component,
                        which dismisses itself after five seconds - fine for "saved",
                        useless for "here is what to fix".
                    --}}
                    @if($errors->any())
                        <div class="mb-4 rounded-xl border border-error-200 bg-error-50 px-4 py-3 text-theme-sm text-error-700 dark:border-error-500/20 dark:bg-error-500/10 dark:text-error-400"
                             role="alert"
                             aria-labelledby="validation-errors-heading">
                            <p id="validation-errors-heading" class="font-semibold">
                                {{ trans_choice('There is :count problem with this form.|There are :count problems with this form.', $errors->count(), ['count' => $errors->count()]) }}
                            </p>
                            <ul class="mt-2 list-inside list-disc space-y-1">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @yield('content')
                </div>
            </main>
        </div>
    </div>

    <div id="ajax-action-modal" class="fixed inset-0 z-[100010] hidden items-center justify-center bg-gray-950/60 p-4 backdrop-blur-sm" role="presentation" aria-live="polite">
        <section class="animate-scale-in w-full max-w-md rounded-xl bg-white p-5 shadow-xl dark:bg-gray-900" role="dialog" aria-modal="true" aria-labelledby="ajax-action-title" aria-describedby="ajax-action-message">
            <div class="flex items-start gap-4">
                <div id="ajax-action-spinner" class="mt-0.5 shrink-0" aria-hidden="true">
                    <x-mito-loader size="md" />
                </div>
                <div class="min-w-0 flex-1">
                    <h2 id="ajax-action-title" class="text-lg font-semibold text-gray-900 dark:text-white">Working</h2>
                    <p id="ajax-action-message" class="mt-1 text-theme-sm text-gray-600 dark:text-gray-300">Please wait.</p>
                </div>
            </div>
            <div class="mt-5 flex justify-end">
                <button id="ajax-action-close" type="button" class="hidden rounded-lg bg-brand-600 px-4 py-2 text-theme-sm font-medium text-white transition hover:bg-brand-700">Done</button>
            </div>
        </section>
    </div>

    <script>
        // Async action forms (data-modal-action): POST via fetch, no redirect.
        //
        // Success dismisses itself: the modal closes, a toast confirms, and the
        // page stays exactly where it was. Only a failure keeps the modal open,
        // with the error readable and a Done button to leave.
        (() => {
            const modal = document.getElementById('ajax-action-modal');
            const title = document.getElementById('ajax-action-title');
            const message = document.getElementById('ajax-action-message');
            const spinner = document.getElementById('ajax-action-spinner');
            const closeButton = document.getElementById('ajax-action-close');

            const busyBar = () => document.getElementById('top-progress');
            const setBar = (on) => busyBar()?.classList.toggle('is-busy', on);

            const closeModal = () => {
                if (modal.dataset.busy !== 'true') {
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                }
            };

            document.querySelectorAll('form[data-modal-action]').forEach((form) => {
                form.addEventListener('submit', async (event) => {
                    event.preventDefault();

                    if (modal.dataset.busy === 'true') {
                        return;
                    }

                    modal.dataset.busy = 'true';
                    modal.classList.remove('hidden');
                    modal.classList.add('flex');
                    modal.setAttribute('aria-busy', 'true');
                    title.textContent = 'Working';
                    message.textContent = 'Sending your request. Please wait.';
                    spinner.classList.remove('hidden');
                    closeButton.classList.add('hidden');
                    setBar(true);

                    const submitButtons = [...form.querySelectorAll('button[type="submit"]')];
                    submitButtons.forEach((button) => {
                        button.disabled = true;
                        button.classList.add('opacity-80');
                    });

                    try {
                        const response = await fetch(form.action, {
                            method: 'POST',
                            body: new FormData(form),
                            headers: {
                                Accept: 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            credentials: 'same-origin',
                        });
                        const payload = response.headers.get('content-type')?.includes('application/json')
                            ? await response.json()
                            : {};

                        if (!response.ok || payload.ok !== true) {
                            throw new Error(payload.message || (response.redirected
                                ? 'Your session expired. Sign in again, then retry.'
                                : 'The action could not be completed. Please try again.'));
                        }

                        if (form.dataset.closeModal) {
                            window.dispatchEvent(new CustomEvent('close-modal', { detail: form.dataset.closeModal }));
                        }

                        // Success: no extra click. Close, toast, stay on page.
                        modal.dataset.busy = 'false';
                        modal.removeAttribute('aria-busy');
                        closeModal();
                        setBar(false);
                        window.showToast?.(payload.message || 'Done.', 'success');
                        window.dispatchEvent(new CustomEvent('async-action-done', {
                            detail: { action: form.action, message: payload.message || '' },
                        }));
                    } catch (error) {
                        title.textContent = 'Action not completed';
                        message.textContent = error.message;
                        modal.dataset.busy = 'false';
                        modal.removeAttribute('aria-busy');
                        spinner.classList.add('hidden');
                        closeButton.classList.remove('hidden');
                        setBar(false);
                        closeButton.focus();
                    } finally {
                        submitButtons.forEach((button) => {
                            button.disabled = false;
                            button.classList.remove('opacity-80');
                        });
                    }
                });
            });

            closeButton.addEventListener('click', closeModal);
            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    closeModal();
                }
            });
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    closeModal();
                }
            });
        })();
    </script>

</body>
</html>
