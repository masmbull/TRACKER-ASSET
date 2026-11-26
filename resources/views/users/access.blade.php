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
            <x-icon name="plus" class="h-4 w-4" />
            User directory
        </a>
    </div>

    @if(session('success'))
        <div class="mb-6 flex items-start gap-2.5 rounded-xl border border-success-200 bg-success-50 px-4 py-3 text-theme-sm text-success-700 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-300">
            <x-icon name="success" class="mt-0.5 h-[18px] w-[18px] shrink-0" />
            <span>{{ session('success') }}</span>
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[minmax(18rem,0.8fr)_minmax(0,1.9fr)]"
         data-panel-endpoint="{{ route('users.access.panel', ['user' => '__USER__']) }}"
         x-data='accessControl(@json($selectedUser?->id), @json(request('search', '')))'>
        <section class="flex flex-col self-start overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-800">
            <div class="border-b border-gray-200 p-4 dark:border-gray-800">
                {{-- Filtering is local: the list is already on the page and a GET
                     here would tear down the panel and lose the selection. --}}
                <div class="relative">
                    <span class="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2">
                        <x-icon name="search" class="h-[18px] w-[18px] shrink-0 text-gray-400" />
                    </span>
                    <input type="search" x-model="query" @keydown.enter.prevent value="{{ request('search') }}" placeholder="Search users..."
                           class="w-full rounded-lg border border-gray-300 bg-transparent py-2.5 pr-3 pl-10 text-theme-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:ring-brand-500/10 focus:ring-3 focus:outline-none dark:border-gray-700 dark:text-white/90 dark:placeholder:text-white/30">
                </div>
            </div>
            {{-- The list used to be a fixed-height scroll box with a Prev/Next
                 footer, so a 40-person directory was two nested scrollers (the box
                 and the page) and the count was invisible. It now flows at its
                 natural height with a numbered pager and a per-page selector, the
                 same shape as every other list in the app. --}}
            <div class="divide-y divide-gray-100 dark:divide-gray-800" x-ref="list">
                @forelse($users as $user)
                    @php
                        $isSelected = $selectedUser?->is($user);
                        $initials = \Illuminate\Support\Str::of($user->name)->trim()->explode(' ')->filter()->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('');
                    @endphp
                    <button type="button" data-user-row data-user-id="{{ $user->id }}" data-user-search="{{ mb_strtolower($user->name.' '.$user->email) }}"
                            x-show="$data.matches($el.dataset.userSearch)"
                            @click="$data.select({{ $user->id }})"
                            @if($isSelected) aria-current="true" @endif
                            class="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors {{ $isSelected ? 'bg-brand-50/70 shadow-[inset_3px_0_0_0_theme(colors.brand.500)] dark:bg-brand-500/10' : 'hover:bg-gray-50 dark:hover:bg-white/5' }}">
                        <span data-user-avatar class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-theme-xs font-semibold uppercase {{ $isSelected ? 'bg-brand-500 text-white' : 'bg-gray-100 text-gray-600 dark:bg-gray-700/50 dark:text-gray-300' }}">{{ $initials }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-theme-sm font-semibold text-gray-800 dark:text-white">{{ $user->name }}</span>
                            <span class="block truncate text-theme-xs text-gray-500 dark:text-gray-400">{{ $user->email }}</span>
                        </span>
                        <x-badge :variant="$isSelected ? 'brand' : 'gray'" class="capitalize" data-user-badge>{{ $user->role }}</x-badge>
                    </button>
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
        <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-800" x-ref="panel">
            @include('users._access-panel', ['selectedUser' => $selectedUser, 'permissionGroups' => $permissionGroups])
        </section>
    </div>
@endsection

@push('scripts')
<script>
    // Selecting a user fetches only the permission panel. The page, the filtered
    // list and the scroll position stay put; the URL is updated without a load.
    // A global factory, registered without waiting for alpine:init: the page's
    // inline script runs before the Vite module starts Alpine, so an event
    // listener here can miss the one event that registers the component.
    window.accessControl = function (selected, query) {
        return {
            selected,
            query,

            matches(haystack) {
                const term = this.query.trim().toLowerCase();

                return term === '' || haystack.includes(term);
            },

            async select(id) {
                if (id === this.selected) {
                    return;
                }

                try {
                    const endpoint = this.$el.dataset.panelEndpoint.replace('__USER__', String(id));
                    const response = await fetch(endpoint, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
                        credentials: 'same-origin',
                    });

                    if (! response.ok) {
                        throw new Error('Could not load that user.');
                    }

                    const panel = this.$refs.panel;
                    panel.innerHTML = await response.text();
                    // The panel carries its own Alpine nodes (the dirty form).
                    Alpine.initTree(panel);

                    this.selected = id;
                    this.markSelected(id);

                    const url = new URL(window.location.href);
                    url.searchParams.set('user', String(id));
                    window.history.replaceState(null, '', url);
                } catch (error) {
                    window.showToast?.(error.message || 'Could not load that user.', 'error');
                }
            },

            // The list is server-rendered, so its selected styling is updated in
            // place rather than re-rendering the whole page.
            markSelected(id) {
                const selectedRow = ['bg-brand-50/70', 'shadow-[inset_3px_0_0_0_theme(colors.brand.500)]', 'dark:bg-brand-500/10'];
                const idleRow = ['hover:bg-gray-50', 'dark:hover:bg-white/5'];
                const selectedAvatar = ['bg-brand-500', 'text-white'];
                const idleAvatar = ['bg-gray-100', 'text-gray-600', 'dark:bg-gray-700/50', 'dark:text-gray-300'];
                const selectedBadge = ['bg-brand-50', 'text-brand-500', 'dark:bg-brand-500/15', 'dark:text-brand-400'];
                const idleBadge = ['bg-gray-100', 'text-gray-600', 'dark:bg-gray-700/50', 'dark:text-gray-300'];

                this.$refs.list.querySelectorAll('[data-user-row]').forEach((row) => {
                    const isCurrent = Number(row.dataset.userId) === id;

                    if (isCurrent) {
                        row.setAttribute('aria-current', 'true');
                    } else {
                        row.removeAttribute('aria-current');
                    }

                    selectedRow.forEach((name) => row.classList.toggle(name, isCurrent));
                    idleRow.forEach((name) => row.classList.toggle(name, ! isCurrent));

                    const avatar = row.querySelector('[data-user-avatar]');
                    selectedAvatar.forEach((name) => avatar.classList.toggle(name, isCurrent));
                    idleAvatar.forEach((name) => avatar.classList.toggle(name, ! isCurrent));

                    const badge = row.querySelector('[data-user-badge]');
                    selectedBadge.forEach((name) => badge.classList.toggle(name, isCurrent));
                    idleBadge.forEach((name) => badge.classList.toggle(name, ! isCurrent));
                });
            },
        };
    };
</script>
@endpush
