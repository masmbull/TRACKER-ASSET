<?php

namespace App\Console\Commands;

use App\Models\AgentApiToken;
use App\Models\AgentRelease;
use Illuminate\Console\Command;

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
                            {version : Version string, e.g. 1.2.0}
                            {--notes= : What changed in this build}
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
                    $r->released_at?->format('Y-m-d H:i') ?? '-',
                    mb_substr((string) $r->notes, 0, 50),
                ])->all();

            if ($rows === []) {
                $this->warn('No releases recorded. Run: php artisan agent:release <version>');

                return self::SUCCESS;
            }

            $this->table(['Version', 'State', 'Released', 'Notes'], $rows);

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

        if (! preg_match('/\d+(?:\.\d+)*/', $version)) {
            $this->error("\"{$version}\" has no version number in it. Expected something like 1.2.0.");

            return self::FAILURE;
        }

        $release = AgentRelease::ship($version, $this->option('notes'));

        $this->info("Release {$release->version} is now current.");
        $this->line('Previous release: '.(AgentRelease::previous()?->version ?? 'none on record'));
        $this->comment('Devices already reporting an older version will show as out of date on /agent/fleet.');

        return self::SUCCESS;
    }
}
