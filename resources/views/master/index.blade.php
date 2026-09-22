@extends('layouts.app')

@section('title', $config['label'] . ' — Master Data')

@section('content')
<div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-bold">{{ $config['label'] }} <span class="text-base font-normal text-gray-500">Master Data</span></h1>
    <a href="{{ route('master.create', $type) }}" class="bg-blue-600 text-white px-4 py-2 rounded">+ Add</a>
</div>

<div class="flex gap-2 mb-6 text-sm">
    <span class="text-gray-500 self-center">Master:</span>
    @foreach(['companies' => 'Companies', 'locations' => 'Locations', 'categories' => 'Categories', 'statuses' => 'Statuses'] as $key => $label)
        <a href="{{ route('master.index', $key) }}" class="px-3 py-1 rounded {{ $type === $key ? 'bg-blue-600 text-white' : 'bg-white text-gray-700' }}">{{ $label }}</a>
    @endforeach
</div>

<form method="GET" action="{{ route('master.index', $type) }}" class="flex gap-2 mb-4">
    <input type="text" name="search" value="{{ request('search') }}" placeholder="Search name..." class="border rounded px-3 py-2 flex-1">
    <button type="submit" class="bg-gray-600 text-white px-4 py-2 rounded">Search</button>
</form>

@if($records->count() > 0)
<table class="min-w-full bg-white shadow rounded text-sm">
    <thead>
        <tr class="border-b">
            @foreach($config['columns'] as $column)
                <th class="text-left py-3 px-4 capitalize">{{ $column }}</th>
            @endforeach
            <th class="text-left py-3 px-4">Used By</th>
            <th class="text-left py-3 px-4">Actions</th>
        </tr>
    </thead>
    <tbody>
        @foreach($records as $record)
        <tr class="border-b hover:bg-gray-50">
            @foreach($config['columns'] as $column)
                <td class="py-3 px-4">{{ $record->$column }}</td>
            @endforeach
            <td class="py-3 px-4">{{ $record->usage_total }}</td>
            <td class="py-3 px-4 whitespace-nowrap">
                <a href="{{ route('master.edit', [$type, $record->id]) }}" class="text-yellow-600">Edit</a>
                @if($record->usage_total === 0)
                    <form method="POST" action="{{ route('master.destroy', [$type, $record->id]) }}" class="inline ml-2" onsubmit="return confirm('Delete this record?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-red-600">Delete</button>
                    </form>
                @else
                    <span class="text-gray-400 ml-2" title="Still referenced — deactivate instead">Delete</span>
                @endif
            </td>
        </tr>
        @endforeach
    </tbody>
</table>

{{ $records->links() }}
@else
<p class="bg-white p-6 rounded shadow">No records found.</p>
@endif
@endsection
