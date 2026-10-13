<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ServiceHealthChecker;
use App\Support\ScheduleHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ServiceMonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_every_backing_service(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('services.index'))
            ->assertOk()
            ->assertSee('Services')
            ->assertSee('Database')
            ->assertSee('Cache')
            ->assertSee('Scheduler')
            ->assertSee('Health endpoint');
    }

    public function test_services_page_animates_without_full_reloads(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Soft live region with a visible countdown, glowing status cards
        // and a re-check that patches in place: no location.reload anywhere.
        $this->actingAs($admin)->get(route('services.index'))
            ->assertOk()
            ->assertSee('data-live-region="services"', false)
            ->assertSee('data-refresh-countdown', false)
            ->assertSee('status-glow-ok', false)
            ->assertSee('services-recheck', false)
            ->assertDontSee('window.location.reload', false);
    }

    /**
     * The checks reveal the database driver, mail host and cache backend. That
     * is deployment detail, not asset data, and a technician has no reason to
     * read any of it.
     */
    public function test_the_page_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'technician']))
            ->get(route('services.index'))
            ->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'support']))
            ->get(route('services.index'))
            ->assertForbidden();
    }

    /**
     * Which SAPIs can deadlock on a self-call.
     *
     * `php artisan serve` with one worker holds its only worker for the /services
     * request, so the loopback call to /api/health queues behind the request
     * making it and can only ever time out. Anywhere there is a pool of workers -
     * php-fpm, nginx, RoadRunner, or the dev server told to fork - the self-call
     * is fine and remains the right test, because an endpoint that answers
     * internally but is unreachable from outside is still broken.
     */
    public function test_only_a_single_worker_dev_server_cannot_probe_itself(): void
    {
        // The bug: reported the health endpoint as down while it was up.
        $this->assertTrue(ServiceHealthChecker::loopbackSelfCallImpossible('cli-server', 1));
        $this->assertTrue(ServiceHealthChecker::loopbackSelfCallImpossible('cli-server', 0));

        // The fixes: a worker pool, and every production SAPI.
        $this->assertFalse(ServiceHealthChecker::loopbackSelfCallImpossible('cli-server', 2));
        $this->assertFalse(ServiceHealthChecker::loopbackSelfCallImpossible('cli-server', 4));
        $this->assertFalse(ServiceHealthChecker::loopbackSelfCallImpossible('fpm-fcgi', 1));
        $this->assertFalse(ServiceHealthChecker::loopbackSelfCallImpossible('litespeed', 1));
    }

    /**
     * A check that could not be performed must not be counted as a failure.
     *
     * Counting it as "down" produced a red alarm on a healthy system, which is
     * the failure mode that teaches operators to ignore the page. `skipped` keeps
     * the row visible and keeps it out of the failure count.
     *
     * The skip is reached here through the config gate rather than the SAPI
     * check, because the two produce the same state by the same code path and
     * only the config gate is reachable from the CLI-based suite.
     */
    public function test_an_unperformable_check_is_skipped_not_counted_as_down(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $report = $this->actingAs($admin)->getJson(route('services.health'))->json();

        $health = collect($report['services'])->firstWhere('name', 'Health endpoint');

        $this->assertNotNull($health, 'the health endpoint row should still be listed');
        $this->assertSame('skipped', $health['status'], 'phpunit disables the loopback probe, so it cannot run');
        $this->assertSame(1, $report['skipped']);
        $this->assertSame(
            $report['total'] - $report['healthy'] - $report['skipped'],
            $report['down'],
            'down must be total - healthy - skipped, so an unchecked row is never counted as a failure'
        );
    }

    /**
     * The page has to say "not checked" rather than "down" for the same reason.
     * A red badge would read as an outage; silence would read as success.
     */
    public function test_the_page_labels_an_unperformable_check_as_not_checked(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('services.index'))
            ->assertOk()
            ->assertSee('not checked')
            ->assertDontSee('Health endpoint down')
            ->assertDontSee('>down<', false);
    }

    /**
     * The hint must not send Windows developers after a fix that cannot work.
     *
     * PHP's built-in server forks workers through a POSIX-only call; on Windows it
     * prints "forking is not supported on this platform" and stays single-process
     * whatever PHP_CLI_SERVER_WORKERS says. Telling a Windows user to set that
     * variable is advice that provably does nothing, and the skipped row is the
     * one place they are told what to do next.
     */
    public function test_the_hint_is_true_for_the_platform_it_is_given(): void
    {
        $windows = ServiceHealthChecker::singleWorkerHint('\\');
        $posix = ServiceHealthChecker::singleWorkerHint('/');

        $this->assertStringContainsString('cannot fork workers on Windows', $windows);
        $this->assertStringNotContainsString('PHP_CLI_SERVER_WORKERS', $windows);

        $this->assertStringContainsString('--no-reload', $posix);
        $this->assertStringContainsString('PHP_CLI_SERVER_WORKERS', $posix);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('services.index'))->assertRedirect('/login');
    }

    /**
     * A load balancer reads the status code. A 200 carrying a body that says
     * "down" would report a broken instance as healthy.
     */
    public function test_the_json_endpoint_answers_200_when_nothing_is_down(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Stamp the scheduled commands first. Nothing runs a scheduler inside a
        // test, and an unstamped schedule is correctly reported as down - so
        // without this the 200 assertion would fail for a reason that has
        // nothing to do with the status code it is checking.
        foreach (array_keys(ScheduleHeartbeat::CADENCE) as $command) {
            ScheduleHeartbeat::record($command);
        }

        $response = $this->actingAs($admin)->getJson(route('services.health'));

        $response->assertOk();
        $this->assertSame(0, $response->json('down'));
        $this->assertGreaterThan(0, $response->json('total'));
    }

    /**
     * The database queue's broker is a table, not a socket.
     *
     * The probe used to reach for Redis on every non-inline driver. On a server
     * running queue=database, against an image with no phpredis extension, that
     * threw `Class "Redis" not found` and a perfectly healthy queue reported down
     * with the detail "Error" - a red row whose text explained nothing. The jobs
     * table is what a dispatched job would actually fail on, so that is what the
     * row checks.
     */
    public function test_a_database_queue_is_probed_through_its_table_not_redis(): void
    {
        config(['queue.default' => 'database']);

        $report = app(ServiceHealthChecker::class)->check();
        $queue = collect($report['services'])->firstWhere('name', 'Queue');

        $this->assertSame('ok', $queue['status']);
        $this->assertStringContainsString('jobs table reachable', $queue['detail']);
    }

    /**
     * A driver the probe does not know must say so by name.
     *
     * The alternative is reaching for the wrong broker again: assuming every
     * non-inline queue is Redis is exactly the bug the test above pins down.
     */
    public function test_an_unprobed_queue_driver_is_named_rather_than_guessed(): void
    {
        config(['queue.default' => 'sqs']);

        $report = app(ServiceHealthChecker::class)->check();
        $queue = collect($report['services'])->firstWhere('name', 'Queue');

        $this->assertSame('down', $queue['status']);
        $this->assertStringContainsString('sqs', $queue['detail']);
    }

    /**
     * The point of the JSON endpoint: a stopped scheduler is a real outage, and
     * a load balancer reads the status code rather than a cheerful body.
     */
    public function test_the_json_endpoint_answers_503_when_the_scheduler_is_not_running(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        foreach (array_keys(ScheduleHeartbeat::CADENCE) as $command) {
            Cache::forget('tracker:scheduled:'.$command);
        }

        $this->actingAs($admin)->getJson(route('services.health'))
            ->assertStatus(503)
            ->assertJsonPath('down', 1);
    }

    /**
     * Mail is the one service a test cannot bring up, so this asserts the
     * honest case: a dead mail server must be reported down, and its reason
     * must be readable rather than a bare exception class name.
     */
    public function test_a_failing_service_is_reported_down_with_a_readable_reason(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 9]);

        $report = app(ServiceHealthChecker::class)->check();
        $mail = collect($report['services'])->firstWhere('name', 'Mail');

        $this->assertSame('down', $mail['status']);
        $this->assertStringContainsString('not accepting connections', $mail['detail']);
        $this->assertStringNotContainsString('RuntimeException', $mail['detail']);
    }

    /**
     * A name that does not resolve is not a mail server that is down.
     *
     * `mailpit` is the host shipped in .env.example, and it resolves only inside
     * docker compose - so on a developer machine this probe reported the mail
     * row as "not accepting connections", which reads as "start your mail
     * server" for the one case where starting it changes nothing. The two faults
     * have different fixes, so the row has to say which one it found.
     */
    public function test_a_mail_host_that_does_not_resolve_says_so(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'no-such-mail-host.invalid',
            'mail.mailers.smtp.port' => 1025,
        ]);

        $report = app(ServiceHealthChecker::class)->check();
        $mail = collect($report['services'])->firstWhere('name', 'Mail');

        $this->assertSame('down', $mail['status']);
        $this->assertStringContainsString('does not resolve', $mail['detail']);
        $this->assertStringContainsString('no-such-mail-host.invalid', $mail['detail']);
        $this->assertStringNotContainsString('not accepting connections', $mail['detail']);
    }

    /**
     * The database probe used to hardcode `select version()`, which is MySQL
     * syntax and throws on SQLite - reporting a healthy database as down. The
     * suite runs entirely on SQLite, so this is the regression that matters.
     */
    public function test_the_database_probe_works_on_sqlite(): void
    {
        $report = app(ServiceHealthChecker::class)->check();
        $db = collect($report['services'])->firstWhere('name', 'Database');

        $this->assertSame('ok', $db['status'], 'A reachable SQLite database must not be reported down: '.$db['detail']);
    }

    /**
     * Redis is not a dependency unless something is configured to use it. A
     * local install on file cache and sync queue must not show a red row for a
     * service it never asked for.
     */
    public function test_redis_is_not_reported_down_when_nothing_uses_it(): void
    {
        config([
            'cache.default' => 'file',
            'queue.default' => 'sync',
            'session.driver' => 'file',
        ]);

        $report = app(ServiceHealthChecker::class)->check();
        $redis = collect($report['services'])->firstWhere('name', 'Redis');

        $this->assertSame('ok', $redis['status']);
        $this->assertStringContainsString('not in use', $redis['detail']);
    }
}
