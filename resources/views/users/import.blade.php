@extends('layouts.app')

@section('title', 'Import Users')

@section('content')
<x-page-header title="Import Users" subtitle="Match people by office email and optionally assign them to assets.">
    <x-slot:actions>
        <div class="flex flex-wrap items-center gap-2">
            <x-button variant="success" size="sm" :href="route('users.import.template')">
                <x-icon name="download" class="h-4 w-4" /> Download template
            </x-button>
            <x-button variant="secondary" size="sm" :href="route('users.index')">Back to Users</x-button>
        </div>
    </x-slot:actions>
</x-page-header>

<x-card class="mb-6">
    <form method="POST" action="{{ route('users.import.analyze') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
        @csrf
        <div class="min-w-[16rem] flex-1">
            <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Excel file</label>
            <input type="file" name="file" accept=".xlsx,.xls,.csv" required
                   class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-theme-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
        </div>
        <x-button type="submit" variant="primary" size="md"><x-icon name="search" class="h-4 w-4" /> Analyze</x-button>
    </form>
    <p class="mt-3 text-theme-xs text-gray-500 dark:text-gray-400">
        The sheet must contain <strong>Full Name</strong> and <strong>Office Email</strong>. Add <strong>Asset ID</strong> to make each user the holder of that asset. Matching is done by email, ignoring case. Nothing is written until you confirm.
    </p>
</x-card>

@if($result)
    <x-card class="mb-6" title="Import result">
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach([
                ['Created', $result['created'], 'text-success-600'],
                ['Updated', $result['updated'], 'text-brand-600'],
                ['Assigned to assets', $result['assignments'], 'text-success-600'],
                ['Skipped', $result['skipped'], 'text-error-600'],
            ] as [$label, $value, $tone])
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-800/50">
                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                    <p class="text-title-sm font-bold {{ $tone }}">{{ $value }}</p>
                </div>
            @endforeach
        </div>
        @if(!empty($result['errors']))
            <ul class="mt-3 space-y-1 text-theme-xs text-error-600 dark:text-error-400">
                @foreach($result['errors'] as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        @endif
    </x-card>
@endif

@if($analysis)
    <x-card class="mb-6" title="Preview — {{ $stagedName }}">
        <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach([
                ['Valid', $analysis['summary'][\App\Services\UserImportService::VALID], 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-300'],
                ['Will update / warn', $analysis['summary'][\App\Services\UserImportService::WARNING], 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-300'],
                ['Duplicates', $analysis['summary'][\App\Services\UserImportService::DUPLICATE], 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300'],
                ['Errors', $analysis['summary'][\App\Services\UserImportService::ERROR], 'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-300'],
            ] as [$label, $value, $classes])
                <div class="rounded-xl px-3 py-2 {{ $classes }}">
                    <p class="text-theme-xs">{{ $label }}</p>
                    <p class="text-title-sm font-bold">{{ $value }}</p>
                </div>
            @endforeach
        </div>

        <div class="mb-4 flex flex-wrap items-center gap-3 text-theme-xs text-gray-500 dark:text-gray-400">
            <span>Sheet: <strong class="text-gray-700 dark:text-gray-200">{{ $analysis['sheet'] }}</strong></span>
            <span>Mapped: {{ implode(', ', $analysis['mapped_columns']) ?: 'none' }}</span>
            @if($analysis['unmapped_columns'])
                <span class="text-warning-600 dark:text-warning-300">Ignored: {{ implode(', ', $analysis['unmapped_columns']) }}</span>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-theme-sm">
                <thead class="bg-gray-50 text-theme-xs uppercase tracking-wide text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                    <tr><th class="px-3 py-2">Row</th><th class="px-3 py-2">Full Name</th><th class="px-3 py-2">Office Email</th><th class="px-3 py-2">Asset ID</th><th class="px-3 py-2">Status</th><th class="px-3 py-2">Note</th></tr>
                </thead>
                <tbody>
                    @foreach($analysis['rows'] as $row)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="px-3 py-2 text-gray-400">{{ $row['row'] }}</td>
                            <td class="px-3 py-2 text-gray-800 dark:text-gray-200">{{ $row['name'] ?: '—' }}</td>
                            <td class="px-3 py-2 font-mono text-xs text-gray-700 dark:text-gray-300">{{ $row['email'] ?: '—' }}</td>
                            <td class="px-3 py-2 font-mono text-xs text-gray-700 dark:text-gray-300">{{ $row['asset_id'] ?: '—' }}</td>
                            <td class="px-3 py-2"><span class="rounded-full bg-gray-100 px-2 py-0.5 text-theme-xs dark:bg-gray-700">{{ $row['status'] }}</span></td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ $row['note'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-theme-xs text-gray-400">Only valid and warning rows are imported. Duplicates and errors are skipped.</p>
            <form method="POST" action="{{ route('users.import.confirm') }}" onsubmit="return confirm('Import these users now?');">
                @csrf
                <x-button type="submit" variant="primary" size="md">Confirm import</x-button>
            </form>
        </div>
    </x-card>
@endif
@endsection
