@extends('layouts.app')

@section('title', 'Audit Agent Fleet')

@section('content')
<h1 class="text-2xl font-bold mb-2">Audit Agent Fleet</h1>
<p class="text-sm text-gray-500 mb-6">
    Roll out the lightweight audit agent (PRD §11) to every laptop by pasting one command.
    The script is served from this site — nothing to download, no <code>.exe</code>, revocable anytime.
</p>

@if(session('created_token'))
@php $created = session('created_token'); @endphp
<div class="bg-green-50 border border-green-300 rounded p-4 mb-6">
    <p class="font-semibold text-green-800 mb-2">Token <code>{{ $created['name'] }}</code> created — copy the commands now, the token is shown only once:</p>

    <p class="text-xs text-gray-500 mt-3 mb-1">Run once (single audit):</p>
    <div class="flex gap-2">
        <code class="bg-gray-900 text-green-300 text-xs p-2 rounded flex-1 overflow-x-auto">{{ $created['run_once'] }}</code>
        <button type="button" onclick="navigator.clipboard.writeText(@json($created['run_once']))" class="bg-green-600 text-white px-3 py-1 rounded text-xs">Copy</button>
    </div>

    <p class="text-xs text-gray-500 mt-3 mb-1">Install (runs daily at 10:17 via Scheduled Task):</p>
    <div class="flex gap-2">
        <code class="bg-gray-900 text-green-300 text-xs p-2 rounded flex-1 overflow-x-auto">{{ $created['install'] }}</code>
        <button type="button" onclick="navigator.clipboard.writeText(@json($created['install']))" class="bg-green-600 text-white px-3 py-1 rounded text-xs">Copy</button>
    </div>

    <p class="text-xs text-gray-500 mt-2">Raw token: <code>{{ $created['plain_text'] }}</code></p>
</div>
@endif

<div class="bg-white p-6 rounded shadow mb-6">
    <h2 class="text-lg font-semibold mb-3">Generate token</h2>
    <form method="POST" action="{{ route('agent.store') }}" class="flex flex-wrap gap-3 items-end">
        @csrf
        <div>
            <label class="block text-sm font-medium">Token name *</label>
            <input type="text" name="name" required placeholder="laptop-fin-01" class="border rounded px-3 py-2">
            @error('name')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="block text-sm font-medium">Expires (days)</label>
            <input type="number" name="expires_days" min="1" max="3650" value="365" class="border rounded px-3 py-2 w-28">
        </div>
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Create</button>
    </form>
</div>

<div class="bg-white p-6 rounded shadow">
    <h2 class="text-lg font-semibold mb-3">Tokens</h2>
    @if($tokens->count() > 0)
    <table class="min-w-full text-sm">
        <thead>
            <tr class="border-b bg-gray-50">
                <th class="text-left py-2 px-2">Name</th>
                <th class="text-left py-2 px-2">Expires</th>
                <th class="text-left py-2 px-2">Last used</th>
                <th class="text-left py-2 px-2">Status</th>
                <th class="text-left py-2 px-2">Action</th>
            </tr>
        </thead>
        <tbody>
            @foreach($tokens as $token)
            <tr class="border-b">
                <td class="py-2 px-2">{{ $token->name }}</td>
                <td class="py-2 px-2">{{ $token->expires_at?->format('Y-m-d') ?? 'never' }}</td>
                <td class="py-2 px-2">{{ $token->last_used_at?->diffForHumans() ?? 'never' }}</td>
                <td class="py-2 px-2">
                    @if($token->isUsable())
                        <span class="px-2 py-1 text-xs rounded bg-green-100 text-green-800">active</span>
                    @else
                        <span class="px-2 py-1 text-xs rounded bg-red-100 text-red-700">revoked/expired</span>
                    @endif
                </td>
                <td class="py-2 px-2">
                    @if($token->isUsable())
                        <form method="POST" action="{{ route('agent.revoke', $token) }}" class="inline" onsubmit="return confirm('Revoke {{ $token->name }}? Its laptops will get 401 at next run.')">
                            @csrf
                            <button type="submit" class="text-red-600 text-sm">Revoke</button>
                        </form>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @else
    <p class="text-gray-500">No tokens yet.</p>
    @endif
</div>
@endsection
