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
                if (sessionStorage.getItem('mito-splash-seen') === '1') {
                    document.documentElement.classList.add('mito-splash-seen');
                }
            } catch (e) {}
        })();
    </script>
    <style>.mito-splash-seen #mito-splash{display:none!important}</style>
</head>
<body class="h-full bg-white font-outfit dark:bg-gray-950">
    <div id="top-progress" aria-hidden="true"></div>
    <div id="mito-splash" class="fixed inset-0 z-[1000000] flex items-center justify-center bg-white dark:bg-black" aria-hidden="true">
        <div class="animate-scale-in flex flex-col items-center gap-4">
            <x-mito-loader size="xl" />
            <p class="text-theme-sm font-semibold tracking-widest text-gray-400 uppercase">Tracker-Asset</p>
        </div>
    </div>

    <div class="grid min-h-full lg:grid-cols-2">

        {{-- Brand panel. Hidden below lg so a phone gets the form without a wall
             of marketing first; on desktop it is the left half of the split. --}}
        <aside class="relative hidden overflow-hidden bg-gray-900 lg:flex lg:flex-col lg:justify-between lg:p-12">
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
                                <svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0L3.3 9.7a1 1 0 1 1 1.4-1.4l3.1 3.1 6.8-6.8a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/></svg>
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
            <div class="animate-fade-up w-full max-w-sm">

                {{-- Compact brand for mobile, where the panel above is hidden. --}}
                <div class="mb-8 text-center lg:hidden">
                    <img src="{{ asset('images/mito-icon.png') }}" alt="MITO Electronic" width="64" height="64"
                         class="mb-4 inline-block h-16 w-16 rounded-2xl shadow-theme-lg">
                    <h1 class="text-title-xs font-bold text-gray-800 dark:text-white">TRACKER-ASSET</h1>
                    <p class="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">IT Asset Management System</p>
                </div>

                <div class="mb-8 hidden lg:block">
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Sign in</h2>
                    <p class="mt-1.5 text-theme-sm text-gray-500 dark:text-gray-400">Welcome back. Enter your details to continue.</p>
                </div>

                @if ($errors->any())
                    <div class="mb-5 flex items-center gap-3 rounded-xl border border-error-200 bg-error-50 px-4 py-3 text-theme-sm font-medium text-error-700 dark:border-error-500/20 dark:bg-error-500/10 dark:text-error-300">
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path fill-rule="evenodd" clip-rule="evenodd" d="M10 2C5.58 2 2 5.58 2 10s3.58 8 8 8 8-3.58 8-8-3.58-8-8-8zm-.75 4.75a.75.75 0 011.5 0v3.5a.75.75 0 01-1.5 0v-3.5zm.75 7a1 1 0 100-2 1 1 0 000 2z" fill="currentColor"/></svg>
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="/login" class="space-y-5">
                    @csrf
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Email address</label>
                        <input type="email" name="email" value="{{ old('email') }}" required autofocus
                               class="w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-theme-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 focus:outline-none dark:border-gray-700 dark:text-white dark:placeholder:text-gray-500"
                               placeholder="you@company.com">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Password</label>
                        <input type="password" name="password" required
                               class="w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-theme-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 focus:outline-none dark:border-gray-700 dark:text-white dark:placeholder:text-gray-500"
                               placeholder="Enter your password">
                    </div>
                    <button type="submit"
                            class="w-full rounded-lg bg-brand-500 py-2.5 text-theme-sm font-medium text-white shadow-theme-xs transition-all duration-200 hover:bg-brand-600 active:scale-[0.99] disabled:opacity-80">
                        Sign in
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