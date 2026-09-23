@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<h1 class="text-2xl font-bold mb-6">Dashboard</h1>

<div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4 mb-8">
    <div class="bg-white p-6 rounded shadow">
        <p class="text-sm text-gray-500">Total Assets</p>
        <p class="text-3xl font-bold">{{ $totalAssets }}</p>
    </div>
    <div class="bg-white p-6 rounded shadow">
        <p class="text-sm text-gray-500">Active</p>
        <p class="text-3xl font-bold text-green-600">{{ $activeAssets }}</p>
    </div>
    <div class="bg-white p-6 rounded shadow">
        <p class="text-sm text-gray-500">Available</p>
        <p class="text-3xl font-bold text-blue-600">{{ $availableAssets }}</p>
    </div>
    <div class="bg-white p-6 rounded shadow">
        <p class="text-sm text-gray-500">Assigned</p>
        <p class="text-3xl font-bold text-yellow-600">{{ $assignedAssets }}</p>
    </div>
    <div class="bg-white p-6 rounded shadow">
        <p class="text-sm text-gray-500">Repair</p>
        <p class="text-3xl font-bold text-orange-600">{{ $repairAssets }}</p>
    </div>
    <div class="bg-white p-6 rounded shadow">
        <p class="text-sm text-gray-500">Retired</p>
        <p class="text-3xl font-bold text-gray-600">{{ $retiredAssets }}</p>
    </div>
    <div class="bg-white p-6 rounded shadow">
        <p class="text-sm text-gray-500">Disposed</p>
        <p class="text-3xl font-bold text-gray-600">{{ $disposedAssets }}</p>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
    <div class="bg-white p-4 rounded shadow">
        <p class="text-sm text-gray-500">Open Repairs</p>
        <p class="text-2xl font-bold">{{ $openRepairs }}</p>
    </div>
    <div class="bg-white p-4 rounded shadow">
        <p class="text-sm text-gray-500">Total Repairs</p>
        <p class="text-2xl font-bold">{{ $totalRepairs }}</p>
    </div>
    <div class="bg-white p-4 rounded shadow">
        <p class="text-sm text-gray-500">Recent Transfers</p>
        <p class="text-2xl font-bold">{{ $recentTransfers->count() }}</p>
    </div>
</div>

<div class="bg-white p-6 rounded shadow mb-8">
    <h2 class="text-lg font-semibold mb-4">Warnings</h2>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        @php
            $warningLabels = [
                'warranty_expiring' => ['Warranty Expiring (60 days)', 'text-orange-600'],
                'not_audited' => ['Asset Not Audited', 'text-yellow-600'],
                'audit_mismatch' => ['Audit Mismatch', 'text-red-600'],
                'missing_serial' => ['Missing Serial', 'text-red-500'],
                'missing_asset_id' => ['Missing Asset ID', 'text-red-500'],
                'duplicate_serial' => ['Duplicate Serial', 'text-red-700'],
                'retirement_due' => ['Retirement Due', 'text-gray-700'],
                'audit_stale' => ['Audit Older Than 6 Months', 'text-yellow-700'],
            ];
        @endphp
        @foreach($warningLabels as $key => $meta)
            <div class="border rounded p-3">
                <p class="text-xs text-gray-500">{{ $meta[0] }}</p>
                <p class="text-xl font-bold {{ $meta[1] }}">{{ $warnings[$key] ?? 0 }}</p>
            </div>
        @endforeach
    </div>
</div>

<div class="bg-white p-6 rounded shadow mb-8">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-lg font-semibold">Agent Fleet</h2>
        <div class="flex gap-2 text-sm">
            @can('manage-agents')
                <a href="{{ route('agent.commands') }}" class="text-blue-600">Command Center</a>
                <form method="POST" action="{{ route('agent.scanAll') }}" class="inline">
                    @csrf
                    <button type="submit" class="text-green-700 font-medium">Scan All Laptops</button>
                </form>
            @endcan
        </div>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="border rounded p-3">
            <p class="text-xs text-gray-500">Agents Online</p>
            <p class="text-xl font-bold text-green-600">{{ $fleet['online'] }}</p>
        </div>
        <div class="border rounded p-3">
            <p class="text-xs text-gray-500">Stale (&gt; 5 min)</p>
            <p class="text-xl font-bold text-yellow-600">{{ $fleet['stale'] }}</p>
        </div>
        <div class="border rounded p-3">
            <p class="text-xs text-gray-500">Never Reported</p>
            <p class="text-xl font-bold text-gray-500">{{ $fleet['offline'] }}</p>
        </div>
        <div class="border rounded p-3">
            <p class="text-xs text-gray-500">Pending Commands</p>
            <p class="text-xl font-bold {{ $fleet['pending_commands'] > 0 ? 'text-orange-600' : 'text-gray-700' }}">{{ $fleet['pending_commands'] }}</p>
        </div>
    </div>
    <p class="text-xs text-gray-500 mt-3">Agent audits in the last 24 hours: <strong>{{ $fleet['recent_audits'] }}</strong></p>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    <div class="bg-white p-6 rounded shadow">
        <h2 class="text-lg font-semibold mb-4">Assets by Category</h2>
        <ul>
            @forelse($assetsByCategory as $item)
                <li class="flex justify-between py-1 border-b">
                    <span>{{ $item->name }}</span>
                    <span>{{ $item->total }}</span>
                </li>
            @empty
                <li class="text-gray-500">No data.</li>
            @endforelse
        </ul>
    </div>
    <div class="bg-white p-6 rounded shadow">
        <h2 class="text-lg font-semibold mb-4">Assets by Location</h2>
        <ul>
            @forelse($assetsByLocation as $item)
                <li class="flex justify-between py-1 border-b">
                    <span>{{ $item->name }}</span>
                    <span>{{ $item->total }}</span>
                </li>
            @empty
                <li class="text-gray-500">No data.</li>
            @endforelse
        </ul>
    </div>
    <div class="bg-white p-6 rounded shadow">
        <h2 class="text-lg font-semibold mb-4">Assets by Company</h2>
        <ul>
            @forelse($assetsByCompany as $item)
                <li class="flex justify-between py-1 border-b">
                    <span>{{ $item->name }}</span>
                    <span>{{ $item->total }}</span>
                </li>
            @empty
                <li class="text-gray-500">No data.</li>
            @endforelse
        </ul>
    </div>
</div>

@if($recentTransfers->count() > 0)
<div class="bg-white p-6 rounded shadow mb-8">
    <h2 class="text-lg font-semibold mb-4">Recent Transfers</h2>
    <table class="min-w-full">
        <thead>
            <tr class="border-b">
                <th class="text-left py-2 px-4">Transfer #</th>
                <th class="text-left py-2 px-4">Date</th>
                <th class="text-left py-2 px-4">From</th>
                <th class="text-left py-2 px-4">To</th>
                <th class="text-left py-2 px-4">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach($recentTransfers as $transfer)
            <tr class="border-b">
                <td class="py-2 px-4">{{ $transfer->transfer_number }}</td>
                <td class="py-2 px-4">{{ $transfer->transfer_date }}</td>
                <td class="py-2 px-4">{{ $transfer->fromUser?->name }}</td>
                <td class="py-2 px-4">{{ $transfer->toUser?->name }}</td>
                <td class="py-2 px-4"><span class="px-2 py-1 text-xs rounded bg-gray-200">{{ $transfer->status }}</span></td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

<div class="bg-white p-6 rounded shadow">
    <h2 class="text-lg font-semibold mb-4">Recent Activity</h2>
    <table class="min-w-full">
        <thead>
            <tr class="border-b">
                <th class="text-left py-2">User</th>
                <th class="text-left py-2">Action</th>
                <th class="text-left py-2">Asset</th>
                <th class="text-left py-2">Time</th>
            </tr>
        </thead>
        <tbody>
            @forelse($recentActivity as $activity)
                <tr class="border-b">
                    <td class="py-2">{{ $activity->actor?->name }}</td>
                    <td class="py-2">{{ $activity->action }}</td>
                    <td class="py-2">{{ $activity->entity_identifier }}</td>
                    <td class="py-2">{{ $activity->created_at->diffForHumans() }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-2 text-gray-500">No activity yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
