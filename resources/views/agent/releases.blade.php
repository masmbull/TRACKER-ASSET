@extends('layouts.app')

@section('title', 'Agent Releases')

@section('content')
<x-page-header title="Agent Releases" subtitle="Ship a TrackerAgent build, make it the version every download and update serves, and roll the fleet onto it.">
    <x-slot:actions>
        <x-button type="button" variant="primary" @click="$dispatch('open-modal', 'upload-release-modal')">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Upload Build
        </x-button>
    </x-slot:actions>
</x-page-header>

<x-card class="mb-6">
    <div class="flex flex-wrap gap-2 text-theme-sm">
        <a href="{{ route('agent.index') }}" class="rounded-lg bg-gray-100 px-3 py-1.5 font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">Tokens</a>
        <a href="{{ route('agent.releases') }}" class="rounded-lg bg-brand-600 px-3 py-1.5 font-medium text-white">Releases</a>
        <a href="{{ route('agent.commands') }}" class="rounded-lg bg-gray-100 px-3 py-1.5 font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">Commands</a>
        <a href="{{ route('agent.fleet') }}" class="rounded-lg bg-gray-100 px-3 py-1.5 font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">Live Fleet</a>
    </div>

    @foreach(['success', 'error'] as $flash)
        @if(session($flash))
            <div class="mt-4 rounded-xl border px-4 py-3 text-theme-sm {{ $flash === 'success' ? 'border-success-200 bg-success-50 text-success-700 dark:border-success-500/20 dark:bg-success-500/10 dark:text-success-300' : 'border-error-200 bg-error-50 text-error-700 dark:border-error-500/20 dark:bg-error-500/10 dark:text-error-300' }}">
                {{ session($flash) }}
            </div>
        @endif
    @endforeach
</x-card>

<div class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3">
    <x-card title="Current Build" class="border-brand-200 dark:border-brand-800">
        <p class="text-2xl font-semibold text-gray-900 dark:text-white">
            {{ $current?->version ?? 'none' }}
        </p>
        <p class="mt-1 text-theme-xs text-gray-500">
            {{ $current?->released_at?->diffForHumans() ?? 'No release recorded yet' }}
        </p>
        @if($current && ! $current->hasBinary())
            <p class="mt-2 text-theme-xs text-warning-600 dark:text-warning-400">
                No stored binary — serving the legacy shared file.
            </p>
        @endif
    </x-card>

    <x-card title="Devices Behind">
        <p class="text-2xl font-semibold {{ $outdated > 0 ? 'text-warning-600' : 'text-success-600' }}">
            {{ $outdated }}
        </p>
        <p class="mt-1 text-theme-xs text-gray-500">{{ $unknown }} reporting no version</p>
    </x-card>

    <x-card title="Releases On Record">
        <p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $releases->count() }}</p>
        <p class="mt-1 text-theme-xs text-gray-500">{{ $releases->filter(fn ($r) => $r->hasBinary())->count() }} with a stored binary</p>
    </x-card>
</div>

<x-card title="Release History" padding="p-0">
    <x-table :headers="['Version', 'State', 'Size', 'SHA-256', 'Devices on this build', 'Released', 'Actions']" emptyText="No releases yet. Upload a build to ship the first one.">
        @forelse($releases as $release)
            @php
                $normalized = \App\Models\AgentRelease::normalize($release->version);
                $onThisBuild = $byVersion[$normalized] ?? 0;
            @endphp
            <tr class="transition-colors hover:bg-gray-50 dark:hover:bg-white/5">
                <td class="py-3 px-4">
                    <span class="font-semibold text-gray-900 dark:text-white">{{ $release->version }}</span>
                    @if($release->notes)
                        <p class="mt-0.5 max-w-xs truncate text-theme-xs text-gray-500" title="{{ $release->notes }}">{{ $release->notes }}</p>
                    @endif
                </td>
                <td class="py-3 px-4">
                    @if($release->is_current)
                        <x-badge variant="success" dot>Current</x-badge>
                    @else
                        <x-badge variant="gray">Stored</x-badge>
                    @endif
                </td>
                <td class="py-3 px-4 text-theme-sm text-gray-600 dark:text-gray-400">
                    {{ $release->sizeLabel() ?? '—' }}
                </td>
                <td class="py-3 px-4">
                    @if($release->sha256)
                        <code class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-theme-xs text-gray-600 dark:bg-gray-800 dark:text-gray-400" title="{{ $release->sha256 }}">{{ substr($release->sha256, 0, 12) }}…</code>
                    @else
                        <span class="text-theme-xs text-gray-400">—</span>
                    @endif
                </td>
                <td class="py-3 px-4 text-theme-sm text-gray-600 dark:text-gray-400">
                    {{ $onThisBuild }}
                </td>
                <td class="py-3 px-4 text-theme-sm text-gray-500">
                    {{ $release->released_at?->diffForHumans() ?? '—' }}
                </td>
                <td class="py-3 px-4">
                    <div class="flex flex-wrap items-center justify-end gap-2">
                        @if($release->hasBinary())
                            <a href="{{ route('agent.releases.download', $release) }}" class="inline-flex items-center gap-1 rounded-lg bg-gray-100 px-2.5 py-1.5 text-theme-xs font-medium text-gray-700 transition-colors hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                                Download
                            </a>
                        @endif

                        @unless($release->is_current)
                            @if($release->hasBinary())
                                <form method="POST" action="{{ route('agent.releases.activate', $release) }}">
                                    @csrf
                                    <x-button type="submit" variant="secondary" size="sm">Activate</x-button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('agent.releases.destroy', $release) }}" onsubmit="return confirm('Delete release {{ $release->version }} and its stored binary?');">
                                @csrf
                                @method('DELETE')
                                <x-button type="submit" variant="ghost" size="sm" class="text-error-600 hover:bg-error-50 dark:text-error-400">Delete</x-button>
                            </form>
                        @endunless

                        @if($release->is_current && $release->hasBinary())
                            <form method="POST" action="{{ route('agent.releases.push', $release) }}" onsubmit="return confirm('Queue an update to {{ $release->version }} on every device that is not already on it?');">
                                @csrf
                                <x-button type="submit" variant="primary" size="sm">Push to fleet</x-button>
                            </form>
                        @endif
                    </div>
                </td>
            </tr>
        @empty
        @endforelse
    </x-table>
</x-card>

<x-card class="mt-6" title="Build &amp; Ship Workflow">
    <ol class="grid grid-cols-1 gap-6 text-theme-sm md:grid-cols-4">
        <li class="flex gap-3">
            <span class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full bg-brand-500 text-xs font-bold text-white">1</span>
            <div>
                <p class="font-semibold text-gray-900 dark:text-white">Bump the version</p>
                <p class="text-gray-600 dark:text-gray-400">Edit <code class="rounded bg-gray-100 px-1 py-0.5 text-theme-xs dark:bg-gray-800">&lt;Version&gt;</code> in <code class="rounded bg-gray-100 px-1 py-0.5 text-theme-xs dark:bg-gray-800">agent/TrackerAgent/TrackerAgent.csproj</code>.</p>
            </div>
        </li>
        <li class="flex gap-3">
            <span class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full bg-brand-500 text-xs font-bold text-white">2</span>
            <div>
                <p class="font-semibold text-gray-900 dark:text-white">Build</p>
                <p class="text-gray-600 dark:text-gray-400">Run <code class="rounded bg-gray-100 px-1 py-0.5 text-theme-xs dark:bg-gray-800">build.bat</code>. It publishes a single-file, self-contained <code class="rounded bg-gray-100 px-1 py-0.5 text-theme-xs dark:bg-gray-800">TrackerAgent.exe</code>.</p>
            </div>
        </li>
        <li class="flex gap-3">
            <span class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full bg-brand-500 text-xs font-bold text-white">3</span>
            <div>
                <p class="font-semibold text-gray-900 dark:text-white">Upload here</p>
                <p class="text-gray-600 dark:text-gray-400">Click <strong>Upload Build</strong>, enter the version, attach the .exe. It becomes current immediately.</p>
            </div>
        </li>
        <li class="flex gap-3">
            <span class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full bg-brand-500 text-xs font-bold text-white">4</span>
            <div>
                <p class="font-semibold text-gray-900 dark:text-white">Push to fleet</p>
                <p class="text-gray-600 dark:text-gray-400"><strong>Push to fleet</strong> queues an update on every device behind it. Each agent re-pulls and restarts itself.</p>
            </div>
        </li>
    </ol>
</x-card>

<x-modal id="upload-release-modal" title="Upload a Build" maxWidth="lg">
    <form method="POST" action="{{ route('agent.releases.store') }}" enctype="multipart/form-data" class="space-y-4">
        @csrf
        <div>
            <label for="version" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Version</label>
            <input type="text" name="version" id="version" value="{{ old('version') }}" placeholder="1.4.0" required
                   class="w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-theme-sm text-gray-800 focus:border-brand-500 focus:outline-none dark:border-gray-700 dark:text-white/90">
            <p class="mt-1 text-theme-xs text-gray-500">Must match <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">&lt;Version&gt;</code> in the .csproj.</p>
        </div>
        <div>
            <label for="notes" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">Release notes <span class="text-gray-400">(optional)</span></label>
            <textarea name="notes" id="notes" rows="2" placeholder="What changed in this build"
                      class="w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-theme-sm text-gray-800 focus:border-brand-500 focus:outline-none dark:border-gray-700 dark:text-white/90">{{ old('notes') }}</textarea>
        </div>
        <div>
            <label for="binary" class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-300">TrackerAgent.exe</label>
            <input type="file" name="binary" id="binary" accept=".exe" required
                   class="w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 text-theme-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-brand-500 file:px-3 file:py-1.5 file:text-white dark:border-gray-700 dark:text-gray-300">
            <p class="mt-1 text-theme-xs text-gray-500">The self-contained build (~160 MB). Its size and SHA-256 are recorded automatically.</p>
        </div>
        <div class="flex justify-end gap-3 pt-2">
            <x-button type="button" variant="secondary" @click="$dispatch('close-modal', 'upload-release-modal')">Cancel</x-button>
            <x-button type="submit" variant="primary">Upload &amp; Make Current</x-button>
        </div>
    </form>
</x-modal>
@endsection