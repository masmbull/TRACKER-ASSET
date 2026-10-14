@extends('layouts.app')

@section('title', 'Access Control')

@section('content')
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <p class="text-theme-sm font-medium text-brand-600">Administration</p>
            <h1 class="text-title-xs font-bold text-gray-800 dark:text-white">Access Control</h1>
            <p class="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">Set exceptions for an individual user without changing their base role.</p>
        </div>
        <a href="{{ route('users.index') }}" class="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-theme-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800">
            <svg fill="none" width="16" height="16" viewBox="0 0 20 20" aria-hidden="true"><path d="M10 4.5v11M4.5 10h11" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            User directory
        </a>
    </div>

    @if(session('success'))
        <div class="mb-6 flex items-start gap-2.5 rounded-xl border border-success-200 bg-success-50 px-4 py-3 text-theme-sm text-success-700 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-300">
            <svg class="mt-0.5 shrink-0" fill="none" width="18" height="18" viewBox="0 0 20 20" aria-hidden="true"><path d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm-3.2-8.3 2 2 4.4-4.4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[minmax(18rem,0.8fr)_minmax(0,1.9fr)]">
        <section class="flex flex-col self-start overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/3">
            <div class="border-b border-gray-200 p-4 dark:border-gray-800">
                <form method="GET" action="{{ route('users.access') }}" class="relative">
                    @if($selectedUser)
                        <input type="hidden" name="user" value="{{ $selectedUser->id }}">
                    @endif
                    <span class="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2">
                        <svg class="fill-gray-400" width="18" height="18" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M3.04 9.37a6.33 6.33 0 1 1 11.32 3.95l2.82 2.82a.75.75 0 1 1-1.06 1.06l-2.82-2.82A6.33 6.33 0 0 1 3.04 9.37Zm6.33-4.83a4.83 4.83 0 1 0 0 9.66 4.83 4.83 0 0 0 0-9.66Z"/></svg>
                    </span>
                    <input type="search" name="search" value="{{ request('search') }}" placeholder="Search users..."
                           class="w-full rounded-lg border border-gray-300 bg-transparent py-2.5 pr-3 pl-10 text-theme-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:ring-brand-500/10 focus:ring-3 focus:outline-none dark:border-gray-700 dark:text-white/90 dark:placeholder:text-white/30">
                </form>
            </div>
            {{-- The list used to be a fixed-height scroll box with a Prev/Next
                 footer, so a 40-person directory was two nested scrollers (the box
                 and the page) and the count was invisible. It now flows at its
                 natural height with a numbered pager and a per-page selector, the
                 same shape as every other list in the app. --}}
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse($users as $user)
                    @php
                        $isSelected = $selectedUser?->is($user);
                        $initials = \Illuminate\Support\Str::of($user->name)->trim()->explode(' ')->filter()->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('');
                    @endphp
                    <a href="{{ route('users.access', array_filter(['user' => $user->id, 'search' => request('search'), 'per_page' => request('per_page')])) }}"
                       @if($isSelected) aria-current="true" @endif
                       class="flex items-center gap-3 px-4 py-3 transition-colors {{ $isSelected ? 'bg-brand-50/70 shadow-[inset_3px_0_0_0_theme(colors.brand.500)] dark:bg-brand-500/10' : 'hover:bg-gray-50 dark:hover:bg-white/5' }}">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-theme-xs font-semibold uppercase {{ $isSelected ? 'bg-brand-500 text-white' : 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300' }}">{{ $initials }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-theme-sm font-semibold text-gray-800 dark:text-white">{{ $user->name }}</span>
                            <span class="block truncate text-theme-xs text-gray-500 dark:text-gray-400">{{ $user->email }}</span>
                        </span>
                        <x-badge :variant="$isSelected ? 'brand' : 'gray'" class="capitalize">{{ $user->role }}</x-badge>
                    </a>
                @empty
                    <p class="p-5 text-theme-sm text-gray-500 dark:text-gray-400">No users found.</p>
                @endforelse
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 px-4 py-3 text-theme-xs dark:border-gray-800">
                <form method="GET" action="{{ route('users.access') }}" class="flex items-center gap-2">
                    @if($selectedUser)
                        <input type="hidden" name="user" value="{{ $selectedUser->id }}">
                    @endif
                    @if(request('search'))
                        <input type="hidden" name="search" value="{{ request('search') }}">
                    @endif
                    <label for="per_page" class="text-gray-500 dark:text-gray-400">Show</label>
                    <select id="per_page" name="per_page" onchange="this.form.submit()"
                            class="min-w-[4.5rem] rounded-lg border border-gray-300 bg-white py-1 pl-3 pr-8 text-gray-700 focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
                        @foreach([12, 25, 50, 100] as $option)
                            <option value="{{ $option }}" @selected($perPage === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                    <span class="text-gray-500 dark:text-gray-400">per page</span>
                </form>
                <p class="text-gray-500 dark:text-gray-400">
                    Showing {{ $users->firstItem() ?? 0 }} to {{ $users->lastItem() ?? 0 }} of {{ $users->total() }} users
                </p>
            </div>

            @if($users->hasPages())
                {{ $users->links('components.pagination') }}
            @endif
        </section>
        <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/3">
            @if($selectedUser)
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 bg-gray-50/70 p-5 dark:border-gray-800 dark:bg-white/3">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-brand-500 text-theme-sm font-bold uppercase text-white">{{ \Illuminate\Support\Str::of($selectedUser->name)->trim()->explode(' ')->filter()->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') }}</span>
                        <div class="min-w-0">
                            <h2 class="truncate text-lg font-bold text-gray-900 dark:text-white">{{ $selectedUser->name }}</h2>
                            <p class="truncate text-theme-sm text-gray-500 dark:text-gray-400">{{ $selectedUser->email }} &middot; Base role: <span class="font-semibold capitalize text-gray-700 dark:text-gray-300">{{ $selectedUser->role }}</span></p>
                        </div>
                    </div>
                    @if($selectedUser->isAdmin())
                        <x-badge variant="brand" :dot="true">Full administrator access</x-badge>
                    @else
                        <x-badge :variant="$selectedUser->is_active ? 'success' : 'gray'" :dot="true">{{ $selectedUser->is_active ? 'Active' : 'Inactive' }}</x-badge>
                    @endif
                </div>

                @if($selectedUser->isAdmin())
                    <div class="p-6 text-theme-sm text-gray-600 dark:text-gray-300">Administrator access cannot be restricted from this screen. Change the user role first if their access needs to be limited.</div>
                @else
                    <form method="POST" action="{{ route('users.access.update', $selectedUser) }}" x-data="{ dirty: false }" @input="dirty = true" @change="dirty = true">
                        @csrf
                        @method('PUT')
                        <div class="space-y-6 p-5">
                            <div class="flex gap-3 rounded-xl border border-blue-light-200 bg-blue-light-50 px-4 py-3 text-theme-sm text-blue-light-800 dark:border-blue-light-500/30 dark:bg-blue-light-500/10 dark:text-blue-light-200">
                                <svg class="mt-0.5 shrink-0" fill="none" width="18" height="18" viewBox="0 0 20 20" aria-hidden="true"><path d="M10 13.4v-3.9M10 6.6h.01M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <p><strong>Inherit</strong> uses the base role. <strong>Allow</strong> grants an exception; <strong>Deny</strong> blocks it, even when the role normally allows it.</p>
                            </div>

                            @foreach($permissionGroups as $group => $permissions)
                                <div>
                                    <h3 class="mb-3 flex items-center gap-2 text-theme-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                        {{ $group }}
                                        <span class="h-px flex-1 bg-gray-200 dark:bg-gray-800"></span>
                                    </h3>
                                    <div class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                                        @foreach($permissions as $permission => $label)
                                            @php
                                                $override = $selectedUser->permissionOverride($permission);
                                                $state = $override === null ? 'inherit' : ($override ? 'allow' : 'deny');
                                                $name = 'permissions['.$permission.']';
                                            @endphp
                                            <div class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                                <span class="min-w-0 flex-1 text-theme-sm font-medium text-gray-800 dark:text-gray-100">{{ $label }}</span>
                                                <div class="inline-flex shrink-0 rounded-lg border border-gray-200 bg-gray-50 p-0.5 dark:border-gray-700 dark:bg-gray-800/60" role="radiogroup" aria-label="{{ $label }}">
                                                    @foreach(['inherit' => ['Inherit', 'hover:text-gray-800 dark:hover:text-gray-200', 'peer-checked:bg-white peer-checked:text-gray-800 peer-checked:shadow-theme-xs dark:peer-checked:bg-gray-700 dark:peer-checked:text-white'], 'allow' => ['Allow', 'hover:text-success-700 dark:hover:text-success-400', 'peer-checked:bg-success-500 peer-checked:text-white'], 'deny' => ['Deny', 'hover:text-error-700 dark:hover:text-error-400', 'peer-checked:bg-error-500 peer-checked:text-white']] as $value => [$text, $idle, $active])
                                                        <label class="cursor-pointer">
                                                            <input type="radio" name="{{ $name }}" value="{{ $value }}" @checked($state === $value) class="peer sr-only">
                                                            <span class="inline-flex h-8 min-w-[4.5rem] items-center justify-center rounded-md px-3 text-theme-xs font-semibold text-gray-500 transition dark:text-gray-400 {{ $idle }} {{ $active }}">{{ $text }}</span>
                                                        </label>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="flex items-center gap-3 border-t border-gray-200 bg-gray-50/70 px-5 py-4 dark:border-gray-800 dark:bg-white/3">
                            <p class="text-theme-xs font-medium text-warning-600 dark:text-warning-500" x-show="dirty" x-cloak>Unsaved changes</p>
                            <button type="submit" class="ml-auto inline-flex items-center gap-2 rounded-lg bg-brand-500 px-5 py-2.5 text-theme-sm font-semibold text-white shadow-theme-xs transition hover:bg-brand-600 focus:ring-brand-500/40 focus:ring-3 focus:outline-none">
                                <svg fill="none" width="16" height="16" viewBox="0 0 20 20" aria-hidden="true"><path d="M5 4h8l3 3v9a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1Zm2 0v4h5V4M7 13h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                Save access
                            </button>
                        </div>
                    </form>
                @endif
            @else
                <div class="p-6 text-theme-sm text-gray-500 dark:text-gray-400">Choose a user to configure their access.</div>
            @endif
        </section>
    </div>
@endsection