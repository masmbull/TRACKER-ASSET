@extends('layouts.app')

@section('title', $user ? 'Edit User' : 'New User')

@section('content')
<h1 class="text-2xl font-bold mb-6">{{ $user ? 'Edit' : 'New' }} User</h1>

<form method="POST" action="{{ $user ? route('users.update', $user) : route('users.store') }}" class="bg-white p-6 rounded shadow space-y-4">
    @csrf
    @if($user)
        @method('PUT')
    @endif

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium">Name *</label>
            <input type="text" name="name" required value="{{ old('name', $user?->name) }}" class="w-full border rounded px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium">Email *</label>
            <input type="email" name="email" required value="{{ old('email', $user?->email) }}" class="w-full border rounded px-3 py-2">
        </div>
    	<div>
            <label class="block text-sm font-medium">Role {{ auth()->user()->isAdmin() ? '*' : '(admin only)' }}</label>
            <select name="role" {{ auth()->user()->isAdmin() ? 'required' : 'disabled' }} class="w-full border rounded px-3 py-2">
                @foreach(\App\Models\User::ROLES as $role)
                    <option value="{{ $role }}" {{ (string) old('role', $user?->role ?? 'viewer') === $role ? 'selected' : '' }}>{{ ucfirst($role) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium">Department</label>
            <input type="text" name="department" value="{{ old('department', $user?->department) }}" class="w-full border rounded px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium">Job Title</label>
            <input type="text" name="job_title" value="{{ old('job_title', $user?->job_title) }}" class="w-full border rounded px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium">Company</label>
            <input type="text" name="company" value="{{ old('company', $user?->company) }}" class="w-full border rounded px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium">{{ $user ? 'New Password (leave blank to keep)' : 'Password * (min 8)' }}</label>
            <input type="password" name="password" {{ $user ? '' : 'required' }} class="w-full border rounded px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium">Confirm Password {{ $user ? '' : '*' }}</label>
            <input type="password" name="password_confirmation" {{ $user ? '' : 'required' }} class="w-full border rounded px-3 py-2">
        </div>
    </div>

    @if($user)
        <label class="flex items-center gap-2 text-sm">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" {{ old('is_active', $user->is_active) ? 'checked' : '' }}>
            Active (inactive users are blocked from the system)
        </label>
    @else
        <input type="hidden" name="is_active" value="1">
    @endif

    @error('password')
        <p class="text-red-600 text-sm">{{ $message }}</p>
    @enderror

    <div class="flex gap-2 pt-4">
        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded">{{ $user ? 'Update' : 'Create' }}</button>
        <a href="{{ route('users.index') }}" class="bg-gray-300 text-gray-700 px-6 py-2 rounded">Cancel</a>
    </div>
</form>
@endsection
