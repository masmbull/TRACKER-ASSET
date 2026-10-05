@extends('layouts.app')

@section('title', 'Add '.$token->name.' to the register')
@section('header', 'Add to Register')

@section('content')
<div class="mx-auto max-w-6xl space-y-6">
    <x-page-header
        title="Add this device to the register"
        subtitle="The scan filled in what the hardware reports. The rest is what only a person knows.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" :href="route('agent.fleet')">Back to Fleet</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- Why this device is not on the register yet. Without this the page looks
         like ordinary asset creation, and the operator has no way to tell that
         the serial below came from a scan rather than from a purchase record. --}}
    <div class="rounded-lg border border-warning-200 bg-warning-50 p-4 dark:border-warning-500/30 dark:bg-warning-500/10">
        <p class="text-theme-sm font-medium text-warning-800 dark:text-warning-300">
            {{ $token->name }} scanned {{ $scan?->audit_date?->diffForHumans() ?? 'recently' }} and its serial
            was not on the register, so no asset was created.
        </p>
        <p class="mt-1 text-theme-xs text-warning-700 dark:text-warning-300/80">
            Nothing was guessed for you. Company, category, location and holder are yours to choose —
            those are the fields the auto-created rows used to get wrong.
        </p>
    </div>

    <form method="POST" action="{{ route('agent.promote.store', $token) }}" class="space-y-6">
        @csrf

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            {{-- What the machine said about itself. Read-only on purpose: these
                 are evidence, and letting them be edited here would quietly undo
                 the point of scanning in the first place. --}}
            <x-card title="What the device reported" class="lg:col-span-1">
                <dl class="space-y-2.5 text-theme-sm">
                    @foreach([
                        'Hostname' => $scan?->device_hostname ?? $telemetry?->hostname,
                        'Serial number' => $scan?->device_serial,
                        'Brand' => $scan?->device_brand ?? $fingerprint?->device_manufacturer,
                        'Model' => $scan?->device_model ?? $fingerprint?->device_model,
                        'Processor' => $scan?->device_processor,
                        'RAM' => $scan?->device_ram ?? $telemetry?->ram_config,
                        'Storage' => $scan?->device_storage,
                        'Operating system' => $scan?->device_os ?? $telemetry?->os,
                        'MAC address' => $scan?->device_mac,
                        'IP address' => $telemetry?->ip_address,
                        'Logged-in user' => $telemetry?->logged_in_user,
                    ] as $label => $value)
                        <div class="flex justify-between gap-3">
                            <dt class="shrink-0 text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                            <dd class="text-right font-medium text-gray-700 dark:text-gray-200">{{ $value ?: '—' }}</dd>
                        </div>
                    @endforeach
                </dl>

                @if($fingerprint?->findings())
                    <div class="mt-4 border-t border-gray-200 pt-3 dark:border-gray-700">
                        <p class="mb-2 text-theme-xs font-medium text-gray-500 dark:text-gray-400">Security findings</p>
                        <div class="flex flex-wrap gap-1">
                            @foreach($fingerprint->findings() as $finding)
                                <x-badge :variant="$finding['severity'] === 'danger' ? 'error' : 'warning'">{{ $finding['label'] }}</x-badge>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-card>

            <div class="space-y-6 lg:col-span-2">
                <x-card title="Register details">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        @include('agent.partials.promote-fields')
                    </div>
                </x-card>

                <x-card title="Purchase details (optional)">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        @include('agent.partials.promote-purchase')
                    </div>
                </x-card>

                <div class="flex justify-end gap-3">
                    <x-button variant="secondary" :href="route('agent.fleet')">Cancel</x-button>
                    <x-button type="submit">Add to register</x-button>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection