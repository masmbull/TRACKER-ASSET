@extends('layouts.app')

@section('title', 'Audit Agents')

@section('content')
<x-page-header title="Audit Agents" subtitle="Download and deploy the audit agent to devices. The agent runs silently in the background and sends hardware scans automatically.">
    <x-slot:actions>
        <a href="{{ route('agent.download.latest') }}" class="inline-flex items-center gap-2 bg-brand-500 hover:bg-brand-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
            Download Agent
        </a>
    </x-slot:actions>
</x-page-header>

<x-card class="mb-6">
    <div class="flex flex-wrap gap-2 text-theme-sm">
        <a href="{{ route('agent.index') }}" class="rounded-lg bg-brand-600 px-3 py-1.5 font-medium text-white">Tokens</a>
        <a href="{{ route('agent.releases') }}" class="rounded-lg bg-gray-100 px-3 py-1.5 font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">Releases</a>
        <a href="{{ route('agent.commands') }}" class="rounded-lg bg-gray-100 px-3 py-1.5 font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">Commands</a>
        <a href="{{ route('agent.fleet') }}" class="rounded-lg bg-gray-100 px-3 py-1.5 font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">Live Fleet</a>
    </div>
</x-card>

{{-- Which build the download button is serving, and what it replaced.
     Without this the page says "latest" and means nothing verifiable: two
     different files carry that label, and nothing on screen distinguishes a
     fleet that took the update from one that never will. --}}
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <x-card title="Release Version" class="border-brand-200 dark:border-brand-800">
        <p class="text-2xl font-semibold text-gray-900 dark:text-white">
            {{ $currentRelease?->version ?? 'not recorded' }}
        </p>
        <p class="mt-1 text-theme-xs text-gray-500">
            {{ $currentRelease?->released_at?->diffForHumans() ?? 'Register one with: php artisan agent:release <version>' }}
        </p>
    </x-card>

    <x-card title="Previous Version">
        <p class="text-2xl font-semibold text-gray-500">
            {{ $previousRelease?->version ?? '—' }}
        </p>
        <p class="mt-1 text-theme-xs text-gray-500">
            {{ $previousRelease?->released_at?->diffForHumans() ?? 'No earlier release on record' }}
        </p>
    </x-card>

    <x-card title="Fleet Status">
        @php
            $outdated = $tokens->filter(fn ($t) => $t->isOutdated() === true)->count();
            $unknown = $tokens->filter(fn ($t) => blank($t->agent_version))->count();
        @endphp
        <p class="text-2xl font-semibold {{ $outdated > 0 ? 'text-warning-600' : 'text-success-600' }}">
            {{ $outdated }}<span class="text-theme-sm font-normal text-gray-500"> outdated</span>
        </p>
        <p class="mt-1 text-theme-xs text-gray-500">{{ $unknown }} reporting no version</p>
    </x-card>
</div>

<div class="grid grid-cols-1 gap-6">
    <x-card title="How to Deploy" padding="p-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div class="flex gap-4">
                <div class="flex-shrink-0 w-8 h-8 rounded-full bg-brand-500 text-white flex items-center justify-center text-sm font-bold">1</div>
                <div>
                    <h3 class="font-semibold text-gray-900 dark:text-white mb-1">Download Agent</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Click the button above to download the latest audit agent. A unique token is generated automatically.</p>
                </div>
            </div>
            <div class="flex gap-4">
                <div class="flex-shrink-0 w-8 h-8 rounded-full bg-brand-500 text-white flex items-center justify-center text-sm font-bold">2</div>
                <div>
                    <h3 class="font-semibold text-gray-900 dark:text-white mb-1">Install on Device</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Run <code class="bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 rounded text-xs">TrackerAgent.exe</code> on the device. Zero configuration: the download already carries this server's address, so the installer auto-detects it and registers the device on its own.</p>
                </div>
            </div>
            <div class="flex gap-4">
                <div class="flex-shrink-0 w-8 h-8 rounded-full bg-brand-500 text-white flex items-center justify-center text-sm font-bold">3</div>
                <div>
                    <h3 class="font-semibold text-gray-900 dark:text-white mb-1">Agent Runs in Background</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">After installation, the agent runs silently in the background. It will be detected by serial number automatically.</p>
                </div>
            </div>
        </div>
    </x-card>

    <x-card title="Deployed Devices" padding="p-0">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
                        <th class="text-left py-3 px-4 font-semibold text-gray-600 dark:text-gray-400">Device</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-600 dark:text-gray-400">Agent Version</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-600 dark:text-gray-400">Status</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-600 dark:text-gray-400">Last Seen</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-600 dark:text-gray-400">Expires</th>
                        <th class="text-right py-3 px-4 font-semibold text-gray-600 dark:text-gray-400">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                    @forelse($tokens as $token)
                        <tr class="hover:bg-gray-50 dark:hover:bg-white/5 transition-colors">
                            <td class="py-3 px-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-lg bg-brand-500/10 dark:bg-brand-500/20 flex items-center justify-center text-brand-500">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                    </div>
                                    <span class="font-medium text-gray-900 dark:text-white">{{ $token->name }}</span>
                                    @if($token->device_id)
                                        <span class="block text-theme-xs text-gray-400">{{ $token->device_id }}</span>
                                    @endif
                                </div>
                            </td>
                            {{-- The build this laptop is actually running, next to the
                                 build we serve. "unknown" is its own state: every
                                 device enrolled before agents reported a version
                                 reads that, and painting it as up to date would be
                                 a claim nobody can check. --}}
                            <td class="py-3 px-4">
                                @php $outdated = $token->isOutdated(); @endphp
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium
                                    @if($outdated === true)
                                        bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400
                                    @elseif($outdated === false)
                                        bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400
                                    @else
                                        bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400
                                    @endif">
                                    {{ $token->versionLabel() }}
                                    @if($outdated === true)
                                        <span class="font-normal">· outdated</span>
                                    @endif
                                </span>
                            </td>
                            <td class="py-3 px-4">
                                @if($token->isUsable())
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400">
                                        <span class="w-1.5 h-1.5 rounded-full bg-success-500"></span>
                                        Active
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-400">
                                        <span class="w-1.5 h-1.5 rounded-full bg-error-500"></span>
                                        Revoked / Expired
                                    </span>
                                @endif
                            </td>
                            <td class="py-3 px-4 text-gray-600 dark:text-gray-400">
                                {{ $token->last_used_at?->diffForHumans() ?? 'Never' }}
                            </td>
                            <td class="py-3 px-4 text-gray-600 dark:text-gray-400">
                                {{ $token->expires_at?->format('Y-m-d') ?? 'Never' }}
                            </td>
                            <td class="py-3 px-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    @if($token->isUsable())
                                        <a href="{{ route('agent.download', $token) }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-brand-500 text-white hover:bg-brand-600 transition-colors">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                            Download
                                        </a>
                                    @else
                                        <span class="text-xs text-gray-400">Inactive</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-8 px-4 text-center text-gray-500 dark:text-gray-400">
                                No devices yet. Click <strong>Download Agent</strong> above to get started.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>
@endsection