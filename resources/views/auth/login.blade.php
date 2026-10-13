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
<body class="h-full bg-gray-50 font-outfit">
    <div class="flex min-h-full items-center justify-center p-4" x-data="{ loaded: false }" x-init="setTimeout(() => loaded = true, 100)">

        <div x-show="loaded" x-transition:enter="transition ease-out duration-500"
             x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             class="relative w-full max-w-md">

            {{-- Logo --}}
            <div class="text-center mb-8">
                <img src="{{ asset('images/mito-icon.png') }}" alt="MITO Electronic" width="64" height="64"
                     class="inline-block w-16 h-16 rounded-2xl shadow-theme-lg mb-4">
                <h1 class="text-title-xs font-bold text-gray-800">TRACKER-ASSET</h1>
                <p class="text-theme-sm text-gray-500 mt-1">IT Asset Management System</p>
            </div>

            {{-- Card --}}
            <div class="rounded-2xl border border-gray-200 bg-white p-8 shadow-theme-lg">
                <h2 class="text-lg font-semibold text-gray-800 mb-6">Sign in to your account</h2>

                @if ($errors->any())
                    <div class="mb-4 flex items-center gap-3 border border-error-200 bg-error-50 text-error-700 px-4 py-3 rounded-xl text-theme-sm font-medium">
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path fill-rule="evenodd" clip-rule="evenodd" d="M10 2C5.58 2 2 5.58 2 10s3.58 8 8 8 8-3.58 8-8-3.58-8-8-8zm-.75 4.75a.75.75 0 011.5 0v3.5a.75.75 0 01-1.5 0v-3.5zm.75 7a1 1 0 100-2 1 1 0 000 2z" fill="currentColor"/></svg>
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="/login" class="space-y-5">
                    @csrf
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700">Email address</label>
                        <input type="email" name="email" value="{{ old('email') }}" required
                               class="w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-theme-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:ring-brand-500/10 focus:ring-3 focus:outline-none"
                               placeholder="you@company.com">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-theme-sm font-medium text-gray-700">Password</label>
                        <input type="password" name="password" required
                               class="w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-theme-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:ring-brand-500/10 focus:ring-3 focus:outline-none"
                               placeholder="Enter your password">
                    </div>
                    <button type="submit"
                            class="w-full bg-brand-500 text-white hover:bg-brand-600 shadow-theme-xs font-medium py-2.5 rounded-lg transition text-theme-sm">
                        Sign in
                    </button>
                </form>

                @if($entraEnabled ?? false)
                    <div class="flex items-center gap-3 my-5">
                        <div class="flex-1 border-t border-gray-200"></div>
                        <span class="text-theme-xs text-gray-400 font-medium">or continue with</span>
                        <div class="flex-1 border-t border-gray-200"></div>
                    </div>
                    <a href="{{ route('auth.microsoft') }}"
                       class="w-full flex items-center justify-center gap-3 border border-gray-300 py-2.5 rounded-lg hover:bg-gray-50 transition text-theme-sm font-medium text-gray-700">
                        <svg width="18" height="18" viewBox="0 0 23 23"><rect width="11" height="11" fill="#f25022"/><rect x="12" width="11" height="11" fill="#7fba00"/><rect y="12" width="11" height="11" fill="#00a4ef"/><rect x="12" y="12" width="11" height="11" fill="#ffb900"/></svg>
                        Sign in with Microsoft
                    </a>
                @endif

                <p class="text-theme-xs text-gray-400 mt-6 text-center">
                    {{ ($entraEnabled ?? false) ? 'Microsoft Entra ID SSO enabled' : 'Local Development Mode' }}
                </p>
            </div>
        </div>
    </div>
</body>
</html>
