<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'TRACKER-ASSET')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-gray-100">
    <nav class="bg-white shadow">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <a href="/" class="text-xl font-bold text-blue-600">TRACKER-ASSET</a>
                </div>
<div class="flex items-center gap-4">
                        <a href="{{ route('dashboard') }}" class="text-gray-600 hover:text-gray-900">Dashboard</a>
                        <a href="{{ route('assets.index') }}" class="text-gray-600 hover:text-gray-900">Assets</a>
                        @can('import-assets')
                            <a href="{{ route('assets.import') }}" class="text-gray-600 hover:text-gray-900">Import</a>
                        @endcan
                        @can('manage-master-data')
                            <div class="relative">
                                <button onclick="document.getElementById('master-menu').classList.toggle('hidden')" class="text-gray-600 hover:text-gray-900">Master Data ▾</button>
                                <div id="master-menu" class="hidden absolute right-0 mt-2 w-48 bg-white rounded shadow-lg border z-10">
                                    <a href="{{ route('master.index', 'companies') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Companies</a>
                                    <a href="{{ route('master.index', 'locations') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Locations</a>
                                    <a href="{{ route('master.index', 'categories') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Categories</a>
                                    <a href="{{ route('master.index', 'statuses') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Statuses</a>
                                    @can('manage-users')
                                        <a href="{{ route('users.index') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 border-t">Users</a>
                                    @endcan
                                    <a href="{{ route('templates.index') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Templates</a>
                                </div>
                            </div>
                        @endcan
                        @can('view-activity-log')
                            <a href="{{ route('activity.index') }}" class="text-gray-600 hover:text-gray-900">Activity</a>
                        @endcan
                        @can('export-assets')
                            <a href="{{ route('reports.index') }}" class="text-gray-600 hover:text-gray-900">Reports</a>
                        @endcan
                        @auth
                            <span class="text-sm text-gray-500">
                                {{ Auth::user()?->name }}
                                <span class="text-xs px-1 rounded bg-gray-200">{{ Auth::user()?->role }}</span>
                            </span>
                        @endauth
                        <form method="POST" action="{{ route('logout') }}" class="inline">
                            @csrf
                            <button type="submit" class="text-gray-600 hover:text-gray-900">Logout</button>
                        </form>
                    </div>
            </div>
        </div>
    </nav>
    <main class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
        @if (session('success'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                {{ session('success') }}
            </div>
        @endif
        @if (session('error'))
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                {{ session('error') }}
            </div>
        @endif
        @if (session('warning'))
            <div class="bg-yellow-100 border border-yellow-400 text-yellow-800 px-4 py-3 rounded mb-4">
                {{ session('warning') }}
            </div>
        @endif
        @yield('content')
    </main>
    @livewireScripts
</body>
</html>
