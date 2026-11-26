@extends('layouts.app')

@section('title', 'Fleet Commands')

@section('content')
@php
    $pendingCount = $commands->where('status', 'pending')->count();
    $runningCount = $commands->where('status', 'in_progress')->count();
    $liveCount = $pendingCount + $runningCount;
@endphp

<x-page-header title="Fleet Command Center" subtitle="Agents poll every 10 seconds and pending commands are executed automatically.">
    <x-slot:actions>
        <div class="flex flex-wrap items-center gap-2">
            <form method="POST" action="{{ route('agent.scanAll') }}" data-modal-action>
                @csrf
                <x-button type="submit" variant="secondary" size="sm"><x-icon name="activity" class="h-4 w-4" /> Scan All</x-button>
            </form>
            <x-button type="button" variant="primary" size="sm" @click="$dispatch('open-modal', 'confirm-update-all-agents')"><x-icon name="refresh" class="h-4 w-4" /> Update All</x-button>
            <x-button type="button" size="sm" class="bg-gradient-to-r from-brand-600 to-brand-500 text-white shadow-theme-md hover:from-brand-700 hover:to-brand-600" @click="$dispatch('open-modal', 'push-script-modal')">
                <x-icon name="code" class="h-4 w-4" /> Push Script
            </x-button>
            <form method="POST" action="{{ route('agent.sweep') }}" data-modal-action>
                @csrf
                <x-button type="submit" variant="secondary" size="sm"><x-icon name="laptop-pulse" class="h-4 w-4" /> Sweep Now</x-button>
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

    <p class="mt-3 text-theme-xs text-gray-400">
        Commands are picked up by each agent on its next 10-second poll. Running and waiting commands are highlighted below.
    </p>
</x-card>

<x-card class="mb-6" title="Commands">
    <div data-live-region="commands"{!! $liveCount > 0 ? ' data-poll-interval="10"' : '' !!}>
    @if($commands->count() > 0)
        <div class="overflow-x-auto">
            <x-table :headers="['ID', 'Command', 'Device', 'Status', 'Progress', 'Created', 'Executed']">
                @foreach($commands as $cmd)
                    @php
                        $statusColors = [
                            'pending' => 'bg-warning-100 text-warning-700 dark:bg-warning-500/10 dark:text-warning-300',
                            'in_progress' => 'bg-brand-100 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300',
                            'done' => 'bg-success-100 text-success-700 dark:bg-success-500/10 dark:text-success-300',
                            'failed' => 'bg-error-100 text-error-700 dark:bg-error-500/10 dark:text-error-300',
                        ];
                        $percent = $cmd->status === 'done' ? 100 : (int) ($cmd->progress ?? 0);
                        $stalled = $cmd->isStalled();
                    @endphp
                    <tr class="border-b border-gray-100 transition hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/5 {{ $cmd->status === 'in_progress' ? 'bg-brand-50/40 dark:bg-brand-500/5' : '' }}">
                        <td class="py-2 px-2 font-mono text-theme-xs font-semibold text-gray-500 dark:text-gray-400">#{{ $cmd->id }}</td>
                        <td class="py-2 px-2"><span class="rounded-lg bg-gray-100 px-2 py-1 text-theme-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300">{{ $cmd->command }}</span></td>
                        <td class="py-2 px-2 text-theme-sm text-gray-600 dark:text-gray-400">{{ $cmd->token?->name ?? 'All devices' }}</td>
                        <td class="py-2 px-2">
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-1 text-theme-xs font-medium {{ $statusColors[$cmd->status] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300' }}">
                                @if($cmd->status === 'in_progress')
                                    <span class="pulse-dot h-1.5 w-1.5 rounded-full bg-brand-500"></span>
                                @endif
                                {{ $cmd->status === 'in_progress' ? 'running' : $cmd->status }}
                            </span>
                        </td>
                        <td class="py-2 px-2">
                            @if(in_array($cmd->status, ['pending', 'in_progress']))
                                <div class="w-28">
                                    <div class="process-progress-track h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                                        <div class="h-full rounded-full {{ $stalled ? 'bg-error-500' : 'bg-gradient-to-r from-brand-600 to-brand-400' }} {{ $cmd->status === 'in_progress' ? 'progress-stripes' : '' }}"
                                             style="width: {{ $stalled ? $percent : max(3, $percent) }}%"></div>
                                    </div>
                                    <p class="mt-1 truncate text-theme-xs {{ $stalled ? 'text-error-500' : 'text-gray-400' }}" title="{{ $cmd->progressLabel() }}">{{ $cmd->progressLabel() ?? 'queued' }}</p>
                                </div>
                            @else
                                <span class="text-theme-xs text-gray-400">{{ $cmd->status === 'done' ? '100% · done' : ($cmd->result ? 'failed' : '—') }}</span>
                            @endif
                        </td>
                        <td class="py-2 px-2 text-theme-sm text-gray-600 dark:text-gray-400">{{ $cmd->created_at?->diffForHumans() ?? '—' }}</td>
                        <td class="py-2 px-2 text-theme-sm text-gray-600 dark:text-gray-400">{{ $cmd->executed_at?->diffForHumans() ?? '—' }}</td>
                    </tr>
                @endforeach
            </x-table>
        </div>
    @else
        <div class="py-10 text-center">
            <span class="mx-auto mb-3 flex h-11 w-11 items-center justify-center rounded-xl bg-gray-100 text-gray-400 dark:bg-gray-800"><x-icon name="activity" class="h-5 w-5" /></span>
            <p class="text-theme-sm text-gray-400">No commands yet. Queue a script or scan a device to get started.</p>
        </div>
    @endif
    @if($liveCount > 0)
        <p class="mt-3 flex items-center gap-2 text-theme-xs text-gray-400">
            <span class="pulse-dot inline-block h-1.5 w-1.5 rounded-full {{ $runningCount > 0 ? 'bg-brand-500' : 'bg-warning-500' }}"></span>
            {{ $runningCount }} running · {{ $pendingCount }} waiting — updating automatically, no need to refresh.
        </p>
    @endif
    </div>
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
    <form method="POST" action="{{ route('agent.commands.script') }}" class="space-y-5" data-modal-action data-close-modal="push-script-modal"
          x-data="{
              target: 'all',
              body: '',
              runAs: 'current-user',
              timeout: 300,
              trigger: 'once',
              workingDir: 'C:\\Temp\\Scripts',
              notes: '',
              showAdvanced: false,
              deviceSearch: '',
              selected: [],
              devices: @js($tokens->filter(fn ($t) => $t->isUsable())->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'host' => $t->telemetry?->hostname, 'online' => (bool) ($t->last_used_at && $t->last_used_at->gt(now()->subSeconds(90)))])->values()),
              // One catalogue in PHP: label, notes, default target and the
              // silent script body always agree.
              presets: @js(\App\Support\ScriptPresets::definitions()),
              get groupedPresets() {
                  const groups = {};
                  Object.entries(this.presets).forEach(([key, preset]) => {
                      if (!groups[preset.section]) { groups[preset.section] = []; }
                      groups[preset.section].push(Object.assign({ key }, preset));
                  });
                  return Object.entries(groups).map(([section, items]) => ({ section, items }));
              },
              applyPreset(key) {
                  const p = this.presets[key];
                  if (!p) { return; }
                  this.body = p.body;
                  this.runAs = p.runAs;
                  this.workingDir = p.workingDir;
                   this.notes = p.notes;
                   if (p.target) { this.target = p.target; }
              },
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

        <div class="flex items-start gap-3 rounded-xl border border-brand-100 bg-brand-50/70 px-4 py-3 dark:border-brand-500/20 dark:bg-brand-500/10">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-brand-600 shadow-theme-xs dark:bg-white/5 dark:text-brand-300"><x-icon name="code" class="h-5 w-5" /></span>
            <div class="min-w-0">
                <p class="text-theme-sm font-semibold text-gray-800 dark:text-white">Run a script on enrolled laptops</p>
                <p class="mt-0.5 text-theme-xs text-gray-600 dark:text-gray-300">The script is hashed, queued, and verified by each agent before it runs. You can minimize the progress panel while it works.</p>
            </div>
        </div>

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

            {{-- Ready-made scripts, so the common jobs are one click instead of a
                 paste. A preset only fills the form (body, run-as, notes); the
                 operator still sees the exact body and target before queueing. --}}
            <div class="mb-2 space-y-3 rounded-xl border border-gray-100 bg-gray-50/70 px-3 py-3 dark:border-gray-800 dark:bg-gray-800/40">
                <div class="flex items-center justify-between gap-2">
                    <span class="inline-flex items-center gap-1 text-theme-xs font-semibold uppercase tracking-wider text-gray-400"><x-icon name="save" class="h-3.5 w-3.5" /> Presets by category</span>
                    <a href="{{ asset('downloads/MITOGROUP_IT_Support_Toolkit.bat') }}" download class="inline-flex items-center gap-1 rounded-lg border border-brand-200 bg-brand-50 px-2.5 py-1 text-theme-xs font-semibold text-brand-700 hover:bg-brand-100 dark:border-brand-500/30 dark:bg-brand-500/10 dark:text-brand-300 dark:hover:bg-brand-500/20"><x-icon name="download" class="h-3.5 w-3.5" /> Full toolkit</a>
                </div>
                <template x-for="group in groupedPresets" :key="group.section">
                    <div class="border-t border-gray-100 pt-2 first:border-0 first:pt-0 dark:border-gray-800">
                        <p class="mb-1.5 text-theme-xs font-semibold text-gray-500 dark:text-gray-400" x-text="group.section"></p>
                        <div class="flex flex-wrap gap-1.5">
                            <template x-for="p in group.items" :key="p.key">
                                <button type="button" @click="applyPreset(p.key)" :title="p.notes"
                                        class="rounded-full border border-gray-200 bg-white px-3 py-1 text-theme-xs font-medium text-gray-600 shadow-theme-xs transition hover:-translate-y-0.5 hover:border-brand-300 hover:bg-brand-50 hover:text-brand-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-brand-500/10 dark:hover:text-brand-300"
                                        x-text="p.label"></button>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
            <p class="mb-2 text-theme-xs text-warning-700 dark:text-warning-300">Full toolkit is interactive (UAC/menu prompts), so download and run it locally. The presets above are unattended-safe and default to one laptop.</p>

            <textarea name="script_body" x-model="body" rows="12" required
                      spellcheck="false"
                      class="code-editor w-full rounded-xl border border-gray-200 bg-gray-950 px-4 py-3 font-mono text-[12.5px] leading-relaxed text-gray-100 shadow-theme-xs focus:border-brand-400 focus:outline-none focus:ring-3 focus:ring-brand-500/20 dark:border-gray-700"
                      placeholder="# Paste your PowerShell or CMD script here..."></textarea>
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
                        <span class="inline-flex items-center gap-1.5 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-theme-sm font-medium text-gray-600 shadow-theme-xs transition peer-checked:border-brand-500 peer-checked:bg-brand-50 peer-checked:text-brand-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:peer-checked:bg-brand-500/10 dark:peer-checked:text-brand-300"
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
                        <x-icon name="search" class="h-4 w-4" />
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
                                <x-icon x-show="(target === 'device' ? selected[0] === d.id : selected.includes(d.id))" name="check" class="h-3 w-3 text-white" />
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

        {{-- Compact summary. The detailed options still exist and still submit
             their defaults - they are just folded away until someone needs them. --}}
        <div class="flex flex-wrap items-center gap-2 rounded-xl border border-gray-100 bg-gray-50/70 px-3 py-2 text-theme-xs dark:border-gray-800 dark:bg-gray-800/40">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 font-medium text-gray-700 shadow-theme-xs dark:bg-gray-900 dark:text-gray-200">
                <x-icon name="code" class="h-3.5 w-3.5 text-brand-500" />
                <span x-text="detectedType === 'binary' ? 'Binary — not allowed' : (detectedType === 'powershell' ? 'PowerShell' : 'CMD / Batch')"></span>
            </span>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 font-medium text-gray-700 shadow-theme-xs dark:bg-gray-900 dark:text-gray-200">
                <x-icon name="laptop" class="h-3.5 w-3.5 text-brand-500" />
                <span x-text="targetSummary"></span>
            </span>
            <span class="ml-auto font-mono text-gray-400" x-text="fileName"></span>
        </div>

        {{-- Advanced options: needed, but default to the safe values above and
             stay collapsed so the common case is just paste -> target -> queue. --}}
        <div class="border-t border-gray-100 dark:border-gray-800">
            <button type="button" @click="showAdvanced = !showAdvanced"
                    class="flex w-full items-center justify-between py-3 text-left text-theme-sm font-medium text-gray-600 transition hover:text-brand-600 dark:text-gray-300"
                    :aria-expanded="showAdvanced ? 'true' : 'false'">
                <span class="inline-flex items-center gap-2"><x-icon name="settings" class="h-4 w-4" /> Advanced options <span class="text-theme-xs font-normal text-gray-400">(run as, timeout, trigger, notes)</span></span>
                <x-icon name="chevron-down" class="h-4 w-4 transition-transform" ::class="showAdvanced ? 'rotate-180' : ''" />
            </button>
            <div x-show="showAdvanced" x-collapse x-cloak class="grid gap-4 pb-1 md:grid-cols-3">
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

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-4 dark:border-gray-800">
            <p class="text-theme-xs text-gray-400">Nothing runs immediately — the job starts on the agent's next poll.</p>
            <div class="flex items-center gap-3">
                <button type="button" @click="$dispatch('close-modal', 'push-script-modal')" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-theme-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">Cancel</button>
                <x-button type="submit" variant="primary" x-bind:disabled="detectedType === 'binary'" x-bind:class="detectedType === 'binary' && 'opacity-50 cursor-not-allowed'">
                    <x-icon name="code" class="h-4 w-4" /> Queue script
                </x-button>
            </div>
        </div>
    </form>
</x-modal>
@endsection
