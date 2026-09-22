@extends('layouts.app')

@section('title', 'Barcode Scan')

@section('content')
<h1 class="text-2xl font-bold mb-2">Barcode / QR Scan</h1>
<p class="text-sm text-gray-500 mb-6">
    Point the scanner at the asset label and submit — or type the
    barcode, QR value, Asset ID, serial number or hostname manually.
</p>

<div class="bg-white p-6 rounded shadow max-w-xl">
    <form method="POST" action="{{ route('barcode.resolve') }}" class="flex gap-2">
        @csrf
        <input type="text" name="code" value="{{ old('code') }}" required autofocus
               placeholder="Scan here…" class="border rounded px-3 py-2 flex-1 font-mono">
        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded">Find</button>
    </form>

    @error('code')
        <p class="text-red-600 text-sm mt-3">{{ $message }}</p>
    @enderror

    <div class="text-xs text-gray-500 mt-4">
        <p class="font-medium mb-1">Supported lookups</p>
        <ul class="list-disc ml-5">
            <li>Barcode / QR code value</li>
            <li>Asset ID</li>
            <li>Serial number</li>
            <li>Hostname</li>
        </ul>
        <p class="mt-2">Check-in, check-out, transfer and audit continue through the
        asset detail page so every movement is recorded in the assignment history.</p>
    </div>
</div>
@endsection
