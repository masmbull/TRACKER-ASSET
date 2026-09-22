@extends('layouts.app')

@section('title', ($record ? 'Edit' : 'New') . ' ' . $config['singular'])

@section('content')
<h1 class="text-2xl font-bold mb-6">{{ $record ? 'Edit' : 'New' }} {{ $config['singular'] }}</h1>

<form method="POST" action="{{ $record ? route('master.update', [$type, $record->id]) : route('master.store', $type) }}" class="bg-white p-6 rounded shadow space-y-4">
    @csrf
    @if($record)
        @method('PUT')
    @endif

    @foreach($config['fields'] as $field => $definition)
        <div>
            <label class="block text-sm font-medium">{{ $definition['label'] }} {{ ($definition['required'] ?? false) ? '*' : '' }}</label>
            @if(($definition['type'] ?? 'text') === 'select')
                <select name="{{ $field }}" {{ ($definition['required'] ?? false) ? 'required' : '' }} class="w-full border rounded px-3 py-2">
                    @foreach($definition['options'] as $value => $label)
                        <option value="{{ $value }}" {{ (string) old($field, $record?->$field ?? '') === (string) $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            @elseif(($definition['type'] ?? 'text') === 'textarea')
                <textarea name="{{ $field }}" class="w-full border rounded px-3 py-2">{{ old($field, $record?->$field ?? '') }}</textarea>
            @else
                <input type="text" name="{{ $field }}" {{ ($definition['required'] ?? false) ? 'required' : '' }} value="{{ old($field, $record?->$field ?? '') }}" class="w-full border rounded px-3 py-2">
            @endif
            @error($field)
                <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
            @enderror
        </div>
    @endforeach

    <div class="flex gap-2 pt-4">
        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded">{{ $record ? 'Update' : 'Create' }}</button>
        <a href="{{ route('master.index', $type) }}" class="bg-gray-300 text-gray-700 px-6 py-2 rounded">Cancel</a>
    </div>
</form>
@endsection
