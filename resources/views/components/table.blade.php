@props(['headers' => [], 'emptyText' => 'No data found.', 'striped' => false, 'minWidth' => null])
<div {{ $attributes->class(['w-full overflow-x-auto']) }}>
    <table class="w-full" @if($minWidth) style="min-width: {{ $minWidth }}" @endif>
        <thead>
            <tr class="border-b border-gray-100 dark:border-gray-800">
                @foreach($headers as $header)
                <th class="text-left py-3 px-4 text-theme-xs font-semibold text-gray-500 uppercase tracking-wider dark:text-gray-400">{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
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
