<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — TRACKER-ASSET</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
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
<body class="h-full font-sans" data-mito-logo="{{ asset('images/mito-icon.png') }}">

    {{-- Ambient background, same layers as the app shell (see layouts/app.blade.php). --}}
    <div class="app-aurora" aria-hidden="true">
        <div class="app-aurora-blob app-aurora-blob-red"></div>
        <div class="app-aurora-blob app-aurora-blob-amber"></div>
    </div>

    <div id="top-progress" role="progressbar" aria-label="Page loading" aria-valuemin="0" aria-valuemax="100">
        <span class="top-progress-orbit" aria-hidden="true"><img src="{{ asset('images/mito-icon.png') }}" alt="" width="24" height="24"></span>
        <span class="top-progress-label" aria-hidden="true">Loading</span>
    </div>
    <div class="relative grid min-h-full lg:grid-cols-2">

        {{-- Brand panel. Hidden below lg so a phone gets the form without a wall
             of marketing first; on desktop it is the left half of the split. --}}
        <aside class="login-brand-panel relative hidden overflow-hidden bg-gray-900 lg:flex lg:flex-col lg:justify-between lg:p-12">
            {{-- Depth without an image: layered brand glows on a dark base read as
                 a deliberate surface, and cost nothing to ship. --}}
            <div class="pointer-events-none absolute inset-0" aria-hidden="true">
                <div class="absolute -top-24 -left-24 h-96 w-96 rounded-full bg-brand-500/30 blur-3xl"></div>
                <div class="absolute top-1/3 -right-24 h-96 w-96 rounded-full bg-brand-400/20 blur-3xl"></div>
                <div class="absolute -bottom-32 left-1/4 h-96 w-96 rounded-full bg-indigo-500/20 blur-3xl"></div>
                <div class="absolute inset-0 opacity-[0.07]" style="background-image:linear-gradient(rgba(255,255,255,.6) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.6) 1px,transparent 1px);background-size:44px 44px;"></div>
            </div>

            <div class="relative z-10 flex items-center gap-3">
                <img src="{{ asset('images/mito-icon.png') }}" alt="MITO Electronic" width="44" height="44"
                     class="h-11 w-11 rounded-xl shadow-theme-lg ring-1 ring-white/10">
                <div class="leading-tight">
                    <p class="text-theme-sm font-bold tracking-wide text-white">MITO Electronic</p>
                    <p class="text-theme-xs text-white/50">IT Asset Management</p>
                </div>
            </div>

            <div class="relative z-10 max-w-md">
                <span class="mb-5 inline-flex h-12 w-12 items-center justify-center rounded-2xl border border-white/10 bg-white/10 text-brand-300 shadow-lg shadow-black/10"><x-icon name="inventory" class="h-6 w-6" /></span>
                <h1 class="text-3xl font-bold leading-tight text-white">
                    Track every asset,<br>from anywhere.
                </h1>
                <p class="mt-4 text-theme-sm leading-relaxed text-white/60">
                    One console for the hardware inventory, the laptops on the floor, and the agents reporting in.
                </p>

                <ul class="mt-8 space-y-3">
                    @foreach([
                        'Live fleet telemetry from every device',
                        'Push scripts and updates to the fleet',
                        'Audit trail for every asset movement',
                    ] as $point)
                        <li class="flex items-center gap-3 text-theme-sm text-white/75">
                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-brand-500/20 text-brand-300">
                                <x-icon name="check" class="h-3 w-3" />
                            </span>
                            {{ $point }}
                        </li>
                    @endforeach
                </ul>
            </div>

            <p class="relative z-10 text-theme-xs text-white/40">&copy; {{ date('Y') }} MITO Electronic</p>
        </aside>

        {{-- Form panel. --}}
        <main class="flex flex-col items-center justify-center px-5 py-10 sm:px-8 lg:px-12">
            {{-- Glass card: floats over the aurora so the form surface feels
                 like an app window, not a sheet of paper. --}}
            <div class="login-card animate-fade-up w-full max-w-sm rounded-2xl border border-gray-200/70 bg-white/85 p-7 shadow-theme-xl backdrop-blur-xl dark:border-gray-700/60 dark:bg-gray-900/85 sm:p-9">

                {{-- Compact brand for mobile, where the panel above is hidden. --}}
                <div class="mb-8 text-center lg:hidden">
                    <img src="{{ asset('images/mito-icon.png') }}" alt="MITO Electronic" width="64" height="64"
                         class="mb-4 inline-block h-16 w-16 rounded-2xl shadow-theme-lg">
                    <h1 class="text-title-xs font-bold text-gray-800 dark:text-white">TRACKER-ASSET</h1>
                    <p class="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">IT Asset Management System</p>
                </div>

                <div class="mb-8 hidden lg:block">
                    <span class="mb-4 inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300"><x-icon name="access" class="h-5 w-5" /></span>
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Sign in</h2>
                    <p class="mt-1.5 text-theme-sm text-gray-500 dark:text-gray-400">Welcome back. Enter your details to continue.</p>
                </div>

                @if ($errors->any())
                    <div class="mb-5 flex items-center gap-3 rounded-xl border border-error-200 bg-error-50 px-4 py-3 text-theme-sm font-medium text-error-700 dark:border-error-500/20 dark:bg-error-500/10 dark:text-error-300">
                        <x-icon name="info" class="h-5 w-5 shrink-0" />
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="/login" class="space-y-5">
                    @csrf
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Email address</label>
                        <div class="relative">
                            <x-icon name="mail" class="pointer-events-none absolute left-3 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-gray-400" />
                            <input type="email" name="email" value="{{ old('email') }}" required autofocus
                               class="w-full rounded-xl border border-gray-200 bg-white/70 py-3 pl-10 pr-4 text-theme-sm text-gray-800 placeholder:text-gray-400 shadow-sm transition focus:border-brand-400 focus:bg-white focus:ring-4 focus:ring-brand-500/10 focus:outline-none dark:border-gray-700 dark:bg-gray-800/60 dark:text-white dark:placeholder:text-gray-500 dark:focus:bg-gray-800"
                               placeholder="you@company.com">
                        </div>
                    </div>
                    <div x-data="{ show: false }">
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Password</label>
                        <div class="relative">
                            <x-icon name="lock" class="pointer-events-none absolute left-3 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-gray-400" />
                            <input :type="show ? 'text' : 'password'" name="password" required
                                   class="w-full rounded-xl border border-gray-200 bg-white/70 py-3 pl-10 pr-11 text-theme-sm text-gray-800 placeholder:text-gray-400 shadow-sm transition focus:border-brand-400 focus:bg-white focus:ring-4 focus:ring-brand-500/10 focus:outline-none dark:border-gray-700 dark:bg-gray-800/60 dark:text-white dark:placeholder:text-gray-500 dark:focus:bg-gray-800"
                                   placeholder="Enter your password">
                            <button type="button" @click="show = !show"
                                    class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 transition-colors hover:text-brand-600 dark:hover:text-brand-300"
                                    :aria-label="show ? 'Hide password' : 'Show password'">
                                <x-icon x-show="!show" name="eye" class="h-5 w-5" />
                                <x-icon x-show="show" x-cloak name="eye-off" class="h-5 w-5" />
                            </button>
                        </div>
                    </div>
                    <button type="submit"
                            class="flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-brand-600 to-brand-500 py-3 text-theme-sm font-semibold text-white shadow-lg shadow-brand-500/20 transition-all duration-200 hover:-translate-y-0.5 hover:from-brand-700 hover:to-brand-600 hover:shadow-brand-500/30 active:translate-y-0 disabled:opacity-80">
                        <span>Sign in</span><x-icon name="arrow-right" class="h-4 w-4" />
                    </button>
                </form>

                @if($entraEnabled ?? false)
                    <div class="my-5 flex items-center gap-3">
                        <div class="flex-1 border-t border-gray-200 dark:border-gray-700"></div>
                        <span class="text-theme-xs font-medium text-gray-400">or continue with</span>
                        <div class="flex-1 border-t border-gray-200 dark:border-gray-700"></div>
                    </div>
                    <a href="{{ route('auth.microsoft') }}"
                       class="flex w-full items-center justify-center gap-3 rounded-lg border border-gray-300 py-2.5 text-theme-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">
                        <svg width="18" height="18" viewBox="0 0 23 23"><rect width="11" height="11" fill="#f25022"/><rect x="12" width="11" height="11" fill="#7fba00"/><rect y="12" width="11" height="11" fill="#00a4ef"/><rect x="12" y="12" width="11" height="11" fill="#ffb900"/></svg>
                        Sign in with Microsoft
                    </a>
                @endif

                <p class="mt-6 text-center text-theme-xs text-gray-400">
                    {{ ($entraEnabled ?? false) ? 'Microsoft Entra ID SSO enabled' : 'Local Development Mode' }}
                </p>
            </div>
        </main>
    </div>
</body>
</html>
