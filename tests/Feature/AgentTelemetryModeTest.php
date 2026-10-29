<?php

namespace Tests\Feature;

use App\Models\AgentApiToken;
use App\Models\AgentTelemetry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fleet status when both agent processes are running.
 *
 * The tray and the SYSTEM daemon take turns writing one telemetry row. Before
 * this, whichever heartbeat landed last won, so a machine with a visible tray
 * alternated between "online" and "service active · tray not detected" every
 * minute - and an operator reading it at the wrong moment concluded the tray had
 * died when it was sitting in the taskbar.
 */
class AgentTelemetryModeTest extends TestCase
{
    use RefreshDatabase;

    private function telemetry(array $payload): AgentTelemetry
    {
        $token = AgentApiToken::create([
            'name' => 'TEST-'.uniqid(),
            'token_hash' => hash('sha256', 'token-'.uniqid()),
            'token_plain' => 'plain-'.uniqid(),
            'is_active' => true,
        ]);

        return AgentTelemetry::create([
            'agent_token_id' => $token->id,
            'hostname' => 'MBUL',
            'payload' => $payload,
            'reported_at' => now(),
        ]);
    }

    public function test_daemon_only_reports_service(): void
    {
        $this->assertSame('service', $this->telemetry(['agent_mode' => 'service'])->agentMode());
    }

    public function test_tray_report_reads_as_tray(): void
    {
        $this->assertSame('tray', $this->telemetry(['agent_mode' => 'tray'])->agentMode());
    }

    /** The regression: the daemon writes after the tray and must not hide it. */
    public function test_daemon_heartbeat_does_not_hide_a_recent_tray(): void
    {
        $telemetry = $this->telemetry([
            'agent_mode' => 'service',
            'tray_seen_at' => now()->subSeconds(20)->toIso8601String(),
        ]);

        $this->assertSame('tray', $telemetry->agentMode());
    }

    /** A tray that stopped reporting for minutes is genuinely gone. */
    public function test_a_stale_tray_report_does_not_claim_tray(): void
    {
        $telemetry = $this->telemetry([
            'agent_mode' => 'service',
            'tray_seen_at' => now()->subMinutes(10)->toIso8601String(),
        ]);

        $this->assertSame('service', $telemetry->agentMode());
    }

    public function test_unknown_mode_stays_unknown(): void
    {
        $this->assertSame('unknown', $this->telemetry([])->agentMode());
    }

    public function test_malformed_tray_timestamp_is_ignored(): void
    {
        $telemetry = $this->telemetry([
            'agent_mode' => 'service',
            'tray_seen_at' => 'not-a-date',
        ]);

        $this->assertSame('service', $telemetry->agentMode());
    }
}
