<?php

namespace Tests\Feature;

use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use App\Models\AgentFingerprint;
use App\Models\AgentTelemetry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The agent reports more than the web pages used to show.
 *
 * Laravel's validate() is a whitelist: a field without a rule is dropped from
 * the payload before any controller can store it, and a stored field that is
 * left out of an eager-load select renders as null. These tests pin the whole
 * trip - wire format, database column and rendered page - so a missing rule or
 * a missing select cannot silently blank a reading again.
 */
class AgentFieldVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private AgentApiToken $agentToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = 'tat_'.Str::random(48);

        $this->agentToken = AgentApiToken::create([
            'name' => 'visible-laptop',
            'device_id' => 'ZGV2aWNlLTAwMQ==',
            'token_hash' => hash('sha256', $this->token),
            'token_plain' => $this->token,
            'is_active' => true,
        ]);
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    public function test_reported_hardware_fields_survive_audit_and_heartbeat_validation(): void
    {
        $this->withHeaders($this->headers())->postJson('/api/audits', [
            'hostname' => 'HOST-VIS-01',
            'serial_number' => 'SN-VIS-01',
            'storage_type' => 'SSD',
            'storage_capacity' => '512 GB',
            'battery_status' => 'charging',
            'monitor_sn' => 'MON-SERIAL-1',
            'keyboard_model' => 'Keyboard A',
            'mouse_model' => 'Mouse B',
        ])->assertCreated();

        $this->assertDatabaseHas('agent_telemetries', [
            'agent_token_id' => $this->agentToken->id,
            'storage_type' => 'SSD',
            'storage_capacity' => '512 GB',
            'battery_status' => 'charging',
            'monitor_sn' => 'MON-SERIAL-1',
            'keyboard_model' => 'Keyboard A',
            'mouse_model' => 'Mouse B',
        ]);

        // A heartbeat must refresh the same columns, not just the JSON payload:
        // the fleet panel reads them as ordinary fields.
        $this->withHeaders($this->headers())->postJson('/api/agent/heartbeat', [
            'hostname' => 'HOST-VIS-01',
            'storage_type' => 'NVMe',
            'battery_status' => 'discharging',
            'monitor_sn' => 'MON-SERIAL-2',
        ])->assertOk();

        $telemetry = AgentTelemetry::where('agent_token_id', $this->agentToken->id)->firstOrFail();
        $this->assertSame('NVMe', $telemetry->storage_type);
        $this->assertSame('discharging', $telemetry->battery_status);
        $this->assertSame('MON-SERIAL-2', $telemetry->monitor_sn);
        // Partial update: a value not sent keeps its last known reading.
        $this->assertSame('Keyboard A', $telemetry->keyboard_model);
    }

    public function test_fingerprint_accepts_printer_count_and_accessories(): void
    {
        $this->withHeaders($this->headers())->postJson('/api/agent/fingerprint', [
            'device_id' => 'ZGV2aWNlLTAwMQ==',
            'printer_count' => 3,
            'monitor_sn' => 'MON-FP-1',
            'keyboard_model' => 'Keyboard FP',
            'mouse_model' => 'Mouse FP',
            'headset_model' => 'Headset FP',
            'webcam_model' => 'Webcam FP',
            'other_accessories' => 'Docking station',
        ])->assertOk();

        $fingerprint = AgentFingerprint::where('agent_token_id', $this->agentToken->id)->firstOrFail();
        $this->assertSame(3, (int) $fingerprint->payload['printer_count']);
        $this->assertSame('MON-FP-1', $fingerprint->monitor_sn);
        $this->assertSame('Headset FP', $fingerprint->headset_model);
        $this->assertSame('Docking station', $fingerprint->other_accessories);
    }

    public function test_fleet_refresh_carries_live_progress_and_the_agents_result_message(): void
    {
        AgentTelemetry::create([
            'agent_token_id' => $this->agentToken->id,
            'hostname' => 'HOST-VIS-01',
            'reported_at' => now(),
        ]);

        $running = AgentCommand::create([
            'agent_token_id' => $this->agentToken->id,
            'command' => 'install',
            'status' => 'in_progress',
            'progress' => 76,
            'stage' => 'Mengatur agent berjalan otomatis saat login...',
            'started_at' => now(),
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson(route('agent.fleet.refresh'))
            ->assertOk()
            ->assertJsonPath("devices.{$this->agentToken->id}.operation.command", 'install')
            ->assertJsonPath("devices.{$this->agentToken->id}.operation.progress", 76)
            ->assertJsonPath(
                "devices.{$this->agentToken->id}.operation.label",
                'Mengatur agent berjalan otomatis saat login... (76%)'
            );

        // Settling the row replaces the open operation with the agent's own
        // explanation, which the 15-second poll has to keep delivering.
        $running->update([
            'status' => 'done',
            'progress' => null,
            'stage' => null,
            'executed_at' => now(),
            'result' => ['message' => 'Agent installed; the first hardware scan is queued.'],
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson(route('agent.fleet.refresh'))
            ->assertOk()
            ->assertJsonPath(
                "devices.{$this->agentToken->id}.result_message",
                'Agent installed; the first hardware scan is queued.'
            );
    }

    public function test_fleet_page_renders_previously_invisible_fields_and_raw_payload(): void
    {
        AgentTelemetry::create([
            'agent_token_id' => $this->agentToken->id,
            'hostname' => 'HOST-VIS-01',
            'storage_type' => 'NVMe',
            'storage_capacity' => '512 GB',
            'battery_status' => 'charging',
            'monitor_sn' => 'MON-VIS-1',
            'payload' => ['agent_mode' => 'tray', 'sensor_note' => 'RAW-SENSOR-VALUE'],
            'reported_at' => now(),
        ]);

        AgentFingerprint::create([
            'agent_token_id' => $this->agentToken->id,
            'os_build' => '10.0.22631',
            'timezone' => 'Asia/Jakarta',
            'cpu_physical_cores' => 6,
            'payload' => ['timezone' => 'Asia/Jakarta', 'future_reading' => 'RAW-INVENTORY-VALUE'],
            'collected_at' => now(),
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('agent.fleet'))
            ->assertOk()
            ->assertSee('NVMe', false)
            ->assertSee('512 GB', false)
            ->assertSee('charging', false)
            ->assertSee('MON-VIS-1', false)
            ->assertSee('Asia/Jakarta', false)
            ->assertSee('Physical cores', false)
            ->assertSee('RAW-SENSOR-VALUE', false)
            ->assertSee('RAW-INVENTORY-VALUE', false)
            ->assertSee('data-fleet-operation', false);
    }

    public function test_offline_reason_uses_the_agents_own_last_message(): void
    {
        AgentTelemetry::create([
            'agent_token_id' => $this->agentToken->id,
            'hostname' => 'HOST-VIS-01',
            'reported_at' => now()->subHour(),
        ]);
        $this->agentToken->update(['last_used_at' => now()->subHour()]);

        AgentCommand::create([
            'agent_token_id' => $this->agentToken->id,
            'command' => 'script',
            'status' => 'failed',
            'executed_at' => now()->subMinutes(50),
            'result' => ['message' => 'Windows refused the script request (exit code 5).'],
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('agent.fleet'))
            ->assertOk()
            ->assertSee('Windows refused the script request (exit code 5).', false);
    }
}
