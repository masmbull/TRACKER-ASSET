@extends('layouts.app')

@section('title', 'Fleet Commands')

@section('content')
@php $pendingCount = $commands->where('status', 'pending')->count(); @endphp

<x-page-header title="Fleet Command Center" subtitle="Agents poll every 10 seconds and pending commands are executed automatically.">
    <x-slot:actions>
        <div class="flex flex-wrap items-center gap-2">
            <form method="POST" action="{{ route('agent.scanAll') }}" data-modal-action>
                @csrf
                <x-button type="submit" variant="secondary" size="sm">Scan All Laptops</x-button>
            </form>
            <x-button type="button" variant="primary" size="sm" @click="$dispatch('open-modal', 'confirm-update-all-agents')">Update All Agents</x-button>
            <x-button type="button" variant="success" size="sm" @click="$dispatch('open-modal', 'push-script-modal')">Push Script</x-button>
            <form method="POST" action="{{ route('agent.sweep') }}" data-modal-action>
                @csrf
                <x-button type="submit" variant="secondary" size="sm">Sweep Now</x-button>
            </form>
        </div>
    </x-slot:actions>
</x-page-header>

<x-card class="mb-6">
    <div class="flex flex-wrap gap-2 text-theme-sm">
        <a href="{{ route('agent.index') }}" class="rounded-lg bg-gray-100 px-3 py-1.5 font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">Tokens</a>
        <a href="{{ route('agent.commands') }}" class="rounded-lg bg-brand-600 px-3 py-1.5 font-medium text-white">Commands</a>
        <a href="{{ route('agent.fleet') }}" class="rounded-lg bg-gray-100 px-3 py-1.5 font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">Live Fleet</a>
    </div>

    @if(session('success'))
        <div class="mt-4 rounded-xl border border-success-200 bg-success-50 px-4 py-3 text-theme-sm text-success-700 dark:border-success-500/20 dark:bg-success-500/10 dark:text-success-300">
            {{ session('success') }}
        </div>
    @endif
</x-card>

<x-card class="mb-6" title="Commands">
    @if($commands->count() > 0)
        <div class="overflow-x-auto">
            <x-table :headers="['ID', 'Command', 'Token', 'Status', 'Result', 'Created', 'Executed']">
                @foreach($commands as $cmd)
                    <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-white/5">
                        <td class="py-2 px-2 text-theme-sm text-gray-600 dark:text-gray-400">#{{ $cmd->id }}</td>
                        <td class="py-2 px-2"><span class="rounded bg-gray-100 px-2 py-1 text-theme-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300">{{ $cmd->command }}</span></td>
                        <td class="py-2 px-2 text-theme-sm text-gray-600 dark:text-gray-400">{{ $cmd->token?->name ?? 'All' }}</td>
                        <td class="py-2 px-2">
                            @php
                                $statusColors = ['pending' => 'bg-warning-100 text-warning-700 dark:bg-warning-500/10 dark:text-warning-300', 'done' => 'bg-success-100 text-success-700 dark:bg-success-500/10 dark:text-success-300', 'failed' => 'bg-error-100 text-error-700 dark:bg-error-500/10 dark:text-error-300'];
                            @endphp
                            <span class="rounded px-2 py-1 text-theme-xs font-medium {{ $statusColors[$cmd->status] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300' }}">{{ $cmd->status }}</span>
                        </td>
                        <td class="py-2 px-2 text-theme-sm text-gray-600 dark:text-gray-400">{{ $cmd->result ? json_encode($cmd->result) : '—' }}</td>
                        <td class="py-2 px-2 text-theme-sm text-gray-600 dark:text-gray-400">{{ $cmd->created_at->diffForHumans() }}</td>
                        <td class="py-2 px-2 text-theme-sm text-gray-600 dark:text-gray-400">{{ $cmd->executed_at?->diffForHumans() ?? '—' }}</td>
                    </tr>
                @endforeach
            </x-table>
        </div>
    @else
        <p class="py-8 text-center text-theme-sm text-gray-400">No commands yet.</p>
    @endif
</x-card>

<x-card title="Agent Status">
    <p class="mb-4 text-theme-sm text-gray-500 dark:text-gray-400">Online = last poll within 60 seconds. Offline = stale or not running.</p>

    <x-table :headers="['Name', 'Last Poll', 'Status', 'Action']">
        @foreach($tokens as $token)
            @php $online = $token->last_used_at && $token->last_used_at->gt(now()->subSeconds(90)); @endphp
            <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-white/5">
                <td class="py-2 px-2 text-theme-sm text-gray-700 dark:text-gray-300">{{ $token->name }}</td>
                <td class="py-2 px-2 text-theme-sm text-gray-600 dark:text-gray-400">{{ $token->last_used_at ? $token->last_used_at->diffForHumans() : 'never' }}</td>
                <td class="py-2 px-2 text-theme-sm text-gray-600 dark:text-gray-400">
                    @if($online)
                        <span class="mr-1 inline-block h-2 w-2 rounded-full bg-success-500"></span>Online
                    @else
                        <span class="mr-1 inline-block h-2 w-2 rounded-full bg-gray-400"></span>Offline
                    @endif
                </td>
                <td class="py-2 px-2">
                    @if($token->isUsable())
                        <form method="POST" action="{{ route('agent.scan', $token) }}" class="inline" data-modal-action>
                            @csrf
                            <button type="submit" class="text-theme-sm font-medium text-brand-500 hover:text-brand-600">Scan Now</button>
                        </form>
                    @else
                        <span class="text-theme-sm text-gray-400">revoked</span>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-table>
</x-card>

@if($pendingCount > 0)
    <script>
        setTimeout(function () { window.location.reload(); }, 10000);
    </script>
@endif

<x-modal id="confirm-update-all-agents" title="Update all agents" maxWidth="md">
    <div class="space-y-4">
        <p class="text-theme-sm text-gray-600 dark:text-gray-300">Outdated agents will download the update and restart. Devices already on the current release are skipped.</p>
        <form method="POST" action="{{ route('agent.updateAll') }}" class="flex justify-end gap-3" data-modal-action data-close-modal="confirm-update-all-agents">
            @csrf
            <button type="button" @click="$dispatch('close-modal', 'confirm-update-all-agents')" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</button>
            <x-button type="submit" variant="primary">Update agents</x-button>
        </form>
    </div>
</x-modal>

<x-modal id="push-script-modal" title="Push script to devices" maxWidth="2xl">
    <form method="POST" action="{{ route('agent.commands.script') }}" class="space-y-5" x-data="{
        target: 'all',
        scriptType: 'powershell',
        sample: 'Write-Host \"Starting deployment...\"; Start-Sleep -Seconds 5; Write-Host \"Done\";'
    }">
        @csrf

        <div class="grid gap-4 md:grid-cols-2">
            <div class="md:col-span-2">
                <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Target</label>
                <select name="target" x-model="target" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    <option value="all">All laptops</option>
                    <option value="device">Specific laptop</option>
                    <option value="devices">Multiple laptops</option>
                </select>
            </div>

            <div x-show="target === 'device'" class="md:col-span-2">
                <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Laptop</label>
                <select name="agent_token_id" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    <option value="">Select device</option>
                    @foreach($tokens as $token)
                        @if($token->isUsable())
                            <option value="{{ $token->id }}">{{ $token->name }}</option>
                        @endif
                    @endforeach
                </select>
            </div>

            <div x-show="target === 'devices'" class="md:col-span-2">
                <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Selected laptops</label>
                <select name="agent_token_ids[]" multiple size="8" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    @foreach($tokens as $token)
                        @if($token->isUsable())
                            <option value="{{ $token->id }}">{{ $token->name }}</option>
                        @endif
                    @endforeach
                </select>
            </div>

            <div>
                <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Script file name</label>
                <input type="text" name="script_name" value="setup-agent-task.ps1" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" placeholder="e.g. install-app.ps1">
            </div>

            <div>
                <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Script type</label>
                <select name="script_type" x-model="scriptType" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    <option value="powershell">PowerShell (.ps1)</option>
                    <option value="cmd">CMD (.cmd)</option>
                    <option value="bat">Batch (.bat)</option>
                    <option value="exe">Executable</option>
                </select>
            </div>

            <div>
                <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Run as</label>
                <select name="run_as" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    <option value="current-user">Current user</option>
                    <option value="admin">Administrator</option>
                    <option value="system">System</option>
                </select>
            </div>

            <div>
                <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Timeout (seconds)</label>
                <input type="number" name="timeout_seconds" value="300" min="10" max="3600" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
            </div>

            <div>
                <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">SHA-256</label>
                <input type="text" name="sha256" value="" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" placeholder="Optional: verify payload integrity">
            </div>

            <div class="md:col-span-2">
                <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Working directory</label>
                <input type="text" name="working_directory" value="C:\\Temp\\Scripts" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" placeholder="C:\Temp\Scripts">
            </div>

            <div class="md:col-span-2">
                <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Trigger</label>
                <select name="trigger" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    <option value="once">Run once</option>
                    <option value="reboot">Run at next reboot</option>
                </select>
            </div>

            <div class="md:col-span-2">
                <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Script body</label>
                <textarea name="script_body" x-model="sample" rows="10" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 font-mono text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" placeholder="Write-Host \"Deploying...\"">Write-Host "Deploying..."; Start-Sleep -Seconds 5; Write-Host "Done";</textarea>
            </div>

            <div class="md:col-span-2">
                <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                <textarea name="notes" rows="3" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" placeholder="Optional: why this script is being pushed, when to run it, or instructions for the operator."></textarea>
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <button type="button" @click="$dispatch('close-modal', 'push-script-modal')" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</button>
            <x-button type="submit" variant="primary">Queue script</x-button>
        </div>
    </form>
</x-modal>
@endsection
