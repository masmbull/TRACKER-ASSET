@extends('layouts.app')

@section('title', $user ? 'Edit User' : 'New User')

@section('content')
<div class="mx-auto max-w-5xl space-y-6">
    <x-page-header :title="($user ? 'Edit' : 'New') . ' User'" :subtitle="$user ? 'Update profile, role and access details.' : 'Create a new system user with access and role information.'">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" :href="route('users.index')">Back to Users</x-button>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ $user ? route('users.update', $user) : route('users.store') }}" class="space-y-6">
        @csrf
        @if($user)
            @method('PUT')
        @endif

        <x-card title="User Details">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Name *</label>
                    <input type="text" name="name" required value="{{ old('name', $user?->name) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Email *</label>
                    <input type="email" name="email" required value="{{ old('email', $user?->email) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Role {{ auth()->user()->isAdmin() ? '*' : '(admin only)' }}</label>
                    <select name="role" {{ auth()->user()->isAdmin() ? 'required' : 'disabled' }} class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        @foreach(\App\Models\User::ROLES as $role)
                            <option value="{{ $role }}" {{ (string) old('role', $user?->role ?? 'viewer') === $role ? 'selected' : '' }}>{{ ucfirst($role) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Department</label>
                    <input type="text" name="department" value="{{ old('department', $user?->department) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Job Title</label>
                    <input type="text" name="job_title" value="{{ old('job_title', $user?->job_title) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Company</label>
                    <input type="text" name="company" value="{{ old('company', $user?->company) }}" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">{{ $user ? 'New Password (leave blank to keep)' : 'Password * (min 8)' }}</label>
                    <input type="password" name="password" {{ $user ? '' : 'required' }} class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Confirm Password {{ $user ? '' : '*' }}</label>
                    <input type="password" name="password_confirmation" {{ $user ? '' : 'required' }} class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
            </div>
        </x-card>

        <x-card title="Status">
            @if($user)
                <label class="flex items-center gap-3 text-theme-sm text-gray-700 dark:text-gray-300">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" {{ old('is_active', $user->is_active) ? 'checked' : '' }} class="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                    Active (inactive users are blocked from the system)
                </label>
            @else
                <input type="hidden" name="is_active" value="1">
            @endif

            @error('password')
                <p class="mt-3 text-theme-sm text-error-500">{{ $message }}</p>
            @enderror
        </x-card>

        <div class="flex justify-end gap-3">
            <a href="{{ route('users.index') }}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</a>
            <x-button type="submit" variant="primary">{{ $user ? 'Update User' : 'Create User' }}</x-button>
        </div>
    </form>
</div>
@endsection
