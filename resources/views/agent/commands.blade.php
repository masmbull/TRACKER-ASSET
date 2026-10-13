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
    <form method="POST" action="{{ route('agent.commands.script') }}" class="space-y-5"
          x-data="{
              target: 'all',
              body: '',
              runAs: 'current-user',
              timeout: 300,
              trigger: 'once',
              workingDir: 'C:\\\\Temp\\\\Scripts',
              notes: '',
              deviceSearch: '',
              selected: [],
              devices: @js($tokens->filter(fn ($t) => $t->isUsable())->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'host' => $t->telemetry?->hostname, 'online' => (bool) ($t->last_used_at && $t->last_used_at->gt(now()->subSeconds(90)))])->values()),
              get filteredDevices() {
                  const q = this.deviceSearch.trim().toLowerCase();
                  if (!q) return this.devices;
                  return this.devices.filter(d =>
                      d.name.toLowerCase().includes(q) || (d.host || '').toLowerCase().includes(q));
              },
              get selectedDevices() {
                  return this.devices.filter(d => this.selected.includes(d.id));
              },
              toggle(id) {
                  const i = this.selected.indexOf(id);
                  if (i === -1) { this.selected.push(id); } else { this.selected.splice(i, 1); }
              },
              detectType() {
                  const b = this.body || '';
                  if (/[\u0000-\u0008\u000E-\u001F]/.test(b)) return 'binary';
                  const ps = this.psNeedles.filter(k => b.toLowerCase().includes(k)).length;
                  const cmd = this.cmdNeedles.filter(k => b.toLowerCase().includes(k)).length;
                  if (ps === 0 && cmd === 0) return 'powershell';
                  return ps >= cmd ? 'powershell' : 'cmd';
              },
              psNeedles: @js(\App\Services\ScriptPayload::POWERSHELL_NEEDLES),
              cmdNeedles: @js(\App\Services\ScriptPayload::CMD_NEEDLES),
              get detectedType() { return this.detectType(); },
              get fileName() { return this.detectedType === 'cmd' ? 'tracker-script.bat' : 'tracker-script.ps1'; },
              get targetSummary() {
                  if (this.target === 'all') return 'Every enrolled laptop';
                  if (this.target === 'device') return this.selectedDevices[0]?.name || 'No laptop selected';
                  return this.selected.length ? this.selected.length + ' laptop(s) selected' : 'No laptops selected';
              }
          }"
          x-init="$watch('target', () => { if (target !== 'devices') { selected = []; } })">
        @csrf

        {{-- Script body is the single input. Everything else - file type, file
             name, SHA-256 - is derived from what is pasted here, so there is no
             second field to disagree with the first. --}}
        <div>
            <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2">
                <label class="text-theme-sm font-medium text-gray-700 dark:text-gray-300">Script</label>
                <span x-show="body.trim().length > 0" x-cloak class="flex items-center gap-2">
                    <template x-if="detectedType === 'binary'">
                        <span class="rounded-full bg-error-50 px-2.5 py-0.5 text-theme-xs font-medium text-error-700 dark:bg-error-500/10 dark:text-error-300">binary — not allowed</span>
                    </template>
                    <template x-if="detectedType !== 'binary'">
                        <span class="flex items-center gap-2">
                            <span class="rounded-full bg-brand-50 px-2.5 py-0.5 text-theme-xs font-medium text-brand-600 dark:bg-brand-500/10 dark:text-brand-300" x-text="detectedType === 'powershell' ? 'PowerShell' : 'CMD / Batch'"></span>
                            <span class="font-mono text-theme-xs text-gray-400" x-text="fileName"></span>
                        </span>
                    </template>
                </span>
            </div>
            <textarea name="script_body" x-model="body" rows="12" required
                      class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 font-mono text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
                      placeholder="Paste the script here. The type and its SHA-256 are detected automatically."></textarea>
            <p class="mt-1.5 text-theme-xs text-gray-400">The agent verifies the SHA-256 before running it, so the payload cannot be altered in transit.</p>
        </div>

        {{-- Target selection --}}
        <div class="border-t border-gray-100 pt-4 dark:border-gray-800">
            <label class="mb-2 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Target</label>
            <div class="flex flex-wrap gap-2">
                <template x-for="opt in [
                    { value: 'all', label: 'All laptops' },
                    { value: 'device', label: 'One laptop' },
                    { value: 'devices', label: 'Multiple laptops' }
                ]" :key="opt.value">
                    <label class="cursor-pointer">
                        <input type="radio" name="target" :value="opt.value" x-model="target" class="peer sr-only">
                        <span class="inline-flex items-center rounded-lg border border-gray-200 px-3 py-2 text-theme-sm font-medium text-gray-600 peer-checked:border-brand-500 peer-checked:bg-brand-50 peer-checked:text-brand-600 dark:border-gray-700 dark:text-gray-300 dark:peer-checked:bg-brand-500/10 dark:peer-checked:text-brand-300"
                              x-text="opt.label"></span>
                    </label>
                </template>
            </div>
            <input type="hidden" name="agent_token_id" :value="target === 'device' ? (selected[0] ?? '') : ''">
            <template x-for="id in (target === 'devices' ? selected : [])" :key="'gh-' + id">
                <input type="hidden" name="agent_token_ids[]" :value="id">
            </template>

            {{-- Device picker with search. Selection is held in `selected`
                 independently of the filtered list, so searching for a second
                 laptop never clears the first checkbox. --}}
            <div x-show="target === 'device' || target === 'devices'" x-cloak class="mt-3">
                <div class="relative">
                    <span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-gray-400">
                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path fill-rule="evenodd" clip-rule="evenodd" d="M3.04 9.37a6.33 6.33 0 1 1 12.67 0 6.33 6.33 0 0 1-12.67 0ZM9.37 1.54a7.83 7.83 0 1 0 4.98 13.88l2.82 2.82a.75.75 0 1 0 1.06-1.06l-2.82-2.82A7.83 7.83 0 0 0 9.37 1.54Z" fill="currentColor"/></svg>
                    </span>
                    <input type="search" x-model="deviceSearch" placeholder="Search laptop by name or hostname..."
                           class="w-full rounded-lg border border-gray-200 bg-white py-2.5 pl-9 pr-3 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>

                <div class="mt-2 max-h-56 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-700">
                    <template x-for="d in filteredDevices" :key="d.id">
                        <button type="button" @click="target === 'device' ? (selected = [d.id]) : toggle(d.id)"
                                class="flex w-full items-center gap-3 border-b border-gray-100 px-3 py-2.5 text-left last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/5">
                            <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded border"
                                  :class="(target === 'device' ? selected[0] === d.id : selected.includes(d.id)) ? 'border-brand-500 bg-brand-500' : 'border-gray-300 dark:border-gray-600'">
                                <svg x-show="(target === 'device' ? selected[0] === d.id : selected.includes(d.id))" class="h-3 w-3 text-white" viewBox="0 0 12 12" fill="none"><path d="M2.5 6.2 5 8.7l4.5-4.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-theme-sm font-medium text-gray-700 dark:text-gray-200" x-text="d.name"></span>
                                <span class="block truncate text-theme-xs text-gray-400" x-text="d.host || 'no hostname reported'"></span>
                            </span>
                            <span class="h-2 w-2 shrink-0 rounded-full" :class="d.online ? 'bg-success-500' : 'bg-gray-300 dark:bg-gray-600'"></span>
                        </button>
                    </template>
                    <p x-show="filteredDevices.length === 0" class="px-3 py-6 text-center text-theme-sm text-gray-400">No laptop matches.</p>
                </div>
            </div>
        </div>

        {{-- Advanced options --}}
        <div class="border-t border-gray-100 pt-4 dark:border-gray-800">
            <div class="grid gap-4 md:grid-cols-3">
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Run as</label>
                    <select name="run_as" x-model="runAs" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        <option value="current-user">Current user</option>
                        <option value="admin">Administrator</option>
                        <option value="system">System</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Timeout (seconds)</label>
                    <input type="number" name="timeout_seconds" x-model="timeout" min="10" max="3600" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                </div>
                <div>
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Trigger</label>
                    <select name="trigger" x-model="trigger" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        <option value="once">Run once</option>
                        <option value="reboot">Run at next reboot</option>
                    </select>
                </div>
                <div class="md:col-span-3">
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Working directory</label>
                    <input type="text" name="working_directory" x-model="workingDir" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" placeholder="C:\Temp\Scripts">
                </div>
                <div class="md:col-span-3">
                    <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Purpose / notes</label>
                    <textarea name="notes" x-model="notes" rows="2" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-theme-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" placeholder="Why this script is being pushed, or instructions for the operator."></textarea>
                </div>
            </div>
        </div>

        {{-- Summary: the whole job in one strip, so the operator confirms what
             they are about to run before queueing it. --}}
        <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-white/5">
            <p class="mb-2 text-theme-xs font-semibold uppercase tracking-wider text-gray-400">Summary</p>
            <dl class="grid gap-x-6 gap-y-1.5 text-theme-sm sm:grid-cols-[8rem_1fr]">
                <dt class="text-gray-500 dark:text-gray-400">Script</dt>
                <dd class="font-medium text-gray-800 dark:text-gray-200" x-text="detectedType === 'binary' ? 'Binary — cannot be queued' : (detectedType === 'powershell' ? 'PowerShell' : 'CMD / Batch')"></dd>
                <dt class="text-gray-500 dark:text-gray-400">File</dt>
                <dd class="font-mono text-theme-xs text-gray-600 dark:text-gray-300" x-text="fileName"></dd>
                <dt class="text-gray-500 dark:text-gray-400">Target</dt>
                <dd class="font-medium text-gray-800 dark:text-gray-200" x-text="targetSummary"></dd>
                <dt class="text-gray-500 dark:text-gray-400">Runs as</dt>
                <dd class="text-gray-600 dark:text-gray-300" x-text="runAs === 'current-user' ? 'Current user' : (runAs === 'admin' ? 'Administrator' : 'System')"></dd>
                <dt class="text-gray-500 dark:text-gray-400">Trigger</dt>
                <dd class="text-gray-600 dark:text-gray-300" x-text="trigger === 'once' ? 'Once, on next poll' : 'At next reboot'"></dd>
                <template x-if="notes.trim().length > 0">
                    <dt class="text-gray-500 dark:text-gray-400">Purpose</dt>
                </template>
                <template x-if="notes.trim().length > 0">
                    <dd class="text-gray-600 dark:text-gray-300" x-text="notes"></dd>
                </template>
            </dl>
        </div>

        <div class="flex justify-end gap-3">
            <button type="button" @click="$dispatch('close-modal', 'push-script-modal')" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</button>
            <x-button type="submit" variant="primary" x-bind:disabled="detectedType === 'binary'" x-bind:class="detectedType === 'binary' && 'opacity-50 cursor-not-allowed'">Queue script</x-button>
        </div>
    </form>
</x-modal>
@endsection
