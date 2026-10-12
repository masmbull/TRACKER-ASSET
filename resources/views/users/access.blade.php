@extends('layouts.app')

@section('title', 'Access Control')

@section('content')
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-theme-sm font-medium text-brand-600">Administration</p>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Access Control</h1>
            <p class="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">Set exceptions for an individual user without changing their base role.</p>
        </div>
        <a href="{{ route('users.index') }}" class="inline-flex items-center justify-center rounded-lg border border-gray-200 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800">User directory</a>
    </div>

    @if(session('success'))
        <div class="mb-6 rounded-lg border border-success-200 bg-success-50 px-4 py-3 text-theme-sm text-success-700 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-300">{{ session('success') }}</div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[minmax(18rem,0.75fr)_minmax(0,1.75fr)]">
        <section class="rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <div class="border-b border-gray-200 p-4 dark:border-gray-800">
                <form method="GET" action="{{ route('users.access') }}" class="flex gap-2">
                    @if($selectedUser)
                        <input type="hidden" name="user" value="{{ $selectedUser->id }}">
                    @endif
                    <input type="search" name="search" value="{{ request('search') }}" placeholder="Search users..." class="min-w-0 flex-1 rounded-lg border-gray-300 text-theme-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                    <button type="submit" class="rounded-lg bg-gray-900 px-3 text-theme-sm font-medium text-white hover:bg-gray-800 dark:bg-white dark:text-gray-900">Search</button>
                </form>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse($users as $user)
                    <a href="{{ route('users.access', array_filter(['user' => $user->id, 'search' => request('search')])) }}"
                       class="block px-4 py-3 transition-colors hover:bg-gray-50 dark:hover:bg-gray-800/70 {{ $selectedUser?->is($user) ? 'border-l-4 border-brand-500 bg-brand-50/60 pl-3 dark:bg-brand-500/10' : '' }}">
                        <div class="flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-theme-sm font-semibold text-gray-800 dark:text-white">{{ $user->name }}</p>
                                <p class="truncate text-theme-xs text-gray-500 dark:text-gray-400">{{ $user->email }}</p>
                            </div>
                            <span class="rounded-full bg-gray-100 px-2 py-1 text-[11px] font-semibold capitalize text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ $user->role }}</span>
                        </div>
                    </a>
                @empty
                    <p class="p-5 text-theme-sm text-gray-500 dark:text-gray-400">No users found.</p>
                @endforelse
            </div>
            @if($users->hasPages())
                <div class="border-t border-gray-200 px-4 py-3 dark:border-gray-800">{{ $users->links() }}</div>
            @endif
        </section>

        <section class="rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            @if($selectedUser)
                <div class="border-b border-gray-200 p-5 dark:border-gray-800">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-bold text-gray-900 dark:text-white">{{ $selectedUser->name }}</h2>
                            <p class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $selectedUser->email }} · Base role: <span class="font-semibold capitalize">{{ $selectedUser->role }}</span></p>
                        </div>
                        @if($selectedUser->isAdmin())
                            <span class="rounded-full bg-brand-50 px-3 py-1.5 text-theme-xs font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300">Full administrator access</span>
                        @endif
                    </div>
                </div>

                @if($selectedUser->isAdmin())
                    <div class="p-5 text-theme-sm text-gray-600 dark:text-gray-300">Administrator access cannot be restricted from this screen. Change the user role first if their access needs to be limited.</div>
                @else
                    <form method="POST" action="{{ route('users.access.update', $selectedUser) }}">
                        @csrf
                        @method('PUT')
                        <div class="space-y-6 p-5">
                            <div class="rounded-lg border border-blue-light-200 bg-blue-light-50 px-4 py-3 text-theme-sm text-blue-light-800 dark:border-blue-light-500/30 dark:bg-blue-light-500/10 dark:text-blue-light-200">
                                <strong>Inherit</strong> uses the base role. <strong>Allow</strong> grants an exception; <strong>Deny</strong> blocks it, even when the role normally allows it.
                            </div>

                            @foreach($permissionGroups as $group => $permissions)
                                <div>
                                    <h3 class="mb-3 text-theme-sm font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $group }}</h3>
                                    <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
                                        @foreach($permissions as $permission => $label)
                                            @php $override = $selectedUser->permissionOverride($permission); @endphp
                                            <div class="flex flex-col gap-3 border-b border-gray-100 px-4 py-3 last:border-b-0 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
                                                <label for="permission-{{ str_replace('.', '-', $permission) }}" class="text-theme-sm font-medium text-gray-800 dark:text-gray-100">{{ $label }}</label>
                                                <select id="permission-{{ str_replace('.', '-', $permission) }}" name="permissions[{{ $permission }}]" class="rounded-lg border-gray-300 py-2 text-theme-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                                                    <option value="inherit" @selected($override === null)>Inherit role</option>
                                                    <option value="allow" @selected($override === true)>Allow</option>
                                                    <option value="deny" @selected($override === false)>Deny</option>
                                                </select>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="flex justify-end border-t border-gray-200 p-5 dark:border-gray-800">
                            <button type="submit" class="rounded-lg bg-brand-600 px-5 py-2.5 text-theme-sm font-semibold text-white hover:bg-brand-700">Save access</button>
                        </div>
                    </form>
                @endif
            @else
                <div class="p-6 text-theme-sm text-gray-500 dark:text-gray-400">Choose a user to configure their access.</div>
            @endif
        </section>
    </div>
@endsection
