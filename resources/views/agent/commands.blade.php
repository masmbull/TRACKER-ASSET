@extends('layouts.app')

@section('title', 'Fleet Commands')

@section('content')
@php $pendingCount = $commands->where('status', 'pending')->count(); @endphp

<x-page-header title="Fleet Command Center" subtitle="Agents poll every 10 seconds and pending commands are executed automatically.">
    <x-slot:actions>
        <div class="flex flex-wrap items-center gap-2">
            <form method="POST" action="{{ route('agent.scanAll') }}">
                @csrf
                <x-button type="submit" variant="secondary" size="sm">Scan All Laptops</x-button>
            </form>
            <form method="POST" action="{{ route('agent.updateAll') }}">
                @csrf
                <x-button type="submit" variant="primary" size="sm">Update All Agents</x-button>
            </form>
            <form method="POST" action="{{ route('agent.sweep') }}">
                @csrf
                <x-button type="submit" variant="success" size="sm">Sweep Now</x-button>
            </form>
        </div>
    </x-slot:actions>
</x-page-header>

<x-card class="mb-6">
    <div class="flex flex-wrap gap-2 text-theme-sm">
        <a href="{{ route('agent.index') }}" class="rounded-lg bg-gray-100 px-3 py-1.5 font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">Tokens</a>
        <a href="{{ route('agent.commands') }}" class="rounded-lg bg-brand-600 px-3 py-1.5 font-medium text-white">Commands</a>
        <a href="{{ route('agent.fleet') }}" class="rounded-lg bg-gray-100 px-3 py-1.5 font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">Live Fleet</a>
    </div>

    @if(session('success'))
        <div class="mt-4 rounded-xl border border-success-200 bg-success-50 px-4 py-3 text-theme-sm text-success-700 dark:border-success-500/20 dark:bg-success-500/10 dark:text-success-300">
            {{ session('success') }}
        </div>
    @endif
</x-card>

<x-card class="mb-6" title="Commands">
    @if($commands->count() > 0)
        <div class="overflow-x-auto">
            <x-table :headers="['ID', 'Command', 'Token', 'Status', 'Result', 'Created', 'Executed']">
                @foreach($commands as $cmd)
                    <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-white/5">
                        <td class="py-2 px-2 text-theme-sm text-gray-600 dark:text-gray-400">#{{ $cmd->id }}</td>
                        <td class="py-2 px-2"><span class="rounded bg-gray-100 px-2 py-1 text-theme-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300">{{ $cmd->command }}</span></td>
                        <td class="py-2 px-2 text-theme-sm text-gray-600 dark:text-gray-400">{{ $cmd->token?->name ?? 'All' }}</td>
                        <td class="py-2 px-2">
                            @php
                                $statusColors = ['pending' => 'bg-warning-100 text-warning-700 dark:bg-warning-500/10 dark:text-warning-300', 'done' => 'bg-success-100 text-success-700 dark:bg-success-500/10 dark:text-success-300', 'failed' => 'bg-error-100 text-error-700 dark:bg-error-500/10 dark:text-error-300'];
                            @endphp
                            <span class="rounded px-2 py-1 text-theme-xs font-medium {{ $statusColors[$cmd->status] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300' }}">{{ $cmd->status }}</span>
                        </td>
                        <td class="py-2 px-2 text-theme-sm text-gray-600 dark:text-gray-400">{{ $cmd->result ? json_encode($cmd->result) : '—' }}</td>
                        <td class="py-2 px-2 text-theme-sm text-gray-600 dark:text-gray-400">{{ $cmd->created_at->diffForHumans() }}</td>
                        <td class="py-2 px-2 text-theme-sm text-gray-600 dark:text-gray-400">{{ $cmd->executed_at?->diffForHumans() ?? '—' }}</td>
                    </tr>
                @endforeach
            </x-table>
        </div>
    @else
        <p class="py-8 text-center text-theme-sm text-gray-400">No commands yet.</p>
    @endif
</x-card>

<x-card title="Agent Status">
    <p class="mb-4 text-theme-sm text-gray-500 dark:text-gray-400">Online = last poll within 60 seconds. Offline = stale or not running.</p>

    <x-table :headers="['Name', 'Last Poll', 'Status', 'Action']">
        @foreach($tokens as $token)
            @php $online = $token->last_used_at && $token->last_used_at->gt(now()->subSeconds(90)); @endphp
            <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-white/5">
                <td class="py-2 px-2 text-theme-sm text-gray-700 dark:text-gray-300">{{ $token->name }}</td>
                <td class="py-2 px-2 text-theme-sm text-gray-600 dark:text-gray-400">{{ $token->last_used_at ? $token->last_used_at->diffForHumans() : 'never' }}</td>
                <td class="py-2 px-2 text-theme-sm text-gray-600 dark:text-gray-400">
                    @if($online)
                        <span class="mr-1 inline-block h-2 w-2 rounded-full bg-success-500"></span>Online
                    @else
                        <span class="mr-1 inline-block h-2 w-2 rounded-full bg-gray-400"></span>Offline
                    @endif
                </td>
                <td class="py-2 px-2">
                    @if($token->isUsable())
                        <form method="POST" action="{{ route('agent.scan', $token) }}" class="inline">
                            @csrf
                            <button type="submit" class="text-theme-sm font-medium text-brand-500 hover:text-brand-600">Scan Now</button>
                        </form>
                    @else
                        <span class="text-theme-sm text-gray-400">revoked</span>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-table>
</x-card>

@if($pendingCount > 0)
    <script>
        setTimeout(function () { window.location.reload(); }, 10000);
    </script>
@endif
@endsection
