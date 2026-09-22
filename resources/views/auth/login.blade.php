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

        <p class="text-sm text-gray-400 mt-6 text-center">Local Development Mode</p>
    </div>
</div>
@endsection
