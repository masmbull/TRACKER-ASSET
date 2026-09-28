<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/**
 * A probe failed for a reason worth reading out loud on the page.
 *
 * A driver exception is only ever reported by class name, because some carry a
 * DSN or a password. A probe that knows its own failure text raises this
 * instead, so the page can show "mailpit:1025 not accepting connections" rather
 * than the useless "RuntimeException".
 */
class ProbeFailure extends RuntimeException {}

/**
 * Live health of everything the app depends on.
 *
 * Each probe is wrapped so one dead dependency cannot take the whole page down
 * and report every other service as unreachable too. A monitoring page that
 * fails while showing you that the monitor is broken is worse than no page.
 *
 * Probes are deliberately cheap and side-effect free: no writes, no queue
 * dispatch. A page that an operator refreshes every thirty seconds must not
 * become a source of load or of side effects in its own right.
 */
class ServiceHealthChecker
{
    /**
     * Longest a single probe may take. A hanging TCP connect has to become a
     * red row, not a page that hangs with it.
     */
    private const PROBE_TIMEOUT_SECONDS = 3;

    public function check(): array
    {
        $services = [
            $this->probeDatabase(),
            $this->probeRedis(),
            $this->probeCache(),
            $this->probeQueue(),
            $this->probeMail(),
            $this->probeFilesystem(),
            $this->probeScheduler(),
            $this->probeHealthEndpoint(),
        ];

        $healthy = collect($services)->where('status', 'ok')->count();

        return [
            'services' => $services,
            'total' => count($services),
            'healthy' => $healthy,
            'down' => count($services) - $healthy,
        ];
    }

    private function probe(callable $probe, string $name, string $purpose): array
    {
        $started = microtime(true);

        try {
            $detail = $probe();
            $ms = (int) round((microtime(true) - $started) * 1000);

            return compact('name', 'purpose', 'ms') + [
                'status' => 'ok',
                'detail' => $detail,
                'checked_at' => now()->toDateTimeString(),
            ];
        } catch (Throwable $e) {
            $ms = (int) round((microtime(true) - $started) * 1000);

            // Only a RuntimeException thrown by a probe carries a message the
            // operator is meant to read ("mailpit:1025 not accepting
            // connections"); a driver exception can carry a DSN or a password.
            // Reporters wrap the probe so the useful text is set explicitly
            // rather than guessing at the exception class.
            $detail = $e instanceof ProbeFailure
                ? $e->getMessage()
                : class_basename($e);

            if (! $e instanceof ProbeFailure) {
                // The full error belongs in the log, where an admin can see it.
                report($e);
            }

            return compact('name', 'purpose', 'ms') + [
                'status' => 'down',
                'detail' => $detail,
                'checked_at' => now()->toDateTimeString(),
            ];
        }
    }

    /**
     * The server version, queried in whatever dialect the connection uses.
     *
     * `select version()` is MySQL syntax and throws "no such function" on
     * SQLite, which is what this app runs on locally and what the test suite
     * uses everywhere. Hardcoding one dialect made the probe report a healthy
     * database as down. The driver name is enough on its own anyway, and the
     * version is a nicety that must never be able to fail the whole check.
     */
    private function databaseVersion(): string
    {
        try {
            $driver = DB::connection()->getDriverName();

            $version = match ($driver) {
                'mysql', 'mariadb' => DB::selectOne('select version() as v')->v ?? '',
                // SQLite exposes the library version through sqlite_version().
                'sqlite' => DB::selectOne('select sqlite_version() as v')->v ?? '',
                'sqlsrv' => DB::selectOne('select @@VERSION as v')->v ?? '',
                'pgsql' => DB::selectOne('show server_version')->v ?? '',
                default => '',
            };
        } catch (Throwable) {
            // The connection is already known good - the `select 1` above
            // passed - so a dialect this helper does not know is not a failure.
            $version = '';
        }

        $version = trim(preg_replace('/\s+/', ' ', (string) $version));

        // Long and noisy: "8.0.36-0ubuntu0.22.04.1" says nothing an operator
        // needs here, and the patch level changes without a deploy.
        return $version === '' ? '' : preg_split('/[^\d.]\s*/', $version)[0];
    }

    private function probeDatabase(): array
    {
        return $this->probe(function () {
            $started = microtime(true);
            DB::select('select 1');
            $ms = (int) round((microtime(true) - $started) * 1000);

            $name = config('database.default');
            $version = $this->databaseVersion();

            return sprintf('%s%s · %d ms', $name, $version === '' ? '' : ' '.$version, $ms);
        }, 'Database', 'Assets, audits, agent telemetry');
    }

    /**
     * Redis is only a dependency when something is actually configured to use
     * it. A local install running sqlite + file cache + sync queue has no Redis
     * and does not need the extension; reporting that as "down" would be a
     * false alarm that trains the operator to ignore this page.
     *
     * When it *is* configured, a missing phpredis extension is a real
     * deployment error and is reported as such, because the failure mode is
     * silent otherwise: config says redis, the app falls back or throws much
     * later, and nothing here would have mentioned it.
     */
    private function probeRedis(): array
    {
        $usedBy = array_values(array_filter([
            config('cache.default') === 'redis' ? 'cache' : null,
            config('queue.default') === 'redis' ? 'queue' : null,
            config('session.driver') === 'redis' ? 'session' : null,
        ]));

        return $this->probe(function () use ($usedBy) {
            if ($usedBy === []) {
                return sprintf('not in use (cache=%s, queue=%s, session=%s)',
                    config('cache.default'),
                    config('queue.default'),
                    config('session.driver')
                );
            }

            if (! extension_loaded('redis') && config('database.redis.default.client') === 'phpredis') {
                throw new ProbeFailure('phpredis extension missing but '.implode(', ', $usedBy).' need Redis');
            }

            $client = config('database.redis.default.client');
            // Read back what was just written: proves the connection works end
            // to end, not merely that a socket opened.
            Redis::connection()->set('tracker:healthprobe', '1', 'EX', 30);
            $value = Redis::connection()->get('tracker:healthprobe');

            if ((string) $value !== '1') {
                throw new ProbeFailure('read-back mismatch');
            }

            return sprintf('%s · reachable · used by %s', $client ?: 'unknown client', implode(', ', $usedBy));
        }, 'Redis', 'Cache, queue and sessions when configured to use it');
    }

    private function probeCache(): array
    {
        return $this->probe(function () {
            $store = config('cache.default');
            cache()->put('tracker:healthprobe', 'ok', 30);
            $value = cache()->get('tracker:healthprobe');

            if ($value !== 'ok') {
                throw new ProbeFailure('cache read-back mismatch');
            }

            return sprintf('%s · read/write ok', $store);
        }, 'Cache', config('cache.default') === 'file' ? 'Application cache (file driver)' : 'Session and view cache');
    }

    private function probeQueue(): array
    {
        return $this->probe(function () {
            $connection = config('queue.default');

            if (in_array($connection, ['sync', 'null'], true)) {
                // Not a failure: these run jobs inline, so there is no broker to
                // be down. Saying so beats showing a red row for a healthy setup.
                return sprintf('%s · runs inline, no broker', $connection);
            }

            Redis::connection('default')->connect();

            return sprintf('%s · broker reachable', $connection);
        }, 'Queue', 'Background jobs and scheduled commands');
    }

    private function probeMail(): array
    {
        return $this->probe(function () {
            $mailer = config('mail.default');
            $transport = config('mail.mailers.'.$mailer.'.transport');

            if (in_array($transport, ['log', 'array'], true)) {
                return sprintf('%s · %s (writes to log, not a real server)', $mailer, $transport);
            }

            $host = config('mail.mailers.'.$mailer.'.host');
            $port = (int) config('mail.mailers.'.$mailer.'.port', 587);

            $errno = 0;
            $errstr = '';
            // @ silences the warning a refused connection prints, so a dead mail
            // server shows as a red row instead of a PHP notice above the page.
            $connected = @fsockopen($host, $port, $errno, $errstr, self::PROBE_TIMEOUT_SECONDS);

            if ($connected) {
                fclose($connected);

                return sprintf('%s · %s:%d reachable', $mailer, $host, $port);
            }

            // Thrown, not returned. Returning the failure as a detail string
            // marked the whole row "ok" while the text next to it said the
            // server was unreachable, which is worse than showing it red: the
            // green dot is what gets scanned.
            throw new ProbeFailure(sprintf('%s · %s:%d not accepting connections', $mailer, $host, $port));
        }, 'Mail', 'Password resets and notifications');
    }

    private function probeFilesystem(): array
    {
        return $this->probe(function () {
            $paths = [
                'storage/app' => storage_path('app'),
                'logs' => storage_path('logs'),
                'framework' => storage_path('framework/views'),
            ];

            $report = [];

            foreach ($paths as $label => $path) {
                if (! is_dir($path)) {
                    throw new ProbeFailure($label.' directory missing');
                }

                if (! is_writable($path)) {
                    throw new ProbeFailure($label.' not writable');
                }

                $report[] = $label;
            }

            return implode(', ', $report).' · writable';
        }, 'Storage', 'Uploaded files, exports, generated documents');
    }

    private function probeScheduler(): array
    {
        return $this->probe(function () {
            $events = collect(Artisan::all())->keys();

            $commands = [
                'audit:sweep' => 'weekly re-verification of the register',
                'agent:resolve-stale-commands' => 'closes undeliverable agent commands',
            ];

            $missing = [];

            foreach (array_keys($commands) as $command) {
                if (! $events->contains($command)) {
                    $missing[] = $command;
                }
            }

            if ($missing !== []) {
                throw new ProbeFailure('not registered: '.implode(', ', $missing));
            }

            $due = [];

            foreach ($commands as $command => $purpose) {
                $event = collect(Artisan::all())->get($command);
                $due[] = $command.': '.$purpose;
            }

            return sprintf('%d registered · %s', count($events), implode('; ', $due));
        }, 'Scheduler', 'Recurring jobs; the stale-command sweep runs every 5 minutes');
    }

    private function probeHealthEndpoint(): array
    {
        return $this->probe(function () {
            // Called over the loopback rather than faked: a health endpoint that
            // answers internally but is unreachable from outside is still a
            // broken health endpoint.
            $url = rtrim((string) config('app.url'), '/').'/api/health';

            $context = stream_context_create([
                'http' => ['timeout' => self::PROBE_TIMEOUT_SECONDS, 'ignore_errors' => true],
            ]);

            $body = @file_get_contents($url, false, $context);

            if ($body === false) {
                throw new ProbeFailure('no response from '.$url);
            }

            $decoded = json_decode($body, true);

            if (($decoded['status'] ?? null) !== 'ok') {
                throw new ProbeFailure('unexpected payload');
            }

            return sprintf('%s · status ok', parse_url($url, PHP_URL_PATH) ?: '/api/health');
        }, 'Health endpoint', 'What the load balancer and Docker poll');
    }
}
