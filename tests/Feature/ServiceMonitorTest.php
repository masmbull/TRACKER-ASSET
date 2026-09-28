<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ServiceHealthChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $response = $this->actingAs($admin)->getJson(route('services.health'));

        $response->assertOk();
        $this->assertSame('ok', $response->json('services.0.status'));
        $this->assertGreaterThan(0, $response->json('total'));
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
