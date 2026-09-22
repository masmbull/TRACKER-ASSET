@extends('layouts.app')

@section('title', 'Audit Asset')

@section('content')
<h1 class="text-2xl font-bold mb-6">Audit Asset {{ $asset->asset_id }}</h1>

<div class="bg-white p-6 rounded shadow mb-6">
    <h2 class="text-lg font-semibold mb-4">Registered Data</h2>
    <dl class="grid grid-cols-2 gap-2">
        <dt class="font-medium">Hostname</dt><dd>{{ $asset->hostname }}</dd>
        <dt class="font-medium">Serial Number</dt><dd>{{ $asset->serial_number }}</dd>
        <dt class="font-medium">Processor</dt><dd>{{ $asset->processor }}</dd>
        <dt class="font-medium">RAM</dt><dd>{{ $asset->ram }}</dd>
        <dt class="font-medium">Storage</dt><dd>{{ $asset->storage }}</dd>
        <dt class="font-medium">OS</dt><dd>{{ $asset->os_version }}</dd>
        <dt class="font-medium">MAC Address</dt><dd>{{ $asset->mac_address }}</dd>
        <dt class="font-medium">IP Address</dt><dd>{{ $asset->static_ip }}</dd>
    </dl>
</div>

@if($asset->audits->count() > 0)
<div class="bg-white p-6 rounded shadow mb-6">
    <h2 class="text-lg font-semibold mb-4">Previous Audits</h2>
    <table class="min-w-full">
        <thead>
            <tr class="border-b">
                <th class="text-left py-2 px-4">Date</th>
                <th class="text-left py-2 px-4">Result</th>
                <th class="text-left py-2 px-4">Auditor</th>
            </tr>
        </thead>
        <tbody>
            @foreach($asset->audits as $audit)
            <tr class="border-b">
                <td class="py-2 px-4">{{ $audit->audit_date->format('Y-m-d H:i') }}</td>
                <td class="py-2 px-4"><span class="px-2 py-1 text-xs rounded {{ $audit->result === 'MATCH' ? 'bg-green-200' : 'bg-red-200' }}">{{ $audit->result }}</span></td>
                <td class="py-2 px-4">{{ $audit->auditor?->name }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

<form method="POST" action="/assets/{{ $asset->id }}/audit" class="bg-white p-6 rounded shadow space-y-4">
    @csrf

    <h2 class="text-lg font-semibold">Enter Actual Device Data</h2>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium">Device Hostname *</label>
            <input type="text" name="device_hostname" required value="{{ old('device_hostname', $asset->hostname) }}" class="w-full border rounded px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium">Device Serial Number *</label>
            <input type="text" name="device_serial" required value="{{ old('device_serial', $asset->serial_number) }}" class="w-full border rounded px-3 py-2">
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium">Processor</label>
            <input type="text" name="device_processor" value="{{ old('device_processor') }}" class="w-full border rounded px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium">RAM</label>
            <input type="text" name="device_ram" value="{{ old('device_ram') }}" class="w-full border rounded px-3 py-2">
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium">Storage</label>
            <input type="text" name="device_storage" value="{{ old('device_storage') }}" class="w-full border rounded px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium">OS</label>
            <input type="text" name="device_os" value="{{ old('device_os') }}" class="w-full border rounded px-3 py-2">
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium">MAC Address</label>
            <input type="text" name="device_mac" value="{{ old('device_mac', $asset->mac_address) }}" class="w-full border rounded px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium">IP Address</label>
            <input type="text" name="device_ip" value="{{ old('device_ip', $asset->static_ip) }}" class="w-full border rounded px-3 py-2">
        </div>
    </div>

    <div>
        <label class="block text-sm font-medium">Audit Result *</label>
        <select name="result" required class="w-full border rounded px-3 py-2">
            <option value="MATCH">MATCH</option>
            <option value="MISMATCH">MISMATCH</option>
            <option value="NEW DEVICE">NEW DEVICE</option>
            <option value="UNKNOWN DEVICE">UNKNOWN DEVICE</option>
            <option value="INCOMPLETE">INCOMPLETE</option>
        </select>
    </div>

    <div>
        <label class="block text-sm font-medium">Notes</label>
        <textarea name="notes" class="w-full border rounded px-3 py-2">{{ old('notes') }}</textarea>
    </div>

    <div class="flex gap-2 pt-4">
        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded">Submit Audit</button>
        <a href="/assets/{{ $asset->id }}" class="bg-gray-300 text-gray-700 px-6 py-2 rounded">Cancel</a>
    </div>
</form>
@endsection
