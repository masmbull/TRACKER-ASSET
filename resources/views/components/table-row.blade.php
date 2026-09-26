@props(['hover' => true])
<tr {{ $attributes->class(['border-b border-gray-100 dark:border-gray-800', 'hover:bg-gray-50 dark:hover:bg-white/[0.02] transition-colors' => $hover]) }}>
    {{ $slot }}
</tr>
