<?php

namespace App\Services;

use App\Support\ScheduleHeartbeat;
use Aws\S3\S3Client;
use Carbon\Carbon;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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
 * Probes are deliberately cheap: they never dispatch queue work or create
 * uploaded objects. Cache and Redis probes use a short-lived read-back marker,
 * while storage and HTTP probes only read. The page refreshes every 30 seconds.
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
            $this->probePhpRuntime(),
            $this->probeLaravelRuntime(),
            $this->probeEngine('livewire/livewire', 'Livewire\\Livewire', 'Livewire UI engine', 'Reactive pages and interactions'),
            $this->probeEngine('maatwebsite/excel', 'Maatwebsite\\Excel\\ExcelServiceProvider', 'Excel import engine', 'Spreadsheet import and export'),
            $this->probeEngine('barryvdh/laravel-dompdf', 'Barryvdh\\DomPDF\\ServiceProvider', 'PDF generation engine', 'Generated asset documents and reports'),
            $this->probeEngine('simplesoftwareio/simple-qrcode', 'SimpleSoftwareIO\\QrCode\\QrCodeServiceProvider', 'QR code engine', 'Asset labels and QR sheets'),
            $this->probePwaServiceWorker(),
            $this->probeDatabase(),
            $this->probeRedis(),
            $this->probeCache(),
            $this->probeSession(),
            $this->probeQueue(),
            $this->probeMail(),
            $this->probeFilesystem(),
            $this->probeS3Storage(),
            $this->probeScheduler(),
            $this->probeHealthEndpoint(),
            $this->probeEntra(),
            $this->probeMicrosoftGraph(),
        ];

        $healthy = collect($services)->where('status', 'ok')->count();
        $skipped = collect($services)->where('status', 'skipped')->count();

        return [
            'services' => $services,
            'total' => count($services),
            'healthy' => $healthy,
            // A skipped check is not a healthy check, and it is certainly not a
            // failed one. Counting it as down is what turns "I could not look"
            // into a red alarm, and a monitor that cries wolf is one people
            // learn to ignore.
            'skipped' => $skipped,
            'down' => count($services) - $healthy - $skipped,
        ];
    }

    /**
     * True when this process cannot serve a loopback request while it is busy.
     *
     * `php artisan serve` is PHP's built-in server, and with a single worker it
     * handles one request at a time. A request to /services therefore occupies
     * the only worker, and the loopback call to /api/health made underneath it
     * queues behind the very request making it - so the call can only ever time
     * out, and the page reported the health endpoint as down while it was
     * demonstrably up. That is the worst kind of wrong on a health page: it
     * trains people to ignore it.
     *
     * Detected rather than assumed, and it does not weaken the production check.
     * Behind php-fpm, nginx or RoadRunner there is a pool of workers, and the
     * self-call is exactly the right test - an endpoint that answers internally
     * but is unreachable from outside is still broken.
     */
    private function cannotProbeOverLoopback(): bool
    {
        return self::loopbackSelfCallImpossible(
            PHP_SAPI,
            (int) (getenv('PHP_CLI_SERVER_WORKERS') ?: 1),
        );
    }

    /**
     * Pure form of the check above, split out so it can be tested.
     *
     * `PHP_SAPI` is a compile-time constant and cannot be faked from a test, and
     * the suite runs on the CLI rather than on the built-in server, so a test of
     * the private method would only ever exercise the one branch that does not
     * matter. Taking the SAPI and the worker count as arguments makes the
     * interesting cases - single-worker dev server, worker pool, php-fpm -
     * reachable without a server.
     *
     * @param  string  $sapi  The SAPI serving this request.
     * @param  int  $workers  Concurrent request handlers available to serve a loopback call.
     */
    public static function loopbackSelfCallImpossible(string $sapi, int $workers): bool
    {
        return $sapi === 'cli-server' && $workers < 2;
    }

    /**
     * What to tell whoever is looking at the skipped row.
     *
     * The obvious advice - set PHP_CLI_SERVER_WORKERS - is wrong on Windows, and
     * sending people after it is worse than saying nothing. PHP's built-in server
     * forks its workers with a POSIX-only call, so on Windows it prints
     * "forking is not supported on this platform" and serves every request from
     * a single process no matter what the variable says. There is no setting that
     * fixes it; the dev server has to be replaced or moved.
     *
     * @param  string  $separator  DIRECTORY_SEPARATOR, passed in so both branches are testable.
     */
    public static function singleWorkerHint(string $separator = DIRECTORY_SEPARATOR): string
    {
        if ($separator === '\\') {
            return 'not checked: PHP\'s built-in server cannot fork workers on Windows, '
                .'so it cannot call itself. Serve the app from a real web server, '
                .'or run the dev server under WSL or Docker.';
        }

        return 'not checked: `artisan serve` handles one request at a time, so it cannot call itself. '
            .'Start it with `PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload`.';
    }

    /**
     * A check that could not be performed, reported as neither ok nor down.
     *
     * Third state, and the reason it exists: a monitor that reports "I could not
     * look" as "broken" is worse than one that reports nothing, because the first
     * trains its readers to ignore the red. `skipped` keeps the row visible -
     * silently dropping a check would look like success - while keeping it out of
     * the failure count.
     */
    private function probeSkipped(string $detail, string $name, string $purpose, string $category = 'Service'): array
    {
        return [
            'name' => $name,
            'purpose' => $purpose,
            'category' => $category,
            'status' => 'skipped',
            'detail' => $detail,
            'ms' => 0,
            'checked_at' => now()->toDateTimeString(),
        ];
    }

    private function probe(callable $probe, string $name, string $purpose, string $category = 'Service'): array
    {
        $started = microtime(true);

        try {
            $detail = $probe();
            $ms = (int) round((microtime(true) - $started) * 1000);

            return compact('name', 'purpose', 'ms', 'category') + [
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
                // report() must never be able to turn a *down* row into a 500:
                // on a deployment whose log channel or storage volume is not
                // writable it throws, and the monitor page dies while trying to
                // report that another service is merely down. Swallow that
                // failure - the row below is still the truth the operator needs.
                try {
                    report($e);
                } catch (Throwable) {
                    // Logging is itself a service being monitored; if it is
                    // broken, say so in the row instead of crashing the page.
                    $detail = 'logging unavailable · '.class_basename($e);
                }
            }

            return compact('name', 'purpose', 'ms', 'category') + [
                'status' => 'down',
                'detail' => $detail,
                'checked_at' => now()->toDateTimeString(),
            ];
        }
    }

    private function probePhpRuntime(): array
    {
        return $this->probe(
            fn () => sprintf('PHP %s · %s SAPI', PHP_VERSION, PHP_SAPI),
            'PHP runtime',
            'Server-side execution engine',
            'Engine'
        );
    }

    private function probeLaravelRuntime(): array
    {
        return $this->probe(
            fn () => 'Laravel '.app()->version().' · application booted',
            'Laravel framework',
            'Application runtime and web framework',
            'Engine'
        );
    }

    private function probeEngine(string $package, string $class, string $name, string $purpose): array
    {
        return $this->probe(function () use ($package, $class) {
            if (! class_exists($class)) {
                throw new ProbeFailure($package.' is required but its runtime class is unavailable');
            }

            $version = class_exists(InstalledVersions::class)
                ? InstalledVersions::getPrettyVersion($package)
                : null;

            return $package.' · '.($version ?: 'loaded');
        }, $name, $purpose, 'Engine');
    }

    private function probePwaServiceWorker(): array
    {
        return $this->probe(function () {
            $script = public_path('sw.js');
            if (! is_file($script) || ! is_readable($script)) {
                throw new ProbeFailure('public/sw.js is missing or unreadable');
            }

            return 'public/sw.js packaged · browser registration is client-side';
        }, 'PWA service worker', 'Offline app shell and static asset cache', 'Engine');
    }

    private function probeSession(): array
    {
        return $this->probe(function () {
            $driver = (string) config('session.driver');

            if ($driver === 'file') {
                $path = (string) config('session.files');
                if (! is_dir($path) || ! is_writable($path)) {
                    throw new ProbeFailure('session directory is missing or not writable');
                }

                return 'file driver · session directory writable';
            }

            if ($driver === 'database') {
                $table = (string) config('session.table', 'sessions');
                if (! Schema::hasTable($table)) {
                    throw new ProbeFailure($table.' table is missing');
                }

                return 'database driver · '.$table.' table reachable';
            }

            if ($driver === 'redis') {
                $connection = (string) (config('session.store') ?: 'default');
                Redis::connection($connection)->ping();

                return 'redis driver · session connection reachable';
            }

            if (in_array($driver, ['cookie', 'array'], true)) {
                return $driver.' driver · available in this process';
            }

            return $driver.' driver · configured';
        }, 'Session store', 'Login state and user sessions');
    }

    private function probeEntra(): array
    {
        if (! config('services.entra.enabled')) {
            return $this->probeSkipped('disabled · local sign-in is active', 'Microsoft Entra ID', 'Single sign-on provider', 'Integration');
        }

        $tenant = (string) (config('services.entra.tenant_id') ?: 'common');

        return $this->probe(function () use ($tenant) {
            $url = "https://login.microsoftonline.com/{$tenant}/v2.0/.well-known/openid-configuration";
            $response = Http::timeout(self::PROBE_TIMEOUT_SECONDS)->get($url);
            if (! $response->successful()) {
                throw new ProbeFailure('OpenID discovery returned HTTP '.$response->status());
            }

            return 'OpenID discovery reachable · tenant '.(string) (config('services.entra.tenant_id') ?: 'common');
        }, 'Microsoft Entra ID', 'Single sign-on provider', 'Integration');
    }

    private function probeMicrosoftGraph(): array
    {
        if (! config('services.entra.enabled') || ! config('services.entra.directory')) {
            return $this->probeSkipped('directory integration disabled', 'Microsoft Graph', 'Employee directory lookup', 'Integration');
        }

        return $this->probe(function () {
            $response = Http::timeout(self::PROBE_TIMEOUT_SECONDS)->get('https://graph.microsoft.com/v1.0/$metadata');
            if (! $response->successful()) {
                throw new ProbeFailure('Graph metadata endpoint returned HTTP '.$response->status());
            }

            return 'Graph endpoint reachable · tenant permissions are checked on directory lookup';
        }, 'Microsoft Graph', 'Employee directory lookup', 'Integration');
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

        if ($usedBy === []) {
            return $this->probeSkipped(
                sprintf('not in use · cache=%s, queue=%s, session=%s', config('cache.default'), config('queue.default'), config('session.driver')),
                'Redis',
                'Optional cache, queue and session engine'
            );
        }

        return $this->probe(function () use ($usedBy) {
            // `database.redis.client`, not `database.redis.default.client`: the
            // client key sits beside `default`, not inside it, so the old path
            // read null and this check silently never fired.
            if (! extension_loaded('redis') && config('database.redis.client') === 'phpredis') {
                throw new ProbeFailure('phpredis extension missing but '.implode(', ', $usedBy).' need Redis');
            }

            $client = config('database.redis.client');
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

            if ($connection === 'database') {
                // The broker is a table, not a socket. This probe used to reach
                // for Redis on every non-inline driver, which tested a service the
                // database queue does not use - and in an image without the
                // phpredis extension threw `Class "Redis" not found`, so a healthy
                // queue reported down with the detail "Error". What the row has to
                // prove is the thing jobs would actually fail on: the table.
                $table = (string) config('queue.connections.database.table', 'jobs');

                try {
                    DB::table($table)->exists();
                } catch (Throwable) {
                    throw new ProbeFailure(sprintf('%s · %s table not readable', $connection, $table));
                }

                return sprintf('%s · %s table reachable', $connection, $table);
            }

            if ($connection !== 'redis') {
                // Unknown driver: name it rather than guess at a broker this
                // check does not know how to reach. A red row that says which
                // driver it cannot probe is actionable; a false "reachable" or a
                // bare exception class is not.
                throw new ProbeFailure(sprintf('%s · no probe for this driver', $connection));
            }

            if (! extension_loaded('redis') && config('database.redis.client') === 'phpredis') {
                throw new ProbeFailure('phpredis extension missing but queue=redis needs Redis');
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

            // "not accepting connections" for a name that never resolved sent
            // whoever read it to restart a mail server they were not running.
            // `mailpit` is the host in .env.example, and it only resolves inside
            // docker compose - on a developer machine the SMTP port is not even
            // published - so the usual local failure is resolution, not a dead
            // mailbox. fsockopen reports the two differently: a name that does
            // not resolve returns the getaddrinfo message and no errno, a
            // refused or blackholed connect returns a socket errno.
            if (str_contains($errstr, 'getaddrinfo')) {
                throw new ProbeFailure(sprintf(
                    '%s · %s does not resolve from this machine (MAIL_HOST=%s only resolves inside docker compose)',
                    $mailer,
                    $host,
                    $host
                ));
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

            return implode(', ', $report).' · writable · default disk '.config('filesystems.default');
        }, 'Local file storage', 'Uploads, exports, generated documents and agent releases');
    }

    private function probeS3Storage(): array
    {
        if (config('filesystems.default') !== 's3') {
            return $this->probeSkipped(
                'optional S3 disk is defined but inactive · current default is '.config('filesystems.default'),
                'Amazon S3 storage',
                'Optional cloud object storage',
                'Integration'
            );
        }

        return $this->probe(function () {
            if (! class_exists(S3Client::class)) {
                throw new ProbeFailure('AWS S3 SDK is missing');
            }

            $bucket = (string) config('filesystems.disks.s3.bucket');
            if ($bucket === '') {
                throw new ProbeFailure('bucket name is not configured');
            }

            // HEAD is a read-only availability/credential check. Avoid listing
            // objects or uploading a marker into a customer's bucket.
            Storage::disk('s3')->getClient()->headBucket(['Bucket' => $bucket]);

            return 'S3 bucket reachable · read access confirmed';
        }, 'Amazon S3 storage', 'Active cloud object storage', 'Integration');
    }

    private function probeScheduler(): array
    {
        return $this->probe(function () {
            $registered = collect(Artisan::all())->keys();

            $commands = [
                'agent:resolve-stale-commands' => 'every 5 min',
                'audit:sweep' => 'weekly',
            ];

            $missing = array_keys(array_filter($commands, fn ($c, $name) => ! $registered->contains($name), ARRAY_FILTER_USE_BOTH));

            if ($missing !== []) {
                throw new ProbeFailure('not registered: '.implode(', ', $missing));
            }

            // Registration is not execution. Without this the page says the
            // scheduler is fine while nothing has fired it since the deploy -
            // the single most likely way this whole system quietly stops.
            $stale = array_values(array_filter(
                array_keys($commands),
                fn (string $command) => ScheduleHeartbeat::isStale($command)
            ));

            if ($stale !== []) {
                $never = array_values(array_filter(
                    $stale,
                    fn (string $command) => ScheduleHeartbeat::lastRun($command) === null
                ));

                $reason = $never !== []
                    ? 'never run: '.implode(', ', $never).' - is the scheduler started? (schedule:work or cron)'
                    : 'missed runs: '.implode(', ', $stale).' - is the scheduler stopped?';

                throw new ProbeFailure($reason);
            }

            $last = [];

            foreach (array_keys($commands) as $command) {
                $at = ScheduleHeartbeat::lastRun($command);
                $last[] = $command.' '.$commands[$command].' · last run '.Carbon::createFromTimestamp($at)->diffForHumans();
            }

            return sprintf('%d commands · %s', count($registered), implode(' · ', $last));
        }, 'Scheduler', 'Recurring jobs; a stale-command sweep that stops is invisible elsewhere');
    }

    private function probeHealthEndpoint(): array
    {
        // Skippable, and skipped in the test suite. This probe makes a real
        // loopback request, so "everything is healthy" was only ever true when a
        // developer happened to have `artisan serve` running on the configured
        // APP_URL. The suite reported a healthy fleet as broken whenever it did
        // not, and passed whenever it did - a test that measures the developer's
        // workstation rather than the code.
        //
        // The check is kept enabled everywhere else, and stays a real request
        // rather than a self-call: an endpoint that answers internally but is
        // unreachable from outside is still a broken health endpoint.
        if (! config('services.probe_health_endpoint', true)) {
            return $this->probeSkipped('skipped in this environment', 'Health endpoint', 'What the load balancer and Docker poll');
        }

        if ($this->cannotProbeOverLoopback()) {
            return $this->probeSkipped(
                self::singleWorkerHint(),
                'Health endpoint',
                'What the load balancer and Docker poll'
            );
        }

        return $this->probe(function () {
            // Default to APP_URL, but allow Docker deployments to point at the
            // nginx container by name (SERVICES_PROBE_HEALTH_ENDPOINT_URL=http://nginx)
            // because php-fpm does not listen on HTTP ports inside its container.
            $base = config('services.probe_health_endpoint_url', config('app.url'));
            $url = rtrim((string) $base, '/').'/api/health';

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
