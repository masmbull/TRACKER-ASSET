<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Records that a scheduled command actually ran, so a dead scheduler is visible.
 *
 * The monitor used to check only that `agent:resolve-stale-commands` is
 * *registered* in routes/console.php. That proves the schedule is configured,
 * not that anything ever runs it: no cron entry, a stopped `schedule:work`
 * container or a crashed worker all leave the registration intact while every
 * command silently stops firing. The symptoms then show up much later as
 * "uninstall queued forever" or a register that never re-verifies itself.
 *
 * Each command stamps the cache as its first act. A gap wider than the
 * command's own cadence is what "the scheduler is not running" looks like.
 *
 * The cache is used rather than a table because this is a heartbeat, not data
 * worth keeping: it is written every five minutes and read by one page. It also
 * works on the file driver used in development, so the monitor is not blind
 * exactly where a developer is most likely to hit the problem.
 */
class ScheduleHeartbeat
{
    /**
     * Cadence of every scheduled command, in seconds.
     *
     * Kept next to the checks so a command and the threshold that judges it
     * cannot drift apart. Slack is deliberate: an hourly job checked against a
     * 5-minute threshold reports a healthy scheduler as broken on every slow
     * poll, and a nightly job checked against an hour one reports it as broken
     * for most of the day.
     */
    public const CADENCE = [
        'agent:resolve-stale-commands' => 300,
        'audit:sweep' => 604800,
    ];

    /**
     * How many missed runs are tolerated before a command is called stale.
     *
     * One miss is routine - a slow poll, a deploy, a laptop waking up. Three
     * means something is actually wrong, which is the thing worth an alert.
     */
    private const TOLERANCE = 3;

    private static function key(string $command): string
    {
        return 'tracker:scheduled:'.$command;
    }

    /**
     * Stamp the command as having run. Called by the command itself, first thing.
     *
     * The TTL is generously long: the key must outlive its own cadence by a
     * wide margin, or a job that runs exactly on schedule would be judged stale
     * during the interval before it stamps itself again.
     */
    public static function record(string $command): void
    {
        Cache::put(self::key($command), now()->timestamp, 60 * 60 * 24 * 30);
    }

    /**
     * When the command last ran, or null if it never has.
     */
    public static function lastRun(string $command): ?int
    {
        $value = Cache::get(self::key($command));

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * True when the command has not run within cadence × tolerance.
     *
     * A command that has never run counts as stale, deliberately. "Never" and
     * "ran a year ago" point at the same problem - nothing is driving the
     * schedule - and reporting never-ran as fine would hide exactly the case
     * that matters most.
     */
    public static function isStale(string $command): bool
    {
        $cadence = self::CADENCE[$command] ?? null;

        if ($cadence === null) {
            return false;
        }

        $last = self::lastRun($command);

        return $last === null || (time() - $last) > ($cadence * self::TOLERANCE);
    }
}
