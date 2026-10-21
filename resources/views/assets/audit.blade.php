@extends('layouts.app')

@section('title', 'Audit Asset')

@section('content')
<h1 class="text-2xl font-bold mb-6">Audit Asset {{ $asset->asset_id }}</h1>

<div class="mb-4 bg-gray-50 border border-gray-200 rounded p-3 text-sm dark:bg-gray-900 dark:border-gray-700">
    <strong>Recommended:</strong> open the page on the device itself and use the
    <a href="{{ route('assets.audit.device', $asset) }}" class="text-red-600 font-semibold hover:text-red-700 dark:text-red-400">Device Scan (1 klik)</a>
    — browser auto-collects OS/RAM/CPU/GPU and compares automatically.
</div>

<div class="bg-white p-6 rounded-xl shadow mb-6 dark:bg-[#1d2939]">
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
<div class="bg-white p-6 rounded-xl shadow mb-6 dark:bg-[#1d2939]">
    <h2 class="text-lg font-semibold mb-4">Previous Audits</h2>
    <table class="min-w-full">
        <thead>
            <tr class="border-b">
                <th class="text-left py-2 px-4">Date</th>
                <th class="text-left py-2 px-4">Result</th>
                <th class="text-left py-2 px-4">Mismatches</th>
                <th class="text-left py-2 px-4">Auditor</th>
            </tr>
        </thead>
        <tbody>
            @foreach($asset->audits as $audit)
            <tr class="border-b align-top">
                <td class="py-2 px-4">{{ $audit->audit_date->format('Y-m-d H:i') }}</td>
                <td class="py-2 px-4"><span class="px-2 py-1 text-xs rounded {{ $audit->result === 'MATCH' ? 'bg-green-200 dark:bg-green-900 dark:text-green-300' : ($audit->result === 'MISMATCH' ? 'bg-red-200 dark:bg-red-900 dark:text-red-300' : 'bg-gray-200 dark:bg-gray-700') }}">{{ $audit->result }}</span></td>
                <td class="py-2 px-4 text-xs">
                    @if(!empty($audit->mismatches))
                        <ul class="list-disc ml-4">
                            @foreach($audit->mismatches as $field => $values)
                                <li><strong>{{ $field }}</strong>: registered "{{ $values['registered'] }}" / actual "{{ $values['actual'] }}"</li>
                            @endforeach
                        </ul>
                    @else
                        <span class="text-gray-400">—</span>
                    @endif
                </td>
                <td class="py-2 px-4">{{ $audit->auditor?->name }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

<form method="POST" action="/assets/{{ $asset->id }}/audit" class="bg-white p-6 rounded-xl shadow space-y-4 dark:bg-[#1d2939]">
    @csrf

    <h2 class="text-lg font-semibold">Enter Actual Device Data</h2>
    <p class="text-sm text-gray-500 dark:text-gray-400">The result (MATCH / MISMATCH / INCOMPLETE) is computed by the system from your input — it cannot be chosen manually.</p>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium">Device Hostname *</label>
            <input type="text" name="device_hostname" required value="{{ old('device_hostname', $asset->hostname) }}" class="w-full border rounded-lg px-3 py-2 @error('device_hostname') border-red-400 @enderror dark:border-gray-700 dark:bg-gray-900 dark:text-white">
            @error('device_hostname') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-medium">Device Serial Number *</label>
            <input type="text" name="device_serial" required value="{{ old('device_serial', $asset->serial_number) }}" class="w-full border rounded-lg px-3 py-2 @error('device_serial') border-red-400 @enderror dark:border-gray-700 dark:bg-gray-900 dark:text-white">
            @error('device_serial') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium">Brand</label>
            <input type="text" name="device_brand" value="{{ old('device_brand', $asset->brand) }}" class="w-full border rounded-lg px-3 py-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
        </div>
        <div>
            <label class="block text-sm font-medium">Model</label>
            <input type="text" name="device_model" value="{{ old('device_model', $asset->model) }}" class="w-full border rounded-lg px-3 py-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium">Processor</label>
            <input type="text" name="device_processor" value="{{ old('device_processor') }}" class="w-full border rounded-lg px-3 py-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
        </div>
        <div>
            <label class="block text-sm font-medium">RAM</label>
            <input type="text" name="device_ram" value="{{ old('device_ram') }}" class="w-full border rounded-lg px-3 py-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium">Storage</label>
            <input type="text" name="device_storage" value="{{ old('device_storage') }}" class="w-full border rounded-lg px-3 py-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
        </div>
        <div>
            <label class="block text-sm font-medium">OS</label>
            <input type="text" name="device_os" value="{{ old('device_os') }}" class="w-full border rounded-lg px-3 py-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium">MAC Address</label>
            <input type="text" name="device_mac" value="{{ old('device_mac', $asset->mac_address) }}" class="w-full border rounded-lg px-3 py-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
        </div>
        <div>
            <label class="block text-sm font-medium">IP Address</label>
            <input type="text" name="device_ip" value="{{ old('device_ip', $asset->static_ip) }}" class="w-full border rounded-lg px-3 py-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
        </div>
    </div>

    <div>
        <label class="block text-sm font-medium">Notes</label>
        <textarea name="notes" class="w-full border rounded-lg px-3 py-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white">{{ old('notes') }}</textarea>
    </div>

    <div class="flex flex-wrap items-center gap-2 pt-4">
        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-brand-500 px-6 py-2 text-theme-sm font-medium text-white shadow-theme-xs transition-all duration-200 active:scale-[0.97] hover:bg-brand-600">Submit Audit</button>
        <a href="/assets/{{ $asset->id }}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-6 py-2 text-theme-sm font-medium text-gray-700 transition-all duration-200 active:scale-[0.97] hover:bg-gray-50 dark:border-gray-600 dark:bg-[#1d2939] dark:text-gray-100 dark:hover:bg-gray-800">Cancel</a>
    </div>
</form>
@endsection
