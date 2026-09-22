@extends('layouts.app')

@section('title', 'Transfer Asset')

@section('content')
<h1 class="text-2xl font-bold mb-6">Transfer Asset {{ $asset->asset_id }}</h1>

<div class="bg-white p-6 rounded shadow mb-6">
    <h2 class="text-lg font-semibold mb-4">Current Assignment</h2>
    <dl class="grid grid-cols-2 gap-2">
        <dt class="font-medium">Current User</dt><dd>{{ $asset->currentUser?->name }}</dd>
        <dt class="font-medium">Current Location</dt><dd>{{ $asset->location?->name }}</dd>
        <dt class="font-medium">Company</dt><dd>{{ $asset->company?->name }}</dd>
    </dl>
</div>

<form method="POST" action="/assets/{{ $asset->id }}/transfer" class="bg-white p-6 rounded shadow space-y-4">
    @csrf

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium">Transfer Date *</label>
            <input type="date" name="transfer_date" required value="{{ old('transfer_date', now()->toDateString()) }}" class="w-full border rounded px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium">Condition</label>
            <input type="text" name="condition" value="{{ old('condition') }}" class="w-full border rounded px-3 py-2">
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium">From Location *</label>
            <select name="from_location_id" required class="w-full border rounded px-3 py-2">
                <option value="">Select</option>
                @foreach($locations as $location)
                <option value="{{ $location->id }}" {{ $asset->location_id == $location->id ? 'selected' : '' }}>{{ $location->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium">To Location *</label>
            <select name="to_location_id" required class="w-full border rounded px-3 py-2">
                <option value="">Select</option>
                @foreach($locations as $location)
                <option value="{{ $location->id }}">{{ $location->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium">To User *</label>
            <select name="to_user_id" required class="w-full border rounded px-3 py-2">
                <option value="">Select</option>
                @foreach($users as $user)
                <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium">To Department</label>
            <input type="text" name="to_department" value="{{ old('to_department') }}" class="w-full border rounded px-3 py-2">
        </div>
    </div>

    <div>
        <label class="block text-sm font-medium">Accessories</label>
        <textarea name="accessories" class="w-full border rounded px-3 py-2">{{ old('accessories') }}</textarea>
    </div>

    <div>
        <label class="block text-sm font-medium">Notes</label>
        <textarea name="notes" class="w-full border rounded px-3 py-2">{{ old('notes') }}</textarea>
    </div>

    <p class="text-sm text-gray-500">Submitted by: {{ Auth::user()?->name }}</p>

    <div class="flex gap-2 pt-4">
        <button type="submit" class="bg-green-600 text-white px-6 py-2 rounded">Submit Transfer</button>
        <a href="/assets/{{ $asset->id }}" class="bg-gray-300 text-gray-700 px-6 py-2 rounded">Cancel</a>
    </div>
</form>
@endsection
