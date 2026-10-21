@extends('layouts.app')

@section('title', 'Activity Log')

@section('content')
<h1 class="text-2xl font-bold mb-6">Activity Log</h1>

<form method="GET" action="{{ route('activity.index') }}" class="flex flex-wrap items-center gap-2 mb-4">
    <input type="text" name="search" value="{{ request('search') }}" placeholder="Asset, entity, user..." class="border rounded-lg px-3 py-2 flex-1 dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:placeholder-gray-400">
    <select name="action" class="border rounded-lg px-3 py-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
        <option value="">All actions</option>
        @foreach($actions as $action)
            <option value="{{ $action }}" {{ request('action') === $action ? 'selected' : '' }}>{{ $action }}</option>
        @endforeach
    </select>
    <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-gray-600 px-4 py-2 text-theme-sm font-medium text-white transition-all duration-200 active:scale-[0.97] hover:bg-gray-700 dark:bg-gray-600">Filter</button>
    <a href="{{ route('activity.index') }}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 transition-all duration-200 active:scale-[0.97] hover:bg-gray-50 dark:border-gray-600 dark:bg-[#1d2939] dark:text-gray-100 dark:hover:bg-gray-800">Reset</a>
</form>

@if($logs->count() > 0)
<div class="overflow-x-auto">
<table class="min-w-full bg-white shadow rounded text-sm dark:bg-[#1d2939]">
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
        <tr class="border-b align-top hover:bg-gray-50 dark:hover:bg-gray-800">
            <td class="py-2 px-4 whitespace-nowrap">{{ $log->created_at?->format('Y-m-d H:i') }}</td>
            <td class="py-2 px-4">{{ $log->actor?->name }}</td>
            <td class="py-2 px-4"><span class="px-2 py-1 text-xs rounded bg-gray-200 dark:bg-gray-700">{{ $log->action }}</span></td>
            <td class="py-2 px-4">{{ $log->entity_type }}</td>
            <td class="py-2 px-4">{{ $log->entity_identifier }}</td>
            <td class="py-2 px-4">{{ $log->source }}</td>
            <td class="py-2 px-4 text-xs text-gray-600 dark:text-gray-400">
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
<p class="bg-white p-6 rounded-xl shadow dark:bg-[#1d2939]">No activity found.</p>
@endif
@endsection
