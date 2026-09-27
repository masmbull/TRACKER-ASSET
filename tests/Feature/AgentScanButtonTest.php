<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AgentCommandApiController;
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

    /**
     * Removing an agent from the browser (PRD §11). The server cannot touch the
     * user's disk, so the click queues an `uninstall` command; the resident
     * daemon deletes itself on the next poll. The token must survive that poll
     * — AuthenticateAuditAgent rejects inactive tokens, so revoking at queue
     * time would make the command undeliverable.
     */
    public function test_uninstall_queues_a_command_without_revoking_the_token(): void
    {
        $token = $this->enrollAgent();

        $this->actingAs($this->admin)
            ->from(route('agent.fleet'))
            ->post(route('agent.uninstall', $token))
            ->assertRedirect(route('agent.fleet'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('agent_commands', [
            'agent_token_id' => $token->id,
            'command' => 'uninstall',
            'status' => 'pending',
        ]);

        $this->assertTrue(
            $token->fresh()->is_active,
            'Token must stay active until the agent confirms it removed itself.'
        );
    }

    public function test_fleet_shows_queued_state_instead_of_a_second_uninstall_button(): void
    {
        $token = $this->enrollAgent();

        // The controller redirects with back(), so the referer has to be set
        // for the assertion to point at the fleet page.
        $this->actingAs($this->admin)
            ->from(route('agent.fleet'))
            ->post(route('agent.uninstall', $token))
            ->assertRedirect(route('agent.fleet'));

        $this->actingAs($this->admin)
            ->get(route('agent.fleet'))
            ->assertOk()
            ->assertSee('Uninstall queued')
            ->assertDontSee('Remove the agent from field-laptop?', false);
    }

    public function test_uninstall_requires_authentication(): void
    {
        $token = $this->enrollAgent();

        $this->post(route('agent.uninstall', $token))
            ->assertRedirect('/login');

        $this->assertSame(0, AgentCommand::count());
    }

    public function test_user_without_permission_cannot_uninstall_an_agent(): void
    {
        $token = $this->enrollAgent();
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)
            ->post(route('agent.uninstall', $token))
            ->assertForbidden();

        $this->assertSame(0, AgentCommand::count());
    }

    /**
     * The agent acks the uninstall with a fixed marker string. Only that exact
     * confirmation burns the token: an agent too old to know the command acks
     * 'done' with no result, and must not be treated as removed.
     */
    public function test_confirmed_uninstall_ack_revokes_the_token(): void
    {
        $token = $this->enrollAgent();

        $this->actingAs($this->admin)
            ->from(route('agent.fleet'))
            ->post(route('agent.uninstall', $token))
            ->assertRedirect(route('agent.fleet'));

        $command = AgentCommand::firstOrFail();

        $this->postJson("/api/agent/commands/{$command->id}/ack", [
            'status' => 'done',
            'result' => [
                'message' => AgentCommandApiController::UNINSTALL_DONE_MARKER,
            ],
        ], $this->bearer($token))->assertOk();

        $this->assertFalse(
            $token->fresh()->is_active,
            'A confirmed self-removal must revoke the token.'
        );
    }

    public function test_uninstall_ack_without_the_marker_leaves_the_token_active(): void
    {
        $token = $this->enrollAgent();

        $this->actingAs($this->admin)
            ->from(route('agent.fleet'))
            ->post(route('agent.uninstall', $token))
            ->assertRedirect(route('agent.fleet'));

        $command = AgentCommand::firstOrFail();

        // What an agent that predates the uninstall command replies.
        $this->postJson("/api/agent/commands/{$command->id}/ack", [
            'status' => 'done',
        ], $this->bearer($token))->assertOk();

        $this->assertTrue(
            $token->fresh()->is_active,
            'An unconfirmed removal must keep the token so the user can retry.'
        );
    }

    /** Auth header the daemon uses for the inbox/ack endpoints. */
    private function bearer(AgentApiToken $token): array
    {
        return ['Authorization' => 'Bearer ' . $token->token_plain];
    }
}
