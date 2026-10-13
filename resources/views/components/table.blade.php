@props(['headers' => [], 'emptyText' => 'No data found.', 'striped' => false, 'minWidth' => null])
<div {{ $attributes->class(['w-full overflow-x-auto rounded-xl']) }}>
    <table class="w-full border-collapse" @if($minWidth) style="min-width: {{ $minWidth }}" @endif>
        <thead class="sticky top-0 z-10">
            <tr class="border-b border-gray-100 bg-gray-50/80 backdrop-blur dark:border-gray-800 dark:bg-gray-900/80">
                @foreach($headers as $header)
                <th class="text-left py-3 px-4 text-theme-xs font-semibold text-gray-500 uppercase tracking-wider dark:text-gray-400">{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            @if(isset($rows) && count($rows) > 0)
                {{ $rows }}
            @elseif($slot->isNotEmpty())
                {{ $slot }}
            @else
            <tr>
                <td colspan="{{ count($headers) }}" class="py-12 text-center text-theme-sm text-gray-400">{{ $emptyText }}</td>
            </tr>
            @endif
        </tbody>
    </table>
</div>
