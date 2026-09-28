<?php

namespace Tests\Feature;

use App\Services\ServiceHealthChecker;
use App\Support\ScheduleHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A registered schedule and a running one are different things.
 *
 * With no cron entry and no schedule:work process, every command below keeps its
 * registration while firing never happens. Nothing else in the app notices: no
 * error is raised, the fleet page looks healthy, and the failure only surfaces
 * much later as an "Uninstall queued" badge that never clears. These tests pin
 * the detection for exactly that case.
 */
class ScheduleHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private function schedulerStatus(): array
    {
        $report = app(ServiceHealthChecker::class)->check();

        return collect($report['services'])->firstWhere('name', 'Scheduler');
    }

    public function test_a_schedule_that_has_never_run_is_reported_down(): void
    {
        Cache::forget('tracker:scheduled:agent:resolve-stale-commands');
        Cache::forget('tracker:scheduled:audit:sweep');

        $scheduler = $this->schedulerStatus();

        $this->assertSame('down', $scheduler['status']);
        $this->assertStringContainsString('never run', $scheduler['detail']);
        // The message has to say what to do, or it is just a complaint.
        $this->assertStringContainsString('schedule:work', $scheduler['detail']);
    }

    /**
     * The commands stamp themselves. Run one and the page must recover - this
     * is the only proof that the check reacts to reality rather than always
     * showing the same thing.
     */
    public function test_running_a_scheduled_command_stamps_it_and_clears_that_check(): void
    {
        Cache::forget('tracker:scheduled:agent:resolve-stale-commands');
        // The weekly sweep is stamped too: running one command is not proof
        // that both are healthy, and the page judges each against its own
        // cadence. Leaving it unstamped here would fail for the right reason
        // and for the wrong one.
        Cache::put('tracker:scheduled:audit:sweep', now()->timestamp, 3600);

        $this->assertSame('down', $this->schedulerStatus()['status']);

        $this->artisan('agent:resolve-stale-commands')->assertExitCode(0);

        $after = $this->schedulerStatus();
        $this->assertSame('ok', $after['status']);
        $this->assertStringContainsString('last run', $after['detail']);
    }

    /**
     * A scheduler that ran and then died looks registered and alive until
     * something notices the gap. The five-minute command goes stale after three
     * missed runs, not three minutes - a slow poll or a deploy must not read as
     * a broken scheduler.
     */
    public function test_a_gap_wider_than_the_cadence_is_reported_down(): void
    {
        $cadence = ScheduleHeartbeat::CADENCE['agent:resolve-stale-commands'];

        // Two missed runs: within tolerance.
        Cache::put('tracker:scheduled:agent:resolve-stale-commands', now()->subSeconds($cadence * 2)->timestamp, 3600);
        Cache::put('tracker:scheduled:audit:sweep', now()->timestamp, 3600);
        $this->assertFalse(ScheduleHeartbeat::isStale('agent:resolve-stale-commands'));
        $this->assertSame('ok', $this->schedulerStatus()['status']);

        // Four missed runs: past tolerance.
        Cache::put('tracker:scheduled:agent:resolve-stale-commands', now()->subSeconds($cadence * 4)->timestamp, 3600);
        $this->assertTrue(ScheduleHeartbeat::isStale('agent:resolve-stale-commands'));

        $scheduler = $this->schedulerStatus();
        $this->assertSame('down', $scheduler['status']);
        $this->assertStringContainsString('missed runs', $scheduler['detail']);
    }

    /**
     * The weekly sweep is judged on a week, not on the five-minute cadence of
     * its neighbour. Sharing one threshold would flag a healthy weekly job as
     * broken for six days out of seven.
     */
    public function test_the_weekly_sweep_is_not_judged_on_the_five_minute_cadence(): void
    {
        $fiveMinutes = ScheduleHeartbeat::CADENCE['agent:resolve-stale-commands'];

        // Two days since the weekly sweep. Far past the 5-minute command's
        // tolerance, comfortably inside its own.
        Cache::put('tracker:scheduled:agent:resolve-stale-commands', now()->timestamp, 3600);
        Cache::put('tracker:scheduled:audit:sweep', now()->subDays(2)->timestamp, 3600);

        $this->assertFalse(ScheduleHeartbeat::isStale('audit:sweep'));
        $this->assertTrue($fiveMinutes < ScheduleHeartbeat::CADENCE['audit:sweep']);
        $this->assertSame('ok', $this->schedulerStatus()['status']);
    }
}
