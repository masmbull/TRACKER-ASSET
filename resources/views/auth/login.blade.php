@extends('layouts.app')

@section('title', 'Login')

@section('content')
<div class="flex justify-center items-center min-h-[60vh]">
    <div class="bg-white p-8 rounded shadow w-full max-w-md">
        <h1 class="text-4xl font-bold text-center text-blue-600 mb-2">TRACKER-ASSET</h1>
        <p class="text-gray-500 text-center mb-6">Web-based Asset Tracker + IT Audit + FAT Generator</p>
        <h2 class="text-lg font-semibold text-center mb-4">Login</h2>

        @if ($errors->any())
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4 text-sm">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="/login" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-medium">Email</label>
                <input type="email" name="email" value="{{ old('email') }}" required class="w-full border rounded px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium">Password</label>
                <input type="password" name="password" required class="w-full border rounded px-3 py-2">
            </div>
            <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded">Login</button>
        </form>

        @if($entraEnabled ?? false)
            <div class="flex items-center gap-3 my-4">
                <div class="flex-1 border-t"></div>
                <span class="text-xs text-gray-400">or</span>
                <div class="flex-1 border-t"></div>
            </div>
            <a href="{{ route('auth.microsoft') }}" class="w-full flex items-center justify-center gap-2 border py-2 rounded hover:bg-gray-50">
                <svg width="18" height="18" viewBox="0 0 23 23"><rect width="11" height="11" fill="#f25022"/><rect x="12" width="11" height="11" fill="#7fba00"/><rect y="12" width="11" height="11" fill="#00a4ef"/><rect x="12" y="12" width="11" height="11" fill="#ffb900"/></svg>
                Sign in with Microsoft
            </a>
        @endif

        <p class="text-sm text-gray-400 mt-6 text-center">{{ ($entraEnabled ?? false) ? 'Microsoft Entra ID SSO enabled' : 'Local Development Mode' }}</p>
    </div>
</div>
@endsection
