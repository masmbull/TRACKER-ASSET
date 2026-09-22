@extends('layouts.app')

@section('title', 'Users')

@section('content')
<div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-bold">Users</h1>
    <a href="{{ route('users.create') }}" class="bg-blue-600 text-white px-4 py-2 rounded">+ Add User</a>
</div>

<form method="GET" action="{{ route('users.index') }}" class="flex gap-2 mb-4">
    <input type="text" name="search" value="{{ request('search') }}" placeholder="Name, email, department..." class="border rounded px-3 py-2 flex-1">
    <button type="submit" class="bg-gray-600 text-white px-4 py-2 rounded">Search</button>
</form>

@if($users->count() > 0)
<table class="min-w-full bg-white shadow rounded text-sm">
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
        <tr class="border-b hover:bg-gray-50">
            <td class="py-3 px-4">{{ $user->name }}</td>
            <td class="py-3 px-4">{{ $user->email }}</td>
            <td class="py-3 px-4"><span class="px-2 py-1 text-xs rounded bg-gray-200">{{ $user->role }}</span></td>
            <td class="py-3 px-4">{{ $user->department }}</td>
            <td class="py-3 px-4">
                <span class="px-2 py-1 text-xs rounded {{ $user->is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-700' }}">
                    {{ $user->is_active ? 'Active' : 'Inactive' }}
                </span>
            </td>
            <td class="py-3 px-4"><a href="{{ route('users.edit', $user) }}" class="text-yellow-600">Edit</a></td>
        </tr>
        @endforeach
    </tbody>
</table>

{{ $users->links() }}
@else
<p class="bg-white p-6 rounded shadow">No users found.</p>
@endif
@endsection
