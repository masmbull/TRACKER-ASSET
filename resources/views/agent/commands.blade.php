@extends('layouts.app')

@section('title', 'Fleet Commands')

@section('content')
@php $pendingCount = $commands->where('status', 'pending')->count(); @endphp

<h1 class="text-2xl font-bold mb-2">Fleet Command Center</h1>
<p class="text-sm text-gray-500 mb-6">
    Agents poll every 10 seconds. Pending commands run automatically and results appear here.
</p>

<div class="bg-white p-6 rounded shadow mb-6">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-lg font-semibold">Commands</h2>
        <div class="flex gap-2">
            <form method="POST" action="{{ route('agent.scanAll') }}">
                @csrf
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded text-sm">Scan All Laptops</button>
            </form>
            <form method="POST" action="{{ route('agent.updateAll') }}">
                @csrf
                <button type="submit" class="bg-purple-600 text-white px-4 py-2 rounded text-sm">Update All Agents</button>
            </form>
        </div>
    </div>

    <div class="flex gap-2 mb-4 text-sm">
        <a href="{{ route('agent.index') }}" class="px-3 py-1 rounded bg-gray-200">Tokens</a>
        <a href="{{ route('agent.commands') }}" class="px-3 py-1 rounded bg-blue-600 text-white">Commands</a>
    </div>

    @if(session('success'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">{{ session('success') }}</div>
    @endif

    @if($commands->count() > 0)
    <div class="overflow-x-auto">
    <table class="min-w-full text-sm">
        <thead>
            <tr class="border-b bg-gray-50">
                <th class="text-left py-2 px-2">ID</th>
                <th class="text-left py-2 px-2">Command</th>
                <th class="text-left py-2 px-2">Token</th>
                <th class="text-left py-2 px-2">Status</th>
                <th class="text-left py-2 px-2">Result</th>
                <th class="text-left py-2 px-2">Created</th>
                <th class="text-left py-2 px-2">Executed</th>
            </tr>
        </thead>
        <tbody>
            @foreach($commands as $cmd)
            <tr class="border-b">
                <td class="py-2 px-2">#{{ $cmd->id }}</td>
                <td class="py-2 px-2"><span class="px-2 py-1 text-xs rounded bg-gray-200">{{ $cmd->command }}</span></td>
                <td class="py-2 px-2">{{ $cmd->token?->name ?? 'All' }}</td>
                <td class="py-2 px-2">
                    @php
                        $statusColors = ['pending' => 'bg-yellow-100 text-yellow-800', 'done' => 'bg-green-100 text-green-800', 'failed' => 'bg-red-100 text-red-700'];
                    @endphp
                    <span class="px-2 py-1 text-xs rounded {{ $statusColors[$cmd->status] ?? '' }}">{{ $cmd->status }}</span>
                </td>
                <td class="py-2 px-2">{{ $cmd->result ? json_encode($cmd->result) : '—' }}</td>
                <td class="py-2 px-2">{{ $cmd->created_at->diffForHumans() }}</td>
                <td class="py-2 px-2">{{ $cmd->executed_at?->diffForHumans() ?? '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    </div>
    @else
    <p class="text-gray-500">No commands yet.</p>
    @endif
</div>

<div class="bg-white p-6 rounded shadow">
    <h2 class="text-lg font-semibold mb-3">Agent Status</h2>
    <p class="text-sm text-gray-500 mb-4">Online = last poll within 60 seconds. Offline = stale or not running.</p>
    <table class="min-w-full text-sm">
        <thead>
            <tr class="border-b bg-gray-50">
                <th class="text-left py-2 px-2">Name</th>
                <th class="text-left py-2 px-2">Last Poll</th>
                <th class="text-left py-2 px-2">Status</th>
                <th class="text-left py-2 px-2">Action</th>
            </tr>
        </thead>
        <tbody>
            @foreach($tokens as $token)
            @php $online = $token->last_used_at && $token->last_used_at->gt(now()->subSeconds(90)); @endphp
            <tr class="border-b">
                <td class="py-2 px-2">{{ $token->name }}</td>
                <td class="py-2 px-2">{{ $token->last_used_at ? $token->last_used_at->diffForHumans() : 'never' }}</td>
                <td class="py-2 px-2">
                    @if($online)
                        <span class="inline-block w-2 h-2 rounded-full bg-green-500 mr-1"></span>Online
                    @else
                        <span class="inline-block w-2 h-2 rounded-full bg-gray-400 mr-1"></span>Offline
                    @endif
                </td>
                <td class="py-2 px-2">
                    @if($token->isUsable())
                        <form method="POST" action="{{ route('agent.scan', $token) }}" class="inline">
                            @csrf
                            <button type="submit" class="text-blue-600 text-sm font-medium">Scan Now</button>
                        </form>
                    @else
                        <span class="text-gray-400 text-sm">revoked</span>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

{{-- Auto-refresh while work is in flight, so admins watch commands complete live. --}}
@if($pendingCount > 0)
    <script>
        setTimeout(function () { window.location.reload(); }, 10000);
    </script>
@endif
@endsection
