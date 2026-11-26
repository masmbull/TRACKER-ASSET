            @if($selectedUser)
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 bg-gray-50/70 p-5 dark:border-gray-800 dark:bg-gray-800/60">
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
                                <x-icon name="info" class="mt-0.5 h-[18px] w-[18px] shrink-0" />
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
                        <div class="flex items-center gap-3 border-t border-gray-200 bg-gray-50/70 px-5 py-4 dark:border-gray-800 dark:bg-gray-800/60">
                            <p class="text-theme-xs font-medium text-warning-600 dark:text-warning-500" x-show="dirty" x-cloak>Unsaved changes</p>
                            <button type="submit" class="ml-auto inline-flex items-center gap-2 rounded-lg bg-brand-500 px-5 py-2.5 text-theme-sm font-semibold text-white shadow-theme-xs transition hover:bg-brand-600 focus:ring-brand-500/40 focus:ring-3 focus:outline-none">
                                <x-icon name="save" class="h-4 w-4" />
                                Save access
                            </button>
                        </div>
                    </form>
                @endif
            @else
                <div class="p-6 text-theme-sm text-gray-500 dark:text-gray-400">Choose a user to configure their access.</div>
            @endif
