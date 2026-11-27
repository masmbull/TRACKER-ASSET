{{--
    Compact version of the template detail page, used inside the Preview modal
    on /templates. The grouping is the same as TemplateController::show() so the
    order a reader sees here is the order printed on the document.
--}}
@php
    $fields = (array) ($template->template_content ?? []);
    $groups = [];

    foreach ($fields as $key => $definition) {
        $definition = is_array($definition) ? $definition : ['label' => $definition, 'type' => 'text'];
        $section = trim((string) ($definition['section'] ?? ''));
        if ($section === '') {
            $section = 'Lainnya';
        }
        $groups[$section][] = ['key' => $key] + $definition;
    }
@endphp

<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-2">
        <x-badge variant="brand">{{ $template->document_type }}</x-badge>
        <span class="text-theme-xs text-gray-500 dark:text-gray-400">{{ count($fields) }} fields · {{ count($groups) }} sections</span>
    </div>

    @if($fields === [])
        <p class="py-8 text-center text-theme-sm text-gray-400">This template has no fields yet.</p>
    @else
        <div class="max-h-[60vh] space-y-3 overflow-y-auto pr-1">
            @foreach($groups as $section => $sectionFields)
                <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
                    <div class="flex items-center justify-between border-b border-gray-200 bg-gray-50 px-4 py-2 dark:border-gray-700 dark:bg-gray-800/60">
                        <h4 class="text-theme-sm font-semibold text-gray-800 dark:text-gray-100">{{ $section }}</h4>
                        <span class="text-theme-xs text-gray-400">{{ count($sectionFields) }}</span>
                    </div>
                    <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2">
                        @foreach($sectionFields as $field)
                            @php($type = $field['type'] ?? 'text')
                            <div>
                                <p class="mb-1 text-theme-xs font-medium text-gray-700 dark:text-gray-300">{{ $field['label'] ?? $field['key'] }}</p>
                                @if(in_array($type, ['pass_fail_na', 'ada_tidak'], true))
                                    <p class="text-theme-xs text-gray-400">
                                        {{ $type === 'pass_fail_na' ? 'PASS / FAIL / N/A' : 'Ada / Tidak Ada' }}
                                    </p>
                                @else
                                    <div class="flex h-8 items-center rounded-lg border border-dashed border-gray-300 bg-gray-50 px-3 text-theme-xs text-gray-400 dark:border-gray-700 dark:bg-gray-900/40">
                                        auto-filled
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
