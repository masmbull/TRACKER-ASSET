@props(['align' => 'left'])
<td {{ $attributes->class(['py-3 px-4 text-theme-sm', 'text-gray-700 dark:text-gray-400' => true, 'text-right' => $align === 'right', 'text-center' => $align === 'center']) }}>
    {{ $slot }}
</td>
