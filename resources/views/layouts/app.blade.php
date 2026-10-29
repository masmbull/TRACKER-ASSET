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
    {{-- Alias must match bunny() in vite.config.js, familyToSlug('Plus Jakarta Sans'). --}}
    {{ \Illuminate\Support\Facades\Vite::fonts('plus-jakarta-sans') }}
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
            } catch (e) {}
        })();
    </script>
</head>
<body
    data-mito-logo="{{ asset('images/mito-icon.png') }}"
    x-data="{ darkMode: JSON.parse(localStorage.getItem('darkMode') || 'false') }"
    x-init="$watch('darkMode', v => { localStorage.setItem('darkMode', JSON.stringify(v)); document.documentElement.classList.toggle('dark', v) }); document.documentElement.classList.toggle('dark', darkMode)"
    class="font-sans"
>
    {{-- Ambient background: three slow, drifting brand glows behind the shell.
         The static wash + dot grid + grain live in body::before/::after
         (app.css); these blobs are only the moving layer. aria-hidden and
         pointer-events none: purely decorative. --}}
    <div class="app-aurora" aria-hidden="true">
        <div class="app-aurora-blob app-aurora-blob-red"></div>
        <div class="app-aurora-blob app-aurora-blob-amber"></div>
        <div class="app-aurora-blob app-aurora-blob-violet"></div>
    </div>

    <!-- Slim top progress bar: shown during navigations, async posts and
         soft polls (see app.js). -->
    <div id="top-progress" role="progressbar" aria-label="Page loading" aria-valuemin="0" aria-valuemax="100">
        <span class="top-progress-orbit" aria-hidden="true"><img src="{{ asset('images/mito-icon.png') }}" alt="" width="24" height="24"></span>
        <span class="top-progress-label" aria-hidden="true">Loading</span>
    </div>

    <!-- Toast stack: non-blocking notices, filled by app.js. -->
    <div id="toast-stack" aria-live="polite"></div>

    <!-- Page Wrapper -->
    {{-- h-screen + overflow-hidden, not min-h-screen: the page itself must not
         scroll, or the sidebar scrolls away with the content. The viewport is
         locked and the content column owns the only scrollbar. --}}
    {{-- Semi-transparent page surface: keeps cards/tables readable (no blotchy
         aurora patches behind them) while still letting the ambient wash show
         through as a soft tint. Fully transparent made the content area look
         "ngaco"; fully opaque killed the aurora entirely. --}}
    <div class="app-shell flex h-screen overflow-hidden bg-gray-50/85 dark:bg-gray-950/85">

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
                    ['route' => 'dashboard', 'label' => 'Dashboard'],
                    ['route' => 'assets.index', 'label' => 'Assets'],
                    // Its own menu entry, not only a Quick-actions item: printing
                    // labels is a recurring job someone goes looking for in the
                    // sidebar, and burying it in a dropdown made it look removed.
                    ['route' => 'assets.qr.sheet', 'label' => 'QR Labels', 'gate' => 'viewAny', 'model' => App\Models\Asset::class],
                ],
                'MANAGEMENT' => [
                    ['route' => 'transfers.index', 'label' => 'Transfers'],
                    ['route' => 'assets.import', 'label' => 'Import Excel'],
                    ['route' => 'reports.index', 'label' => 'Reports', 'gate' => 'export-assets'],
                    ['route' => 'activity.index', 'label' => 'Activity Log', 'gate' => 'view-activity-log'],
                    ['route' => 'ike-bast.inspection.index', 'label' => 'Inspections', 'gate' => 'manage-master-data'],
                    ['route' => 'barcode.form', 'label' => 'Barcode Scan'],
                ],
                'DOCUMENTS' => [
                    ['route' => 'documents.index', 'label' => 'All Documents'],
                    ['route' => 'templates.index', 'label' => 'Templates', 'gate' => 'manage-master-data'],
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

        <aside class="sidebar flex h-screen shrink-0 flex-col overflow-hidden border-r border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900" aria-label="Main navigation">
            <!-- Sidebar Header -->
            <div class="sidebar-header flex shrink-0 items-center gap-3 border-b border-gray-100 px-5 py-5 dark:border-gray-800">
                <a href="{{ route('dashboard') }}" class="sidebar-brand flex min-w-0 items-center gap-3" aria-label="TRACKER-ASSET dashboard">
                    <img src="{{ asset('images/mito-icon.png') }}" alt="" width="38" height="38" class="h-[38px] w-[38px] shrink-0 rounded-xl ring-1 ring-black/5 dark:ring-white/10">
                    <span class="sidebar-brand-copy min-w-0">
                        <span class="block truncate text-[14px] font-extrabold tracking-tight text-gray-900 dark:text-white">TRACKER-ASSET</span>
                        <span class="mt-0.5 block text-[10px] font-semibold uppercase tracking-[0.16em] text-gray-400 dark:text-gray-500">MITO · IT Operations</span>
                    </span>
                </a>
            </div>

            <!-- Sidebar Nav -->
            <div class="no-scrollbar flex min-h-0 flex-1 flex-col overflow-y-auto py-5 duration-300 ease-linear">
                @foreach($navGroups as $groupName => $items)
                    <nav class="mb-6">
                        <h3 class="sidebar-group-label mb-2 px-3 text-[10px] font-bold leading-5 tracking-[0.15em] text-gray-400 uppercase dark:text-gray-500">
                            <span>{{ $groupName }}</span>
                        </h3>
                        <ul class="flex flex-col gap-2">
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
                                           data-tooltip="{{ $item['label'] }}"
                                           class="menu-item group {{ $isActive ? 'menu-item-active' : 'menu-item-inactive' }}">
                                            <span class="sidebar-icon-frame {{ $isActive ? 'sidebar-icon-frame-active' : '' }}">
                                                <x-icon :name="$item['route']" class="sidebar-icon" />
                                            </span>
                                            <span class="menu-item-text">{{ $item['label'] }}</span>
                                        </a>
                                    </li>
                                @endif
                            @endforeach
                        </ul>
                    </nav>
                @endforeach

                @if(Gate::allows('manage-master-data'))
                    <nav class="mb-6">
                        <h3 class="sidebar-group-label mb-2 px-3 text-[10px] font-bold leading-5 tracking-[0.15em] text-gray-400 uppercase dark:text-gray-500">
                            <span>ADMINISTRATION</span>
                        </h3>
                        <ul class="flex flex-col gap-2">
                            @php $isActive = in_array($currentMasterType, array_column($masterItems, 'type'), true) || $isSection('master.*'); @endphp
                            <li>
                                <a href="{{ $masterSidebarRoute }}"
                                    data-tooltip="Master Data"
                                   class="menu-item group {{ $isActive ? 'menu-item-active' : 'menu-item-inactive' }}">
                                    <span class="sidebar-icon-frame {{ $isActive ? 'sidebar-icon-frame-active' : '' }}"><x-icon name="master" class="sidebar-icon" /></span>
                                    <span class="menu-item-text">Master Data</span>
                                </a>
                            </li>
                            @if(Gate::allows('manage-users'))
                                @php $isActive = $route === 'users.access'; @endphp
                                <li>
                                    <a href="{{ route('users.access') }}"
                                       data-tooltip="Access Control"
                                       class="menu-item group {{ $isActive ? 'menu-item-active' : 'menu-item-inactive' }}">
                                        <span class="sidebar-icon-frame {{ $isActive ? 'sidebar-icon-frame-active' : '' }}"><x-icon name="access" class="sidebar-icon" /></span>
                                        <span class="menu-item-text">Access Control</span>
                                    </a>
                                </li>
                                @php
                                    // `users.*` matches users.access too, so Access Control lit
                                    // up both its own row and Users below it. Excluded explicitly
                                    // rather than narrowed to a list: the intent is "any users page
                                    // that is not its own menu entry".
                                    $isActive = $isSection('users.*') && ! $isSection('users.access');
                                @endphp
                                <li>
                                    <a href="{{ route('users.index') }}"
                                       data-tooltip="Users"
                                       class="menu-item group {{ $isActive ? 'menu-item-active' : 'menu-item-inactive' }}">
                                        <span class="sidebar-icon-frame {{ $isActive ? 'sidebar-icon-frame-active' : '' }}"><x-icon name="users" class="sidebar-icon" /></span>
                                        <span class="menu-item-text">Users</span>
                                    </a>
                                </li>
                            @endif
                            @if(Gate::allows('manage-agents'))
                                {{-- One entry for agents. The token list and the
                                     fleet readings were two sidebar items for one
                                     job - a machine's token is just how the fleet
                                     page identifies it - so Tokens is now a tab on
                                     the fleet page rather than its own destination. --}}
                                @php $isActive = in_array($route, ['agent.fleet', 'agent.index'], true) || $isSection('agent.*'); @endphp
                                <li>
                                    <a href="{{ route('agent.fleet') }}"
                                       data-tooltip="Live Fleet"
                                       class="menu-item group {{ $isActive ? 'menu-item-active' : 'menu-item-inactive' }}">
                                        <span class="sidebar-icon-frame {{ $isActive ? 'sidebar-icon-frame-active' : '' }}"><x-icon name="agent.fleet" class="sidebar-icon" /></span>
                                        <span class="menu-item-text">Live Fleet</span>
                                    </a>
                                </li>
                            @endif
                            @if(Gate::allows('manage-users'))
                                @php $isActive = $isSection('services.*'); @endphp
                                <li>
                                    <a href="{{ route('services.index') }}"
                                       data-tooltip="Services"
                                       class="menu-item group {{ $isActive ? 'menu-item-active' : 'menu-item-inactive' }}">
                                        {{-- A heartbeat trace: this page is about whether
                                             the backing services are alive. --}}
                                        <span class="sidebar-icon-frame {{ $isActive ? 'sidebar-icon-frame-active' : '' }}"><x-icon name="services.index" class="sidebar-icon" /></span>
                                        <span class="menu-item-text">Services</span>
                                    </a>
                                </li>
                            @endif
                        </ul>
                    </nav>
                @endif
            </div>
        </aside>

        <!-- Content Area -->
        {{-- The only scrolling region on the page. min-w-0 lets it shrink beside
             the sidebar instead of forcing the flex row wider. --}}
        <div class="app-content relative flex min-w-0 flex-1 flex-col overflow-x-hidden overflow-y-auto">
            <!-- Header -->
            {{-- Glass header: translucent over the aurora so scrolling content
                 reads through the blur. Fallback keeps it readable on browsers
                 without backdrop-filter via the /80 alpha. --}}
            <header class="app-topbar sticky top-0 z-99999 flex w-full border-b border-gray-200 bg-white/90 backdrop-blur-xl dark:border-gray-800 dark:bg-gray-900/90">
                <div class="flex min-h-[68px] w-full items-center justify-between gap-3 px-4 sm:px-6 lg:px-8">
                    @php
                        $topbarIcons = [
                            'dashboard' => 'dashboard', 'assets.index' => 'inventory', 'assets.show' => 'inventory',
                            'assets.create' => 'plus', 'assets.edit' => 'inventory', 'assets.qr.sheet' => 'assets.qr.sheet',
                            'assets.transfer' => 'transfer', 'assets.repair.create' => 'repair', 'assets.retired' => 'warning',
                            'assets.audit' => 'activity', 'assets.audit.device' => 'laptop-pulse', 'assets.import' => 'assets.import',
                            'agent.fleet' => 'agent.fleet', 'agent.commands' => 'activity', 'agent.index' => 'download',
                            'agent.releases' => 'cloud-download', 'agent.fingerprint' => 'inventory', 'agent.promote' => 'plus',
                            'documents.index' => 'documents.index', 'documents.create' => 'plus', 'documents.generate' => 'documents.index', 'documents.preview' => 'documents.index',
                            'templates.index' => 'templates.index', 'templates.create' => 'plus', 'templates.show' => 'templates.index',
                            'transfers.index' => 'transfers.index', 'reports.index' => 'reports.index', 'activity.index' => 'activity.index',
                            'services.index' => 'services.index', 'users.index' => 'users', 'users.create' => 'plus', 'users.edit' => 'users', 'users.access' => 'access',
                            'master.index' => 'master', 'master.create' => 'master', 'master.edit' => 'master', 'barcode.form' => 'barcode.form',
                            'ike-bast.inspection.index' => 'ike-bast.inspection.index', 'ike-bast.inspection.create' => 'ike-bast.inspection.index', 'ike-bast.inspection.show' => 'ike-bast.inspection.index',
                        ];
                        $topbarIcon = $topbarIcons[$route] ?? 'dashboard';
                    @endphp
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-gray-400 dark:text-gray-500">MITO ELECTRONIC <span class="mx-1 text-gray-300 dark:text-gray-700">/</span> WORKSPACE</p>
                        <p class="mt-0.5 flex min-w-0 items-center gap-2 truncate text-sm font-semibold text-gray-800 dark:text-gray-100"><x-icon :name="$topbarIcon" class="h-4 w-4 shrink-0 text-brand-500" /><span class="truncate">@yield('title', 'Overview')</span></p>
                    </div>
                    <div class="flex shrink-0 items-center justify-end gap-2 sm:gap-3">
                        <button id="pwa-install-button" type="button" hidden
                                class="hidden h-10 items-center gap-2 rounded-lg border border-brand-200 bg-brand-50 px-2.5 text-xs font-semibold text-brand-700 transition-colors hover:bg-brand-100 dark:border-brand-500/30 dark:bg-brand-500/10 dark:text-brand-300 sm:h-11 sm:px-3 sm:text-sm">
                            <x-icon name="install" class="h-4 w-4" />
                            <span class="hidden sm:inline">Install app</span>
                        </button>

                        <button type="button" @click.prevent="darkMode = !darkMode"
                                :aria-label="darkMode ? 'Switch to light mode' : 'Switch to dark mode'"
                                :title="darkMode ? 'Switch to light mode' : 'Switch to dark mode'"
                                class="relative flex h-10 w-10 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-500 transition-colors hover:bg-gray-100 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-400 sm:h-11 sm:w-11">
                            <x-icon name="sun" class="hidden h-5 w-5 dark:block" />
                            <x-icon name="moon" class="h-5 w-5 dark:hidden" />
                        </button>

                        <div class="relative" x-data="{ dropdownOpen: false }" @click.outside="dropdownOpen = false">
                            <a class="flex items-center text-gray-700 dark:text-gray-400" href="#" @click.prevent="dropdownOpen = !dropdownOpen">
                                <span class="mr-2 flex h-9 w-9 items-center justify-center overflow-hidden rounded-full bg-brand-100 text-brand-600 text-xs font-semibold dark:bg-brand-500/20 dark:text-brand-400 sm:mr-3 sm:h-11 sm:w-11 sm:text-sm">
                                    {{ strtoupper(substr(Auth::user()?->name ?? 'U', 0, 1)) }}
                                </span>
                                <span class="hidden text-theme-sm font-medium sm:block">{{ Auth::user()?->name }}</span>
                                <x-icon name="chevron-down" ::class="dropdownOpen && 'rotate-180'" class="ml-1 hidden h-4 w-4 text-gray-500 sm:block" />
                            </a>

                            <div x-show="dropdownOpen"
                                 x-transition:enter="transition ease-[cubic-bezier(0.22,1.36,0.36,1)] duration-200"
                                 x-transition:enter-start="opacity-0 scale-95 translate-y-1" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                                 x-transition:leave="transition ease-in duration-100"
                                 x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                                 class="shadow-theme-lg dark:bg-gray-dark absolute right-0 mt-4 origin-top-right w-65 flex flex-col rounded-2xl border border-gray-200 bg-white p-3 dark:border-gray-800">
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
                                        <x-icon name="logout" class="h-5 w-5 text-gray-500 transition-colors group-hover:text-gray-700" />
                                        Sign out
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Main Content -->
            <main class="app-main">
                <div class="app-main-inner animate-fade-up mx-auto w-full max-w-7xl p-4 md:p-6">
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
                                {{ trans_choice('Ada :count masalah pada permintaan ini.|Ada :count masalah pada permintaan ini.', $errors->count(), ['count' => $errors->count()]) }}
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

    {{-- Below the topbar, not level with it. The overlay used the same z-index
         as the header (99999) and came later in the DOM, so it covered the
         header: the page dimmed, the top bar lost its contents, and all that was
         left on screen was a rounded empty box under the address bar. A progress
         overlay reports on an action; it should not erase the navigation. --}}
    <div id="ajax-action-modal" class="process-overlay fixed inset-0 z-[9998] hidden items-center justify-center bg-gray-950/60 p-4 backdrop-blur-md" role="presentation" aria-live="polite">
        <section class="modal-panel process-dialog animate-scale-in w-full max-w-lg rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-xl dark:border-gray-700 dark:bg-[#182230]" role="dialog" aria-modal="true" aria-labelledby="ajax-action-title" aria-describedby="ajax-action-message">
            <div class="flex items-start gap-4">
                <div id="ajax-action-icon" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300" aria-hidden="true">
                    <x-icon name="activity" class="h-5 w-5" />
                </div>
                <div id="ajax-action-spinner" class="mt-0.5 hidden shrink-0" aria-hidden="true">
                    <x-mito-loader size="md" />
                </div>
                <div class="min-w-0 flex-1">
                    <h2 id="ajax-action-title" class="text-lg font-semibold text-gray-900 dark:text-white">Working</h2>
                    <p id="ajax-action-message" class="mt-1 text-theme-sm text-gray-600 dark:text-gray-300">Please wait.</p>
                </div>
            </div>

            {{-- Progress bar, shown only once the server has told us which command
                 to watch. The spinner covers "sending the request"; this covers
                 "the agent is doing the work", which is a different wait entirely -
                 a scan can run for a minute and an old spinner just spins. --}}
            <div id="ajax-action-progress" class="mt-4 hidden">
                <div class="mb-2 flex items-center justify-between text-theme-xs">
                    <span id="ajax-action-stage" class="font-medium text-gray-500 dark:text-gray-400">Starting…</span>
                    <span id="ajax-action-percent" class="tabular-nums font-semibold text-gray-700 dark:text-gray-200">0%</span>
                </div>
                <div class="process-progress-track h-2.5 w-full overflow-hidden rounded-full bg-gray-100 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <div id="ajax-action-bar" class="h-full rounded-full bg-gradient-to-r from-brand-600 via-brand-500 to-rose-400 transition-[width] duration-500 ease-out" role="progressbar" aria-label="Task progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" style="width: 0%"></div>
                </div>
            </div>

            {{-- The named phases of this action, as a checklist. The bar answers "how
                 far"; this answers "how far to what", which a lone 40% cannot.
                 Filled by watchCommand/watchBatch from the status endpoint. --}}
            <ol id="ajax-action-steps" class="mt-4 hidden space-y-2"></ol>

            {{-- One line per device for a fleet-wide action. The aggregate bar
                 above says how far the rollout is, but "which laptop is stuck" is
                 the question an operator actually has, and a count of "0 of 3"
                 cannot answer it. Filled by watchBatch from the per-command
                 status endpoint, which now carries the device name. --}}
            <ul id="ajax-action-devices" class="mt-3 hidden max-h-52 space-y-1.5 overflow-y-auto"></ul>

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
            const actionIcon = document.getElementById('ajax-action-icon');
            const closeButton = document.getElementById('ajax-action-close');
            const progressWrap = document.getElementById('ajax-action-progress');
            const stageEl = document.getElementById('ajax-action-stage');
            const percentEl = document.getElementById('ajax-action-percent');
            const barEl = document.getElementById('ajax-action-bar');
            const devicesEl = document.getElementById('ajax-action-devices');
            const stepsEl = document.getElementById('ajax-action-steps');

            const busyBar = () => document.getElementById('top-progress');
            const setBar = (on) => busyBar()?.classList.toggle('is-busy', on);

            const closeModal = () => {
                if (modal.dataset.busy !== 'true') {
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                    window.clearInterval(pollTimer);
                    pollTimer = null;
                    clearDeviceList();
                    clearSteps();
                }
            };

            let pollTimer = null;
            let pollStartedAt = 0;

            const setProgressBar = (percent, stage, stalled, failed) => {
                const clamped = Math.max(5, Math.min(100, Number(percent) || 0));
                barEl.style.width = clamped + '%';
                barEl.setAttribute('aria-valuenow', String(Math.max(0, Math.min(100, Number(percent) || 0))));
                barEl.classList.toggle('from-error-600', Boolean(stalled) || Boolean(failed));
                barEl.classList.toggle('via-error-500', Boolean(stalled) || Boolean(failed));
                barEl.classList.toggle('to-rose-400', Boolean(stalled) || Boolean(failed));
                barEl.classList.toggle('from-brand-600', !stalled && !failed);
                barEl.classList.toggle('via-brand-500', !stalled && !failed);
                barEl.classList.toggle('to-rose-400', !stalled && !failed);
                percentEl.textContent = (Number(percent) || 0) + '%';
                if (stage) {
                    stageEl.textContent = stage;
                }
                stageEl.classList.toggle('text-error-500', Boolean(stalled));
            };

            /**
             * Keep the bar visibly busy between reported stages.
             *
             * The agent reports a stage, then can spend tens of seconds inside it
             * (WMI hardware sweep, registry fingerprint walk). A frozen bar reads
             * as a hung job, so while the command is in flight the fill gets moving
             * stripes and the stage line carries a live elapsed counter - motion
             * that is honest, because the job really is still running.
             */
            const setBusy = (busy) => {
                barEl.classList.toggle('progress-stripes', Boolean(busy));
                if (!busy) {
                    window.clearInterval(busyTimer);
                    busyTimer = null;
                }
            };
            let busyTimer = null;
            const startElapsedTicker = (baseLabel) => {
                window.clearInterval(busyTimer);
                const base = baseLabel.replace(/\s*·\s*\d+s$/, '');
                busyTimer = window.setInterval(() => {
                    const secs = Math.round((Date.now() - pollStartedAt) / 1000);
                    stageEl.textContent = base + ' · ' + secs + 's';
                }, 1000);
            };

            /**
             * One row per device for a fleet-wide action: name, state, and the
             * agent's own stage sentence when it has one. Rendered from the
             * status poll every couple of seconds, so it stays current without any
             * server-side push.
             */
            const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (ch) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
            }[ch]));

            const renderDeviceList = (states) => {
                if (!devicesEl) {
                    return;
                }

                // Only for a real batch: a single command already has the bar and
                // a device list of one would just repeat its name.
                if (!Array.isArray(states) || states.length < 2) {
                    devicesEl.classList.add('hidden');
                    devicesEl.innerHTML = '';
                    return;
                }

                devicesEl.classList.remove('hidden');
                devicesEl.innerHTML = states.map((s) => {
                    const name = escapeHtml((s && s.device) ? s.device : 'device');
                    let badge, tone, detail = '';

                    if (s && s.done) {
                        badge = 'done'; tone = 'text-success-600 dark:text-success-400';
                    } else if (s && s.failed) {
                        badge = 'failed'; tone = 'text-error-500'; detail = s.message || '';
                    } else if (s && s.stalled) {
                        badge = 'not responding'; tone = 'text-error-500';
                    } else if (s && s.progress !== null && s.progress !== undefined) {
                        badge = s.progress + '%'; tone = 'text-gray-600 dark:text-gray-300';
                        detail = s.label || '';
                    } else {
                        badge = 'queued'; tone = 'text-gray-500 dark:text-gray-400';
                    }

                    const sub = detail
                        ? '<span class="block truncate text-theme-xs text-gray-400">' + escapeHtml(detail) + '</span>'
                        : '';

                    return '<li class="flex items-start justify-between gap-3 rounded-lg bg-gray-50 px-3 py-1.5 dark:bg-gray-800/60">'
                        + '<span class="min-w-0"><span class="block truncate text-theme-sm font-medium text-gray-700 dark:text-gray-200">' + name + '</span>' + sub + '</span>'
                        + '<span class="shrink-0 text-theme-xs font-medium ' + tone + '">' + badge + '</span>'
                        + '</li>';
                }).join('');
            };
            const clearDeviceList = () => {
                if (devicesEl) {
                    devicesEl.classList.add('hidden');
                    devicesEl.innerHTML = '';
                }
            };

            /**
             * The phase checklist: each step of the action, with a tick, a spinner
             * or a dash. Uses the same escape helper as the device list.
             */
            const renderSteps = (steps) => {
                if (!stepsEl) {
                    return;
                }

                if (!Array.isArray(steps) || steps.length === 0) {
                    stepsEl.classList.add('hidden');
                    stepsEl.innerHTML = '';
                    return;
                }

                stepsEl.classList.remove('hidden');
                stepsEl.innerHTML = steps.map((step) => {
                    const label = escapeHtml(step.label);
                    let icon, tone;

                    if (step.state === 'done') {
                        tone = 'text-gray-500 dark:text-gray-400';
                        icon = '<svg class="h-4 w-4 text-success-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg>';
                    } else if (step.state === 'active') {
                        tone = 'font-medium text-gray-800 dark:text-gray-100';
                        icon = '<span class="inline-block h-3.5 w-3.5 animate-spin rounded-full border-2 border-brand-500 border-t-transparent"></span>';
                    } else if (step.state === 'failed') {
                        tone = 'font-medium text-error-500';
                        icon = '<svg class="h-4 w-4 text-error-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v4m0 4h.01"/></svg>';
                    } else {
                        tone = 'text-gray-400 dark:text-gray-500';
                        icon = '<span class="inline-block h-1.5 w-1.5 rounded-full bg-gray-300 dark:bg-gray-600"></span>';
                    }

                    const active = step.state === 'active';
                    const stateLabel = step.state === 'done' ? 'Complete' : (active ? 'In progress' : (step.state === 'failed' ? 'Failed' : 'Waiting'));
                    return '<li class="flex min-h-9 items-center gap-2.5 rounded-lg border border-gray-200 bg-gray-50/80 px-3 py-1.5 text-theme-sm dark:border-gray-700 dark:bg-gray-800/60 ' + tone + '">'
                        + '<span class="flex h-5 w-5 shrink-0 items-center justify-center">' + icon + '</span>'
                        + '<span class="min-w-0 flex-1 truncate">' + label + '</span>'
                        + '<span class="shrink-0 text-theme-xs ' + (active ? 'font-semibold text-brand-600 dark:text-brand-300' : 'text-gray-400 dark:text-gray-500') + '">' + stateLabel + '</span>'
                        + '</li>';
                }).join('');
            };
            const clearSteps = () => {
                if (stepsEl) {
                    stepsEl.classList.add('hidden');
                    stepsEl.innerHTML = '';
                }
            };

            /**
             * Watch the queued command until it settles, patching the bar.
             *
             * The POST has already returned - the action is only *queued* at that
             * point, and the agent may be several seconds away from picking it up.
             * Polling the command's own status is what turns "Sending your request"
             * into a bar that actually reflects the machine: 0% while it waits for
             * the agent, live percentages while it runs, and a clear end at
             * done/failed. Any failure here is silence, never an error dialog - a
             * lost poll does not mean the scan failed.
             */
            const watchCommand = (commandId) => {
                spinner.classList.add('hidden');
                actionIcon.classList.remove('hidden');
                progressWrap.classList.remove('hidden');
                clearDeviceList();
                clearSteps();
                title.textContent = 'Working';
                pollStartedAt = Date.now();
                setProgressBar(0, 'Waiting for the device…', false, false);
                setBusy(true);
                startElapsedTicker('Waiting for the device…');

                window.clearInterval(pollTimer);
                pollTimer = window.setInterval(async () => {
                    try {
                        const response = await fetch('/agent/commands/' + commandId + '/status', {
                            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                            credentials: 'same-origin',
                        });
                        if (!response.ok) {
                            return;
                        }
                        const state = await response.json();

                        // Name the action once the server tells us what it is, so
                        // the dialog says "Updating", "Scanning", "Running script"
                        // instead of a generic "Working".
                        if (state.command) {
                            title.textContent = {
                                update: 'Updating agent',
                                scan: 'Scanning device',
                                elevate: 'Requesting administrator',
                                script: 'Running script',
                                uninstall: 'Removing agent',
                            }[state.command] || 'Working';
                        }

                        renderSteps(state.steps);

                        if (state.done || state.failed) {
                            window.clearInterval(pollTimer);
                            pollTimer = null;
                            setBusy(false);
                            setProgressBar(state.done ? 100 : (state.progress ?? 100), state.failed ? 'Failed' : 'Completed', false, state.failed);
                            modal.dataset.busy = 'false';
                            modal.removeAttribute('aria-busy');
                            spinner.classList.remove('hidden');
                            closeButton.classList.remove('hidden');
                            setBar(false);
                            title.textContent = state.failed ? 'Action failed' : 'Done';
                            message.textContent = state.message || (state.failed ? 'The device reported a failure.' : 'The device finished this action.');
                            window.showToast?.(state.message || (state.failed ? 'Action failed.' : 'Done.'), state.failed ? 'error' : 'success');
                            window.dispatchEvent(new CustomEvent('async-action-done', { detail: { message: state.message || '' } }));
                            return;
                        }

                        if (state.stalled) {
                            // Release the dialog but keep watching. A dead agent
                            // must not pin the modal open, and "not responding" is
                            // what an operator can act on - but the inbox redelivers
                            // a stalled command, so this device can still come back
                            // and finish, and a dialog frozen on a failure it no
                            // longer owes is its own lie.
                            setBusy(false);
                            window.clearInterval(busyTimer);
                            busyTimer = null;
                            modal.dataset.busy = 'false';
                            modal.removeAttribute('aria-busy');
                            closeButton.classList.remove('hidden');
                            setBar(false);
                            const device = state.device ? state.device + ': ' : '';
                            const explanation = state.status === 'pending'
                                ? 'The agent has not collected this command. It stays queued and is retried once the device is online.'
                                : 'The agent stopped reporting progress. Check the device before trying again.';
                            setProgressBar(state.progress, device + explanation, true, false);
                            title.textContent = 'Device not responding';
                            message.textContent = explanation;
                            return;
                        }

                        setProgressBar(state.progress, state.label || 'In progress…', state.stalled, false);
                        setBusy(!state.stalled);
                        startElapsedTicker(state.label || 'In progress…');
                    } catch {
                        // Silence; the next tick retries.
                    }
                }, 1500);
            };

            /**
             * Watch a broadcast rollout: one command per device, each on its own clock.
             *
             * "Push to fleet" queues an update per machine, so there is no single
             * percentage to show - one device may be offline and not started, another
             * already back up. The bar therefore tracks how many queued updates have
             * settled, with the stage of whichever device is furthest along as the
             * detail line beneath it.
             */
            const watchBatch = (commandIds) => {
                spinner.classList.add('hidden');
                actionIcon.classList.remove('hidden');
                progressWrap.classList.remove('hidden');
                clearSteps();
                title.textContent = 'Fleet action';
                pollStartedAt = Date.now();
                setProgressBar(0, '0 of ' + commandIds.length + ' devices...', false, false);
                setBusy(true);
                startElapsedTicker('0 of ' + commandIds.length + ' devices...');

                window.clearInterval(pollTimer);
                pollTimer = window.setInterval(async () => {
                    try {
                        const states = await Promise.all(commandIds.map(async (id) => {
                            const response = await fetch('/agent/commands/' + id + '/status', {
                                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                                credentials: 'same-origin',
                            });
                            return response.ok ? response.json() : null;
                        }));

                        renderDeviceList(states);

                        const settled = states.filter((s) => s && (s.done || s.failed));
                        const failed = settled.filter((s) => s && s.failed).length;

                        // A device whose agent never answers must not pin the bar
                        // open forever. Once a command reads as stalled (the agent
                        // went quiet past the grace window) it is counted as
                        // settled-unfinished, so the rollout can report itself
                        // rather than sitting at "2 of 5 devices" for an hour on a
                        // laptop that is simply switched off.
                        const stalled = states.filter((s) => s && s.stalled && !s.done && !s.failed).length;
                        const accounted = settled.length + stalled;

                        const total = commandIds.length;
                        const percent = Math.round((accounted / total) * 100);
                        const failedNote = failed > 0 ? ' (' + failed + ' failed)' : '';
                        const stalledNote = stalled > 0 ? ' (' + stalled + ' not responding)' : '';
                        const completedCount = accounted;

                        if (accounted === total) {
                            window.clearInterval(pollTimer);
                            pollTimer = null;
                            setBusy(false);
                            setProgressBar(100, completedCount + ' of ' + total + ' devices accounted for' + failedNote + stalledNote, false, failed > 0 || stalled > 0);
                            modal.dataset.busy = 'false';
                            modal.removeAttribute('aria-busy');
                            spinner.classList.remove('hidden');
                            closeButton.classList.remove('hidden');
                            setBar(false);
                            title.textContent = (failed + stalled) > 0 ? 'Fleet action finished with issues' : 'Done';
                            const successful = settled.length - failed;
                            message.textContent = successful + ' of ' + total + ' devices updated successfully' + failedNote + stalledNote + '. Stalled devices are not confirmed as updated.';
                            window.showToast?.('Fleet action: ' + successful + '/' + total + ' completed' + failedNote + stalledNote + '.', (failed + stalled) > 0 ? 'error' : 'success');
                            window.dispatchEvent(new CustomEvent('async-action-done', { detail: { message: '' } }));
                            return;
                        }

                        const furthest = states.find((s) => s && s.status === 'in_progress') || states.find((s) => s && !s.done && !s.failed && !s.stalled);
                        const detail = furthest?.label || (stalled > 0 ? stalled + ' not responding' : 'Waiting for devices…');
                        setProgressBar(percent, accounted + ' of ' + total + ' devices accounted for · ' + detail, false, stalled > 0);
                        setBusy(true);
                        startElapsedTicker(settled.length + ' of ' + total + ' devices · ' + detail);
                    } catch {
                        // Silence; the next tick retries.
                    }
                }, 2000);
            };

            // Delegated on document, not bound per form.
            //
            // The per-form binding this replaces ran once, at load, over a
            // querySelectorAll snapshot. The Update button on Live Fleet lives in
            // an Alpine modal whose markup is teleported to <body>, so the form
            // it submits could be moved or (re)rendered outside that snapshot -
            // and a form with no listener fell through to a normal browser submit,
            // which the server answered with a redirect. The symptom was exactly
            // "the Update button does nothing and no progress modal appears".
            // Listening on the document catches every current and future
            // data-modal-action form, wherever Alpine puts it.
            document.addEventListener('submit', async (event) => {
                const form = event.target.closest('form[data-modal-action]');
                if (form === null) {
                    return;
                }

                event.preventDefault();

                if (modal.dataset.busy === 'true') {
                    return;
                }

                window.clearInterval(pollTimer);
                pollTimer = null;
                progressWrap.classList.add('hidden');
                modal.dataset.busy = 'true';
                modal.classList.remove('hidden');
                modal.classList.add('flex');
                modal.setAttribute('aria-busy', 'true');
                title.textContent = 'Working';
                message.textContent = 'Sending your request. Please wait.';
                spinner.classList.remove('hidden');
                actionIcon.classList.add('hidden');
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

                    // A queued command is not a finished action: keep the dialog
                    // open and watch it. `command_ids` is the shape every
                    // multi-command action returns (Push Script, update-all), and
                    // it may hold exactly one id when the target was a single
                    // device - so it is watched as a batch from one upward, not
                    // from two. Requiring more than one made a one-laptop push
                    // skip the modal entirely and close on a bare toast.
                    if (payload.command_ids && payload.command_ids.length >= 1) {
                        submitButtons.forEach((button) => {
                            button.disabled = false;
                            button.classList.remove('opacity-80');
                        });

                        if (payload.command_ids.length === 1) {
                            watchCommand(payload.command_ids[0]);
                        } else {
                            watchBatch(payload.command_ids);
                        }

                        return;
                    }

                    if (payload.command_id) {
                        submitButtons.forEach((button) => {
                            button.disabled = false;
                            button.classList.remove('opacity-80');
                        });
                        watchCommand(payload.command_id);
                        return;
                    }

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
