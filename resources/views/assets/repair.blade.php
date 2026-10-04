@extends('layouts.app')

@section('title', 'Add Repair')

@section('content')
<h1 class="text-2xl font-bold mb-6">Add Repair - {{ $asset->asset_id }}</h1>

<div class="bg-white p-6 rounded shadow mb-6">
    <h2 class="text-lg font-semibold mb-4">Asset Info</h2>
    <dl class="grid grid-cols-2 gap-2">
        <dt class="font-medium">Hostname</dt><dd>{{ $asset->hostname }}</dd>
        <dt class="font-medium">Serial</dt><dd>{{ $asset->serial_number }}</dd>
        <dt class="font-medium">Brand/Model</dt><dd>{{ $asset->brand }} {{ $asset->model }}</dd>
        <dt class="font-medium">Current User</dt><dd>{{ $asset->currentUser?->name }}</dd>
        <dt class="font-medium">Location</dt><dd>{{ $asset->location?->name }}</dd>
        <dt class="font-medium">Status</dt><dd><span class="px-2 py-1 text-xs rounded bg-gray-200">{{ $asset->status?->name }}</span></dd>
    </dl>
</div>

<form method="POST" action="/assets/{{ $asset->id }}/repair" class="bg-white p-6 rounded shadow space-y-4">
    @csrf

    {{-- The layout prints every validation error in one block above the form
         (app.blade.php, `@if($errors->any())`), so a rejected submit is never
         silent. What it cannot do is point at the offending input: a person
         looking at "The cost field must be a number" has to hunt for which
         "Cost" box that was. These per-field messages and the red border on the
         control itself close that gap - the summary says what is wrong, these
         say where. --}}
    @if($errors->any())
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <p class="font-semibold">Please fix the following before saving:</p>
            <ul class="mt-1 list-inside list-disc space-y-0.5">
                @foreach($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium">Repair Date *</label>
            <input type="date" name="date" required value="{{ old('date', now()->toDateString()) }}" class="w-full border rounded px-3 py-2 @error('date') border-red-400 @enderror">
            @error('date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-medium">Status</label>
            <select name="status" class="w-full border rounded px-3 py-2 @error('status') border-red-400 @enderror">
                <option value="open" {{ old('status', 'open') === 'open' ? 'selected' : '' }}>Open</option>
                <option value="in_progress" {{ old('status') === 'in_progress' ? 'selected' : '' }}>In Progress</option>
                <option value="completed" {{ old('status') === 'completed' ? 'selected' : '' }}>Completed</option>
                <option value="cancelled" {{ old('status') === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
            </select>
            @error('status') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>

    <div>
        <label class="block text-sm font-medium">Problem *</label>
        <textarea name="problem" required class="w-full border rounded px-3 py-2 @error('problem') border-red-400 @enderror">{{ old('problem') }}</textarea>
        @error('problem') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-sm font-medium">Action Taken</label>
        <textarea name="action" class="w-full border rounded px-3 py-2">{{ old('action') }}</textarea>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium">Vendor</label>
            <input type="text" name="vendor" value="{{ old('vendor') }}" class="w-full border rounded px-3 py-2 @error('vendor') border-red-400 @enderror">
            @error('vendor') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-medium">Cost (Rp)</label>
            <input type="number" name="cost" step="0.01" value="{{ old('cost') }}" class="w-full border rounded px-3 py-2 @error('cost') border-red-400 @enderror">
            @error('cost') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>

    <div>
        <label class="block text-sm font-medium">Notes</label>
        <textarea name="notes" class="w-full border rounded px-3 py-2"></textarea>
    </div>

    <div class="flex gap-2 pt-4">
        <button type="submit" class="bg-orange-600 text-white px-6 py-2 rounded">Add Repair</button>
        <a href="/assets/{{ $asset->id }}" class="bg-gray-300 text-gray-700 px-6 py-2 rounded">Cancel</a>
    </div>
</form>
@endsection
