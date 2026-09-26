<?php

namespace Tests\Feature;

use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use App\Models\AgentTelemetry;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Scan Again" on the asset page (PRD §11): the click must really queue a scan
 * command for the agent enrolled on that device — the laptop answers within its
 * 10-second poll and the audit lands on this asset. Everything runs as a plain
 * local user on the device: no UAC, no admin rights anywhere in the loop.
 */
class AgentScanButtonTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        Company::create(['name' => 'Test Co', 'code' => 'TC', 'address' => '']);
        AssetCategory::create(['name' => 'Laptop']);
        Location::create(['name' => 'Jakarta', 'status' => 'active']);
        AssetStatus::create(['name' => 'Active']);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->asset = Asset::factory()->create([
            'serial_number' => 'SN-AGENT-01',
            'hostname' => 'HOST-AGENT-01',
        ]);
    }

    /**
     * Enroll a device for $this->asset: one token + the telemetry snapshot
     * linking that token to the asset (exactly what a completed agent scan
     * leaves behind).
     */
    private function enrollAgent(array $tokenOverrides = []): AgentApiToken
    {
        $token = AgentApiToken::create(array_merge([
            'name' => 'field-laptop',
            'device_id' => 'ZGV2aWNlLTAwMQ==',
            'platform' => 'Windows 11',
            'token_hash' => hash('sha256', $plain = 'tat_'.Str::random(48)),
            'token_plain' => $plain,
        ], $tokenOverrides));

        AgentTelemetry::create([
            'agent_token_id' => $token->id,
            'asset_id' => $this->asset->id,
            'device_id' => 'ZGV2aWNlLTAwMQ==',
            'hostname' => 'HOST-AGENT-01',
            'payload' => [],
            'reported_at' => now(),
        ]);

        return $token;
    }

    public function test_send_scan_queues_a_command_for_the_enrolled_device(): void
    {
        $token = $this->enrollAgent();

        $this->actingAs($this->admin)
            ->from(route('assets.show', $this->asset))
            ->post(route('assets.audit.agent.scan', $this->asset))
            ->assertRedirect(route('assets.show', $this->asset))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('agent_commands', [
            'agent_token_id' => $token->id,
            'command' => 'scan',
            'status' => 'pending',
        ]);
        $this->assertSame(1, AgentCommand::count());

        $command = AgentCommand::firstOrFail();
        $this->assertSame('asset-audit', $command->payload['source'] ?? null);
        $this->assertSame($this->asset->id, $command->payload['asset_id'] ?? null);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'AGENT_SCAN_REQUEST',
            'entity_id' => $this->asset->id,
        ]);
    }

    public function test_send_scan_errors_when_no_agent_is_enrolled(): void
    {
        $this->actingAs($this->admin)
            ->from(route('assets.show', $this->asset))
            ->post(route('assets.audit.agent.scan', $this->asset))
            ->assertRedirect(route('assets.show', $this->asset))
            ->assertSessionHas('error');

        $this->assertSame(0, AgentCommand::count());
        $this->assertDatabaseMissing('activity_logs', ['action' => 'AGENT_SCAN_REQUEST']);
    }

    public function test_send_scan_errors_when_the_agent_token_is_deactivated(): void
    {
        $this->enrollAgent(['is_active' => false]);

        $this->actingAs($this->admin)
            ->from(route('assets.show', $this->asset))
            ->post(route('assets.audit.agent.scan', $this->asset))
            ->assertSessionHas('error');

        $this->assertSame(0, AgentCommand::count());
    }

    public function test_send_scan_requires_a_serial_number(): void
    {
        $this->asset->forceFill(['serial_number' => ''])->save();
        $this->enrollAgent();

        $this->actingAs($this->admin)
            ->from(route('assets.show', $this->asset))
            ->post(route('assets.audit.agent.scan', $this->asset))
            ->assertSessionHas('error');

        $this->assertSame(0, AgentCommand::count());
    }

    public function test_send_scan_requires_authentication(): void
    {
        $this->post(route('assets.audit.agent.scan', $this->asset))
            ->assertRedirect('/login');
    }
}
