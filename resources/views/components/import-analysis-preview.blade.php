<div class="mb-6 rounded-2xl border border-gray-100 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
    <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800">
        <h3 class="text-lg font-semibold text-gray-800 dark:text-white">2. Validation preview — sheet "{{ $analysis['sheet'] }}"</h3>
    </div>
    <div class="p-6">
        <div class="mb-4 grid grid-cols-2 gap-4 md:grid-cols-5">
            <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">Rows detected</p>
                <p class="text-title-sm font-bold text-gray-800 dark:text-white/90">{{ $analysis['summary']['total'] }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">Valid</p>
                <p class="text-title-sm font-bold text-success-600">{{ $analysis['summary']['valid'] }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">Warning</p>
                <p class="text-title-sm font-bold text-warning-600">{{ $analysis['summary']['warning'] }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">Error</p>
                <p class="text-title-sm font-bold text-error-600">{{ $analysis['summary']['error'] }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">Duplicate</p>
                <p class="text-title-sm font-bold text-gray-700">{{ $analysis['summary']['duplicate'] }}</p>
            </div>
        </div>

        <p class="mb-1 text-theme-sm text-gray-600 dark:text-gray-300">
            <strong>Mapped columns ({{ count($analysis['mapped_columns']) }}):</strong>
            {{ implode(', ', $analysis['mapped_columns']) }}
        </p>

        @if(!empty($analysis['unmapped_columns']))
            <p class="mb-4 text-theme-sm text-gray-500 dark:text-gray-400">
                <strong>Ignored columns:</strong> {{ implode(', ', $analysis['unmapped_columns']) }}
            </p>
        @endif

        <p class="mb-2 text-theme-sm text-gray-600 dark:text-gray-300">
            Previewing the first {{ min(count($analysis['rows']), (int) config('asset-import.preview_limit', 50)) }} rows.
        </p>

        <div class="overflow-x-auto">
            <div class="w-full overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            @foreach(['Row', 'Status', 'Asset ID', 'Serial', 'Hostname', 'Category', 'Company', 'Issues'] as $header)
                                <th class="text-left py-3 px-4 text-theme-xs font-semibold text-gray-500 uppercase tracking-wider dark:text-gray-400">{{ $header }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(array_slice($analysis['rows'], 0, (int) config('asset-import.preview_limit', 50)) as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800 align-top">
                                <td class="py-2 px-2 text-theme-sm text-gray-600 dark:text-gray-400">{{ $row['row'] }}</td>
                                <td class="py-2 px-2">
                                    @php
                                        $badge = [
                                            'valid' => 'bg-success-100 text-success-700 dark:bg-success-500/10 dark:text-success-300',
                                            'warning' => 'bg-warning-100 text-warning-700 dark:bg-warning-500/10 dark:text-warning-300',
                                            'error' => 'bg-error-100 text-error-700 dark:bg-error-500/10 dark:text-error-300',
                                            'duplicate' => 'bg-gray-100 text-gray-700 dark:bg-gray-500/10 dark:text-gray-300',
                                        ][$row['status']] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300';
                                    @endphp
                                    <span class="rounded px-2 py-1 text-theme-xs font-medium {{ $badge }}">{{ $row['status'] }}</span>
                                </td>
                                <td class="py-2 px-2 text-theme-sm text-gray-600 dark:text-gray-400">{{ $row['data']['asset_id'] ?? '' }}</td>
                                <td class="py-2 px-2 text-theme-sm text-gray-600 dark:text-gray-400">{{ $row['data']['serial_number'] ?? '' }}</td>
                                <td class="py-2 px-2 text-theme-sm text-gray-600 dark:text-gray-400">{{ $row['data']['hostname'] ?? '' }}</td>
                                <td class="py-2 px-2 text-theme-sm text-gray-600 dark:text-gray-400">{{ $row['data']['category_name'] ?? '' }}</td>
                                <td class="py-2 px-2 text-theme-sm text-gray-600 dark:text-gray-400">{{ $row['data']['company_name'] ?? '' }}</td>
                                <td class="py-2 px-2">
                                    @if($row['issues'])
                                        <ul class="ml-4 list-disc text-theme-xs text-gray-700 dark:text-gray-300">
                                            @foreach($row['issues'] as $issue)
                                                <li>{{ $issue }}</li>
                                            @endforeach
                                        </ul>
                                    @else
                                        <span class="text-theme-xs text-success-600">OK</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="mb-6 rounded-2xl border border-gray-100 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
    <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800">
        <h3 class="text-lg font-semibold text-gray-800 dark:text-white">3. Confirm import</h3>
    </div>
    <div class="p-6">
        <p class="mb-4 text-theme-sm text-gray-600 dark:text-gray-300">
            {{ $analysis['summary']['valid'] + $analysis['summary']['warning'] }} rows will be created,
            {{ $analysis['summary']['duplicate'] + $analysis['summary']['error'] }} rows will be skipped
            (duplicates and errors are never imported or overwritten).
        </p>

        <button type="button" @click="$dispatch('open-modal', 'confirm-import-modal')" class="inline-flex items-center justify-center rounded-lg bg-success-600 px-6 py-2.5 text-theme-sm font-medium text-white hover:bg-success-700">
            Confirm Import
        </button>
    </div>
</div>

<div x-data="{ open: false }"
     x-on:open-modal.window="$event.detail === 'confirm-import-modal' ? open = true : null"
     x-on:close-modal.window="$event.detail === 'confirm-import-modal' ? open = false : null"
     x-on:keydown.escape.window="open = false"
     x-show="open" x-cloak
     class="fixed inset-0 z-[99999] overflow-y-auto">

    <div x-show="open" x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="fixed inset-0 bg-gray-900/50 backdrop-blur-sm" x-on:click="open = false"></div>

    <div class="flex min-h-full items-center justify-center p-4">
        <div x-show="open" x-cloak
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
             class="relative w-full bg-white rounded-2xl shadow-theme-lg transform dark:bg-gray-900 max-w-md">
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-800">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-white">Confirm import</h3>
                <button @click="open = false" class="text-gray-400 hover:text-gray-600">
                    <svg class="fill-current" width="24" height="24" viewBox="0 0 24 24"><path fill-rule="evenodd" clip-rule="evenodd" d="M6.21967 7.28131C5.92678 6.98841 5.92678 6.51354 6.21967 6.22065C6.51256 5.92775 6.98744 5.92775 7.28033 6.22065L11.999 10.9393L16.7176 6.22078C17.0105 5.92789 17.4854 5.92788 17.7782 6.22078C18.0711 6.51367 18.0711 6.98855 17.7782 7.28144L13.0597 12L17.7782 16.7186C18.0711 17.0115 18.0711 17.4863 17.7782 17.7792C17.4854 18.0721 17.0105 18.0721 16.7176 17.7792L11.999 13.0607L7.28033 17.7794C6.98744 18.0722 6.51256 18.0722 6.21967 17.7794C5.92678 17.4865 5.92678 17.0116 6.21967 16.7187L10.9384 12L6.21967 7.28131Z"/></svg>
                </button>
            </div>
            <div class="px-6 py-4 space-y-4">
                <p class="text-theme-sm text-gray-600 dark:text-gray-300">
                    Import the valid rows now? This action writes the staged records into the inventory database.
                </p>

                <form method="POST" action="{{ route('assets.import.confirm') }}" class="space-y-4">
                    @csrf

                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" @click="$dispatch('close-modal', 'confirm-import-modal')" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</button>
                        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-success-600 px-4 py-2 text-theme-sm font-medium text-white hover:bg-success-700">Import Now</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>