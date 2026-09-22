@extends('layouts.app')

@section('title', 'Activity Log')

@section('content')
<h1 class="text-2xl font-bold mb-6">Activity Log</h1>

<form method="GET" action="{{ route('activity.index') }}" class="flex flex-wrap gap-2 mb-4">
    <input type="text" name="search" value="{{ request('search') }}" placeholder="Asset, entity, user..." class="border rounded px-3 py-2 flex-1">
    <select name="action" class="border rounded px-3 py-2">
        <option value="">All actions</option>
        @foreach($actions as $action)
            <option value="{{ $action }}" {{ request('action') === $action ? 'selected' : '' }}>{{ $action }}</option>
        @endforeach
    </select>
    <button type="submit" class="bg-gray-600 text-white px-4 py-2 rounded">Filter</button>
    <a href="{{ route('activity.index') }}" class="bg-gray-300 text-gray-700 px-4 py-2 rounded">Reset</a>
</form>

@if($logs->count() > 0)
<div class="overflow-x-auto">
<table class="min-w-full bg-white shadow rounded text-sm">
    <thead>
        <tr class="border-b">
            <th class="text-left py-3 px-4">Time</th>
            <th class="text-left py-3 px-4">User</th>
            <th class="text-left py-3 px-4">Action</th>
            <th class="text-left py-3 px-4">Entity</th>
            <th class="text-left py-3 px-4">Identifier</th>
            <th class="text-left py-3 px-4">Source</th>
            <th class="text-left py-3 px-4">Detail</th>
        </tr>
    </thead>
    <tbody>
        @foreach($logs as $log)
        <tr class="border-b align-top hover:bg-gray-50">
            <td class="py-2 px-4 whitespace-nowrap">{{ $log->created_at?->format('Y-m-d H:i') }}</td>
            <td class="py-2 px-4">{{ $log->actor?->name }}</td>
            <td class="py-2 px-4"><span class="px-2 py-1 text-xs rounded bg-gray-200">{{ $log->action }}</span></td>
            <td class="py-2 px-4">{{ $log->entity_type }}</td>
            <td class="py-2 px-4">{{ $log->entity_identifier }}</td>
            <td class="py-2 px-4">{{ $log->source }}</td>
            <td class="py-2 px-4 text-xs text-gray-600">
                @if(!empty($log->mismatches ?? null))
                    @foreach($log->mismatches as $field => $values)
                        <div>{{ $field }}: {{ $values['registered'] }} → {{ $values['actual'] }}</div>
                    @endforeach
                @elseif(!empty($log->new_value))
                    {{ \Illuminate\Support\Str::limit($log->new_value, 120) }}
                @elseif(!empty($log->metadata))
                    {{ \Illuminate\Support\Str::limit(json_encode($log->metadata), 120) }}
                @endif
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>

{{ $logs->links() }}
@else
<p class="bg-white p-6 rounded shadow">No activity found.</p>
@endif
@endsection
