@extends('layouts.app')

@section('title', 'Import Assets')

@section('content')
<div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-bold">Import Assets from Excel</h1>
    <a href="{{ route('assets.index') }}" class="bg-gray-300 text-gray-700 px-4 py-2 rounded">Back to Assets</a>
</div>

@if($result)
<div class="bg-white p-6 rounded shadow mb-6">
    <h2 class="text-lg font-semibold mb-3">Import result — {{ $result['file'] ?? '' }}</h2>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
        <div class="border rounded p-3">
            <p class="text-xs text-gray-500">Created</p>
            <p class="text-xl font-bold text-green-700">{{ $result['created'] }}</p>
        </div>
        <div class="border rounded p-3">
            <p class="text-xs text-gray-500">Skipped</p>
            <p class="text-xl font-bold text-gray-700">{{ $result['skipped'] }}</p>
        </div>
        <div class="border rounded p-3">
            <p class="text-xs text-gray-500">Assignment rows</p>
            <p class="text-xl font-bold">{{ $result['assignment_rows'] }}</p>
        </div>
        <div class="border rounded p-3">
            <p class="text-xs text-gray-500">Placeholder users</p>
            <p class="text-xl font-bold">{{ $result['placeholder_users'] }}</p>
        </div>
    </div>
    @if(!empty($result['errors']))
        <p class="font-medium text-red-700 mb-1">Row errors</p>
        <ul class="list-disc ml-6 text-red-600 text-sm">
            @foreach($result['errors'] as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif
</div>
@endif

<div class="bg-white p-6 rounded shadow mb-6">
    <h2 class="text-lg font-semibold mb-4">1. Upload file</h2>

    <form method="POST" action="{{ route('assets.import.analyze') }}" enctype="multipart/form-data" class="flex flex-wrap gap-3 items-end">
        @csrf
        <div>
            <label class="block text-sm font-medium">Excel file (.xlsx, .xls, .csv) *</label>
            <input type="file" name="file" accept=".xlsx,.xls,.csv" required class="border rounded px-3 py-2">
        </div>
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Analyse / Preview</button>
        @if($stagedName)
            <span class="text-sm text-gray-500">Staged file: {{ $stagedName }}</span>
        @endif
    </form>

    @error('file')
        <p class="text-red-600 text-sm mt-2">{{ $message }}</p>
    @enderror

    <p class="text-xs text-gray-500 mt-3">
        Nothing is written to the database until you confirm the preview.
        Existing assets are never modified or deleted — duplicates are skipped.
    </p>
</div>

@if($analysis)
<div class="bg-white p-6 rounded shadow mb-6">
    <h2 class="text-lg font-semibold mb-4">2. Validation preview — sheet "{{ $analysis['sheet'] }}"</h2>

    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-4">
        <div class="border rounded p-3">
            <p class="text-xs text-gray-500">Rows detected</p>
            <p class="text-xl font-bold">{{ $analysis['summary']['total'] }}</p>
        </div>
        <div class="border rounded p-3">
            <p class="text-xs text-gray-500">Valid</p>
            <p class="text-xl font-bold text-green-700">{{ $analysis['summary']['valid'] }}</p>
        </div>
        <div class="border rounded p-3">
            <p class="text-xs text-gray-500">Warning</p>
            <p class="text-xl font-bold text-yellow-600">{{ $analysis['summary']['warning'] }}</p>
        </div>
        <div class="border rounded p-3">
            <p class="text-xs text-gray-500">Error</p>
            <p class="text-xl font-bold text-red-700">{{ $analysis['summary']['error'] }}</p>
        </div>
        <div class="border rounded p-3">
            <p class="text-xs text-gray-500">Duplicate</p>
            <p class="text-xl font-bold text-orange-600">{{ $analysis['summary']['duplicate'] }}</p>
        </div>
    </div>

    <p class="text-sm text-gray-600 mb-1">
        <strong>Mapped columns ({{ count($analysis['mapped_columns']) }}):</strong>
        {{ implode(', ', $analysis['mapped_columns']) }}
    </p>

    @if(!empty($analysis['unmapped_columns']))
        <p class="text-sm text-gray-500 mb-4">
            <strong>Ignored columns:</strong> {{ implode(', ', $analysis['unmapped_columns']) }}
        </p>
    @endif

    <p class="text-sm text-gray-600 mb-2">
        Previewing the first {{ min(count($analysis['rows']), (int) config('asset-import.preview_limit', 50)) }} rows.
    </p>

    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b bg-gray-50">
                    <th class="text-left py-2 px-2">Row</th>
                    <th class="text-left py-2 px-2">Status</th>
                    <th class="text-left py-2 px-2">Asset ID</th>
                    <th class="text-left py-2 px-2">Serial</th>
                    <th class="text-left py-2 px-2">Hostname</th>
                    <th class="text-left py-2 px-2">Category</th>
                    <th class="text-left py-2 px-2">Company</th>
                    <th class="text-left py-2 px-2">Issues</th>
                </tr>
            </thead>
            <tbody>
                @foreach(array_slice($analysis['rows'], 0, (int) config('asset-import.preview_limit', 50)) as $row)
                    <tr class="border-b align-top">
                        <td class="py-2 px-2">{{ $row['row'] }}</td>
                        <td class="py-2 px-2">
                            @php
                                $badge = [
                                    'valid' => 'bg-green-100 text-green-800',
                                    'warning' => 'bg-yellow-100 text-yellow-800',
                                    'error' => 'bg-red-100 text-red-800',
                                    'duplicate' => 'bg-orange-100 text-orange-800',
                                ][$row['status']] ?? 'bg-gray-100';
                            @endphp
                            <span class="px-2 py-1 text-xs rounded {{ $badge }}">{{ $row['status'] }}</span>
                        </td>
                        <td class="py-2 px-2">{{ $row['data']['asset_id'] ?? '' }}</td>
                        <td class="py-2 px-2">{{ $row['data']['serial_number'] ?? '' }}</td>
                        <td class="py-2 px-2">{{ $row['data']['hostname'] ?? '' }}</td>
                        <td class="py-2 px-2">{{ $row['data']['category_name'] ?? '' }}</td>
                        <td class="py-2 px-2">{{ $row['data']['company_name'] ?? '' }}</td>
                        <td class="py-2 px-2">
                            @if($row['issues'])
                                <ul class="list-disc ml-4 text-xs text-gray-700">
                                    @foreach($row['issues'] as $issue)
                                        <li>{{ $issue }}</li>
                                    @endforeach
                                </ul>
                            @else
                                <span class="text-xs text-green-700">OK</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="bg-white p-6 rounded shadow mb-6">
    <h2 class="text-lg font-semibold mb-3">3. Confirm import</h2>
    <p class="text-sm text-gray-600 mb-4">
        {{ $analysis['summary']['valid'] + $analysis['summary']['warning'] }} rows will be created,
        {{ $analysis['summary']['duplicate'] + $analysis['summary']['error'] }} rows will be skipped
        (duplicates and errors are never imported or overwritten).
    </p>
    <form method="POST" action="{{ route('assets.import.confirm') }}" onsubmit="return confirm('Import the valid rows now?')">
        @csrf
        <button type="submit" class="bg-green-600 text-white px-6 py-2 rounded">Confirm Import</button>
    </form>
</div>
@endif
@endsection
