@extends('layouts.app')

@section('title', 'Barcode Scan')

@section('content')
<h1 class="text-2xl font-bold mb-2">Barcode / QR Scan</h1>
<p class="text-sm text-gray-500 mb-6 dark:text-gray-400">
    Point the scanner at the asset label and submit — or type the
    barcode, QR value, Asset ID, serial number or hostname manually.
</p>

<div class="bg-white p-6 rounded-xl shadow max-w-xl dark:bg-[#1d2939]">
    <form method="POST" action="{{ route('barcode.resolve') }}" class="flex flex-wrap items-center gap-2">
        @csrf
        <input type="text" name="code" value="{{ old('code') }}" required autofocus
               placeholder="Scan here…" class="border rounded-lg px-3 py-2 flex-1 font-mono dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:placeholder-gray-400">
        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-brand-500 px-6 py-2 text-theme-sm font-medium text-white shadow-theme-xs transition-all duration-200 active:scale-[0.97] hover:bg-brand-600">Find</button>
    </form>

    @error('code')
        <p class="text-red-600 text-sm mt-3 dark:text-red-400">{{ $message }}</p>
    @enderror

    <div class="text-xs text-gray-500 mt-4 dark:text-gray-400">
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
