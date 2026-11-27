<?php

namespace App\Console\Commands;

use App\Models\AgentApiToken;
use App\Models\AgentRelease;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Register the build the download button is now serving.
 *
 * The server has no way to read a version out of a compiled .NET single-file
 * bundle reliably, so the build step tells it. Run this after ./build.bat:
 *
 *     php artisan agent:release 1.2.0 "Added RAM slots + Windows update state"
 *
 * Without it the fleet page cannot answer the only question that matters
 * before a rollout - "is the laptop in front of me on the new build?" - and
 * every device reads "unknown", which looks identical to a fleet of agents
 * that never reported at all.
 */
class AgentReleaseCommand extends Command
{
    protected $signature = 'agent:release
                            {version? : Version string, e.g. 1.2.0 (omit with --list)}
                            {--notes= : What changed in this build}
                            {--file= : Path to a built TrackerAgent.exe to store as this release}
                            {--list : Show the release history and exit}';

    protected $description = 'Record a TrackerAgent.exe build as the current release';

    public function handle(): int
    {
        if ($this->option('list')) {
            $current = AgentRelease::current();

            $rows = AgentRelease::query()
                ->orderByDesc('released_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (AgentRelease $r) => [
                    $r->version,
                    $r->is_current ? 'current' : '',
                    $r->hasBinary() ? ($r->sizeLabel() ?? 'yes') : 'no file',
                    $r->released_at?->format('Y-m-d H:i') ?? '-',
                    mb_substr((string) $r->notes, 0, 40),
                ])->all();

            if ($rows === []) {
                $this->warn('No releases recorded. Ship one with: php artisan agent:release <version> --file=<path-to-exe>');

                return self::SUCCESS;
            }

            $this->table(['Version', 'State', 'Binary', 'Released', 'Notes'], $rows);

            $behind = AgentApiToken::query()
                ->whereNotNull('agent_version')
                ->get()
                ->filter(fn (AgentApiToken $t) => AgentRelease::isBehind($t->agent_version) === true)
                ->count();

            $unknown = AgentApiToken::query()->whereNull('agent_version')->count();

            $this->line("Devices behind the current release: {$behind} (unknown version: {$unknown})");

            return self::SUCCESS;
        }

        $version = trim((string) $this->argument('version'));

        if ($version === '') {
            $this->error('A version is required (or pass --list). Example: php artisan agent:release 1.4.0 --file=agent/TrackerAgent.exe');

            return self::FAILURE;
        }

        if (! preg_match('/\d+(?:\.\d+)*/', $version)) {
            $this->error("\"{$version}\" has no version number in it. Expected something like 1.2.0.");

            return self::FAILURE;
        }

        $file = $this->option('file');

        // A file is optional only to keep the old metadata-only call working for
        // environments that ship the binary by hand. When one is given it is the
        // release: size and hash are read from it, and it is copied into
        // per-release storage so this row - not a mutable shared file - owns the
        // bytes. Without a file the row is metadata pointing at the legacy path.
        if ($file !== null && $file !== '') {
            if (! is_file($file)) {
                $this->error("No such file: {$file}");

                return self::FAILURE;
            }

            $handle = fopen($file, 'rb');
            $magic = $handle ? fread($handle, 2) : '';
            if ($handle) {
                fclose($handle);
            }

            if ($magic !== 'MZ') {
                $this->error("{$file} is not a Windows executable (no MZ header). Build TrackerAgent.exe first.");

                return self::FAILURE;
            }

            if (AgentRelease::where('version', $version)->exists()) {
                $this->error("Release {$version} already exists. Bump <Version> in the .csproj.");

                return self::FAILURE;
            }

            $size = (int) filesize($file);
            $sha = (string) hash_file('sha256', $file);
            $stored = 'agent/releases/'.$version.'-'.substr($sha, 0, 12).'.exe';

            Storage::disk('local')->put($stored, fopen($file, 'rb'));

            // Also keep the shared path current. Older deployments (and the
            // download route's fallback) look for storage/app/agent/TrackerAgent.exe;
            // without this copy those environments answer "executable not found"
            // even though a versioned release row exists in the database.
            Storage::disk('local')->put('agent/TrackerAgent.exe', fopen($file, 'rb'));

            $release = AgentRelease::ship($version, $this->option('notes'));
            $release->update(['file_path' => $stored, 'file_size' => $size, 'sha256' => $sha]);

            $this->info("Release {$release->version} is now current.");
            $this->line("Stored binary: {$stored} (".number_format($size / 1024 / 1024, 1).' MB)');
            $this->line('SHA-256: '.$sha);
        } else {
            $release = AgentRelease::ship($version, $this->option('notes'));

            $this->info("Release {$release->version} is now current (no binary attached).");
            $this->comment('The download route falls back to storage/app/agent/TrackerAgent.exe.');
        }

        $this->line('Previous release: '.(AgentRelease::previous()?->version ?? 'none on record'));
        $this->comment('Push it to the fleet from /agent/releases, or run: php artisan agent:release --list');

        return self::SUCCESS;
    }
}
