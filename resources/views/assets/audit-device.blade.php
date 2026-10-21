@extends('layouts.app')

@section('title', 'Device Scan — ' . $asset->asset_id)

@section('content')
<h1 class="text-2xl font-bold mb-2">Device Scan — {{ $asset->asset_id }}</h1>
<p class="text-sm text-gray-500 mb-6 dark:text-gray-400">
    Replacement for the legacy EXE <code>Scan</code> button. <strong>Open this page on the device
    being audited</strong>, click Scan once, then submit. The browser reports only what it can
    truthfully read — serial / hostname / MAC can be typed from the device label below.
</p>

<div class="bg-white p-6 rounded-xl shadow mb-6 dark:bg-[#1d2939]">
    <div class="grid grid-cols-2 gap-2 text-sm mb-4">
        <div><span class="text-gray-500 dark:text-gray-400">Registered:</span> {{ $asset->brand }} {{ $asset->model }} · {{ $asset->serial_number }} · {{ $asset->hostname }}</div>
        <div><span class="text-gray-500 dark:text-gray-400">Registered OS / RAM / IP:</span> {{ $asset->os_version }} · {{ $asset->ram }} · {{ $asset->static_ip }}</div>
    </div>

    <button type="button" onclick="scanDevice()" class="inline-flex items-center justify-center rounded-lg bg-brand-500 px-8 py-3 text-lg font-medium text-white shadow-theme-xs transition-all duration-200 active:scale-[0.97] hover:bg-brand-600">
        Scan Device (1 klik)
    </button>
    <span id="scan-status" class="ml-3 text-sm text-green-600 font-semibold dark:text-green-400"></span>

    <table class="min-w-full text-sm mt-4" id="scan-preview" style="display:none">
        <thead>
            <tr class="border-b bg-gray-50 dark:bg-gray-900">
                <th class="text-left py-2 px-2">Field</th>
                <th class="text-left py-2 px-2">Collected</th>
                <th class="text-left py-2 px-2">Registered</th>
            </tr>
        </thead>
        <tbody id="scan-rows"></tbody>
    </table>
</div>

<form method="POST" action="{{ route('assets.audit.device.submit', $asset) }}" class="bg-white p-6 rounded-xl shadow space-y-4 dark:bg-[#1d2939]">
    @csrf
    <input type="hidden" name="browser_os" id="browser_os">
    <input type="hidden" name="browser_ram" id="browser_ram">
    <input type="hidden" name="browser_processor" id="browser_processor">
    <input type="hidden" name="browser_storage" id="browser_storage">
    <input type="hidden" name="browser_gpu" id="browser_gpu">

    <div class="bg-gray-50 border border-gray-200 rounded p-3 text-sm dark:bg-gray-900 dark:border-gray-700">
        Browser sandbox <strong>cannot</strong> read serial / hostname / MAC. Type them from the
        device label if you verify them physically — leave empty to skip (they will not be compared).
    </div>

    <div class="grid grid-cols-3 gap-4">
        {{-- These three read old() so a rejected submit keeps what was typed.
             Without it, hitting a validation rule cleared the serial, hostname
             and MAC but left Notes alone - the worst mix, because the form
             looked partly filled and the user could not tell which half was
             real. All three are capped at 255 server-side, so a long value
             pasted from a label can legitimately be rejected. --}}
        <div>
            <label class="block text-sm font-medium">Serial Number (from label)</label>
            <input type="text" name="manual_serial" value="{{ old('manual_serial') }}" class="w-full border rounded-lg px-3 py-2 @error('manual_serial') border-red-400 @enderror dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:placeholder-gray-400" placeholder="{{ $asset->serial_number }}">
            @error('manual_serial') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-medium">Hostname</label>
            <input type="text" name="manual_hostname" value="{{ old('manual_hostname') }}" class="w-full border rounded-lg px-3 py-2 @error('manual_hostname') border-red-400 @enderror dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:placeholder-gray-400" placeholder="{{ $asset->hostname }}">
            @error('manual_hostname') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-medium">MAC Address</label>
            <input type="text" name="manual_mac" value="{{ old('manual_mac') }}" class="w-full border rounded-lg px-3 py-2 @error('manual_mac') border-red-400 @enderror dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:placeholder-gray-400" placeholder="{{ $asset->mac_address }}">
            @error('manual_mac') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
    </div>

    <div>
        <label class="block text-sm font-medium">Notes</label>
        <textarea name="notes" rows="2" class="w-full border rounded-lg px-3 py-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white">{{ old('notes') }}</textarea>
    </div>

    <div class="flex gap-2 pt-2">
        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-success-500 px-6 py-2 text-theme-sm font-medium text-white shadow-theme-xs transition-all duration-200 active:scale-[0.97] hover:bg-success-600">Submit Audit</button>
        <a href="{{ route('assets.audit', $asset) }}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-6 py-2 text-theme-sm font-medium text-gray-700 transition-all duration-200 active:scale-[0.97] hover:bg-gray-50 dark:border-gray-600 dark:bg-[#1d2939] dark:text-gray-100 dark:hover:bg-gray-800">Manual Form</a>
        <a href="{{ route('assets.show', $asset) }}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-6 py-2 text-theme-sm font-medium text-gray-700 transition-all duration-200 active:scale-[0.97] hover:bg-gray-50 dark:border-gray-600 dark:bg-[#1d2939] dark:text-gray-100 dark:hover:bg-gray-800">Cancel</a>
    </div>
</form>

<script>
    const REGISTERED = {
        os_version: @json($asset->os_version),
        ram: @json($asset->ram),
        processor: @json($asset->processor),
        storage: @json($asset->storage),
        static_ip: @json($asset->static_ip),
    };

    async function scanDevice() {
        const status = document.getElementById('scan-status');
        status.textContent = 'Scanning…';

        const os = collectOs();
        const ram = navigator.deviceMemory ? navigator.deviceMemory + ' GB' : '';
        const processor = navigator.hardwareConcurrency ? navigator.hardwareConcurrency + ' threads' : '';
        const gpu = collectGpu();
        let storage = '';
        try {
            if (navigator.storage && navigator.storage.estimate) {
                const est = await navigator.storage.estimate();
                if (est && est.quota) {
                    storage = (est.quota / 1073741824).toFixed(0) + ' GB (approx)';
                }
            }
        } catch (e) {}

        document.getElementById('browser_os').value = os;
        document.getElementById('browser_ram').value = ram;
        document.getElementById('browser_processor').value = processor;
        document.getElementById('browser_storage').value = storage;
        document.getElementById('browser_gpu').value = gpu;

        const fields = [
            ['OS', os, REGISTERED.os_version],
            ['RAM', ram, REGISTERED.ram],
            ['Processor', processor, REGISTERED.processor],
            ['Storage', storage, REGISTERED.storage],
            ['GPU (device)', gpu, ''],
        ];

        const rows = fields.map(function (f) {
            const name = f[0], actual = f[1], reg = f[2];
            let badge = '<span class="text-gray-400">not collected</span>';
            if (actual && reg) {
                const token = (reg || '').split(/[ ,]/)[0];
                const hit = token && actual.toLowerCase().indexOf(token.toLowerCase()) >= 0;
                badge = hit
                    ? '<span class="text-green-600 font-semibold dark:text-green-400">match</span>'
                    : '<span class="text-gray-700 font-semibold dark:text-gray-100">differs</span>';
            } else if (actual) {
                badge = '<span class="text-gray-600 dark:text-gray-400">collected</span>';
            }
            return '<tr class="border-b">'
                + '<td class="py-1 px-2">' + name + '</td>'
                + '<td class="py-1 px-2">' + (actual || '—') + '</td>'
                + '<td class="py-1 px-2">' + (reg || '—') + ' ' + badge + '</td>'
                + '</tr>';
        }).join('');

        document.getElementById('scan-rows').innerHTML = rows;
        document.getElementById('scan-preview').style.display = '';
        status.textContent = 'Collected ' + fields.filter(function (f) { return f[1]; }).length + '/' + fields.length + ' fields.';
    }

    function collectOs() {
        const ua = navigator.userAgent;
        let os = '';
        const w = ua.match(/Windows NT ([\d.]+)/);
        if (w) { os = 'Windows ' + w[1]; }
        else if (/Mac OS X/.test(ua)) { os = (ua.match(/Mac OS X ([\d_.]+)/) || ['', ''])[0].replace(/_/g, '.'); }
        else if (/Linux/.test(ua)) { os = 'Linux'; }
        else { os = ua; }

        if (navigator.userAgentData && navigator.userAgentData.platformVersion && /Windows/.test(os)) {
            os = 'Windows 10.0.' + navigator.userAgentData.platformVersion;
        }
        return os;
    }

    function collectGpu() {
        try {
            const canvas = document.createElement('canvas');
            const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
            const ext = gl.getExtension('WEBGL_debug_renderer_info');
            if (ext) { return gl.getParameter(ext.UNMASKED_RENDERER_WEBGL) || ''; }
        } catch (e) {}
        return '';
    }
</script>
@endsection
