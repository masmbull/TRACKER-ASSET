@extends('layouts.app')

@section('title', 'Reports')

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <h1 class="text-2xl font-bold">Reports</h1>
    <a href="{{ route('reports.export', request()->only(['company', 'location'])) }}" class="inline-flex items-center justify-center rounded-lg bg-success-500 px-4 py-2 text-theme-sm font-medium text-white shadow-theme-xs transition-all duration-200 active:scale-[0.97] hover:bg-success-600">Export CSV</a>
</div>

<form method="GET" action="{{ route('reports.index') }}" class="bg-white p-4 rounded-xl shadow mb-6 flex flex-wrap gap-3 items-end">
    <div>
        <label class="block text-xs text-gray-500">Company</label>
        <select name="company" class="border rounded-lg px-3 py-2">
            <option value="">All</option>
            @foreach($companies as $c)
                <option value="{{ $c->id }}" {{ (string) request('company') === (string) $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500">Location</label>
        <select name="location" class="border rounded-lg px-3 py-2">
            <option value="">All</option>
            @foreach($locations as $l)
                <option value="{{ $l->id }}" {{ (string) request('location') === (string) $l->id ? 'selected' : '' }}>{{ $l->name }}</option>
            @endforeach
        </select>
    </div>
    <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-gray-600 px-4 py-2 text-theme-sm font-medium text-white transition-all duration-200 active:scale-[0.97] hover:bg-gray-700">Apply</button>
</form>

<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
    <div class="bg-white p-6 rounded-xl shadow">
        <p class="text-sm text-gray-500">Total Assets</p>
        <p class="text-3xl font-bold">{{ $totalAssets }}</p>
    </div>
    <div class="bg-white p-6 rounded-xl shadow">
        <p class="text-sm text-gray-500">Total Purchase Value</p>
        <p class="text-xl font-bold">Rp {{ number_format($totalValue, 0, ',', '.') }}</p>
    </div>
    <div class="bg-white p-6 rounded-xl shadow">
        <p class="text-sm text-gray-500">Repair Cost ({{ now()->year }})</p>
        <p class="text-xl font-bold">Rp {{ number_format($repairCostThisYear, 0, ',', '.') }}</p>
    </div>
    <div class="bg-white p-6 rounded-xl shadow">
        <p class="text-sm text-gray-500">Retirement Due</p>
        <p class="text-3xl font-bold text-gray-700">{{ $retirementDue }}</p>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
    <div class="bg-white p-6 rounded-xl shadow">
        <p class="text-sm text-gray-500">Audit Coverage (12 months)</p>
        <p class="text-2xl font-bold text-green-600">{{ $auditedLast12Months }}</p>
        <p class="text-xs text-gray-500">Never audited: <strong class="text-red-600">{{ $neverAudited }}</strong></p>
    </div>
    <div class="bg-white p-6 rounded-xl shadow">
        <p class="text-sm text-gray-500">Assets with Open Mismatches</p>
        <p class="text-2xl font-bold text-red-600">{{ $openMismatches }}</p>
    </div>
    <div class="bg-white p-6 rounded-xl shadow">
        <p class="text-sm text-gray-500">Never Audited (all time)</p>
        <p class="text-2xl font-bold">{{ $neverAudited }}</p>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-6">
    @foreach([['Assets by Category', $byCategory], ['Assets by Status', $byStatus], ['Assets by Location', $byLocation], ['Assets by Company', $byCompany]] as $section)
        <div class="bg-white p-6 rounded-xl shadow">
            <h2 class="text-lg font-semibold mb-4">{{ $section[0] }}</h2>
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b">
                        <th class="text-left py-2">Name</th>
                        <th class="text-right py-2">Assets</th>
                        <th class="text-right py-2">Value (Rp)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($section[1] as $row)
                        <tr class="border-b">
                            <td class="py-2">{{ $row->name }}</td>
                            <td class="py-2 text-right">{{ $row->total }}</td>
                            <td class="py-2 text-right">{{ number_format($row->value, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-gray-500">No data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endforeach
</div>
@endsection
