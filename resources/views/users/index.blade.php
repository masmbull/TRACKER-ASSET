@extends('layouts.app')

@section('title', 'Users')

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <h1 class="text-2xl font-bold">Users</h1>
    <a href="{{ route('users.create') }}" class="inline-flex items-center justify-center rounded-lg bg-brand-500 px-4 py-2 text-theme-sm font-medium text-white shadow-theme-xs transition-all duration-200 active:scale-[0.97] hover:bg-brand-600">+ Add User</a>
</div>

<form method="GET" action="{{ route('users.index') }}" class="flex flex-wrap items-center gap-2 mb-4">
    <input type="text" name="search" value="{{ request('search') }}" placeholder="Name, email, department..." class="border rounded-lg px-3 py-2 flex-1 dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:placeholder-gray-400">
    <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-gray-600 px-4 py-2 text-theme-sm font-medium text-white transition-all duration-200 active:scale-[0.97] hover:bg-gray-700 dark:bg-gray-600">Search</button>
</form>

{{-- Employee directory search (PRD §39). Hidden entirely when the feature is
     off, rather than shown and failing: it needs a tenant permission that most
     installations will not have granted, and a search box that 403s on every
     keystroke reads as a broken page. --}}
@if(config('services.entra.directory') && config('services.entra.enabled'))
<div id="m365-directory" class="mb-4">
    <label for="m365-q" class="block text-sm font-medium mb-1">Find in Microsoft 365</label>
    <input id="m365-q" type="text" autocomplete="off" placeholder="Search name, email, department, job title, company..."
           class="border rounded-lg px-3 py-2 w-full max-w-xl dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:placeholder-gray-400">
    <p id="m365-status" class="text-sm text-gray-600 dark:text-gray-300 mt-2"></p>
    <ul id="m365-results" class="mt-2 divide-y border rounded max-w-xl"></ul>
</div>

<script>
(function () {
    const input = document.getElementById('m365-q');
    const status = document.getElementById('m365-status');
    const list = document.getElementById('m365-results');
    const url = @json(route('users.directory'));
    let timer = null;
    let controller = null;

    function render(results) {
        list.innerHTML = '';
        results.forEach(function (person) {
            const row = document.createElement('li');
            row.className = 'py-2 text-sm';
            row.textContent = person.name + ' — ' + person.email;
            const detail = document.createElement('div');
            detail.className = 'text-xs text-gray-500 dark:text-gray-400';
            // Only fields PRD §39 asks for, and only the ones present.
            detail.textContent = [person.job_title, person.department, person.company]
                .filter(Boolean).join(' · ');
            row.appendChild(detail);
            list.appendChild(row);
        });
    }

    input.addEventListener('input', function () {
        const q = input.value.trim();

        // Debounced: one request per pause in typing, not one per keystroke.
        clearTimeout(timer);
        controller?.abort();

        if (q.length < 2) {
            status.textContent = '';
            list.innerHTML = '';
            return;
        }

        timer = setTimeout(function () {
            controller = new AbortController();
            fetch(url + '?q=' + encodeURIComponent(q), { signal: controller.signal, headers: { Accept: 'application/json' } })
                .then(function (r) { return r.json().then(function (body) { return { ok: r.ok, body: body }; }); })
                .then(function (r) {
                    if (!r.ok && r.body.error) {
                        status.textContent = r.body.error;
                        list.innerHTML = '';
                        return;
                    }
                    status.textContent = r.body.results.length
                        ? r.body.results.length + ' found'
                        : 'No match in Microsoft 365.';
                    render(r.body.results);
                })
                .catch(function (e) { if (e.name !== 'AbortError') { status.textContent = 'Search failed. Try again.'; } });
        }, 300);
    });
})();
</script>
@endif

@if($users->count() > 0)
<table class="min-w-full bg-white shadow rounded text-sm dark:bg-[#1d2939]">
    <thead>
        <tr class="border-b">
            <th class="text-left py-3 px-4">Name</th>
            <th class="text-left py-3 px-4">Email</th>
            <th class="text-left py-3 px-4">Role</th>
            <th class="text-left py-3 px-4">Department</th>
            <th class="text-left py-3 px-4">Status</th>
            <th class="text-left py-3 px-4">Actions</th>
        </tr>
    </thead>
    <tbody>
        @foreach($users as $user)
        <tr class="border-b hover:bg-gray-50 dark:hover:bg-gray-800">
            <td class="py-3 px-4">{{ $user->name }}</td>
            <td class="py-3 px-4">{{ $user->email }}</td>
            <td class="py-3 px-4"><span class="px-2 py-1 text-xs rounded bg-gray-200 dark:bg-gray-700">{{ $user->role }}</span></td>
            <td class="py-3 px-4">{{ $user->department }}</td>
            <td class="py-3 px-4">
                <span class="px-2 py-1 text-xs rounded {{ $user->is_active ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300' : 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300' }}">
                    {{ $user->is_active ? 'Active' : 'Inactive' }}
                </span>
            </td>
            <td class="py-3 px-4"><a href="{{ route('users.edit', $user) }}" class="text-red-600 hover:text-red-700 dark:text-red-400">Edit</a></td>
        </tr>
        @endforeach
    </tbody>
</table>

{{ $users->links() }}
@else
<p class="bg-white p-6 rounded-xl shadow dark:bg-[#1d2939]">No users found.</p>
@endif
@endsection
