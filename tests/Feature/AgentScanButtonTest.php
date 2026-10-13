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

    public function test_asset_detail_scan_returns_json_for_the_action_modal(): void
    {
        $token = $this->enrollAgent();

        $this->actingAs($this->admin)
            ->postJson(route('assets.audit.agent.scan', $this->asset))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'Scan command queued. The audit agent on this device will pick it up within a few seconds and send the scan result.');

        $this->assertDatabaseHas('agent_commands', [
            'agent_token_id' => $token->id,
            'command' => 'scan',
            'status' => 'pending',
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
     * "Scan All Devices" targets each device instead of broadcasting.
     *
     * A broadcast row has no target, so no agent can ever settle it honestly:
     * the fleet page kept showing "queued" after every machine had already
     * scanned, and per-device progress and outcome had nowhere to land. One row
     * per reachable device is what makes the summary count true.
     */
    public function test_scan_all_queues_one_targeted_command_per_reachable_device(): void
    {
        $first = $this->enrollAgent();

        $second = AgentApiToken::create([
            'name' => 'field-laptop-2',
            'device_id' => 'ZGV2aWNlLTAwMg==',
            'platform' => 'Windows 11',
            'token_hash' => hash('sha256', $plain = 'tat_'.Str::random(48)),
            'token_plain' => $plain,
        ]);

        // Revoked: the middleware refuses its bearer, so a command for it can
        // never be picked up and must not be queued at all.
        AgentApiToken::create([
            'name' => 'retired-laptop',
            'device_id' => 'ZGV2aWNlLTAwMw==',
            'platform' => 'Windows 11',
            'token_hash' => hash('sha256', 'tat_'.Str::random(48)),
            'is_active' => false,
        ]);

        $this->actingAs($this->admin)
            ->from(route('agent.fleet'))
            ->post(route('agent.scanAll'))
            ->assertRedirect(route('agent.fleet'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('agent_commands', [
            'agent_token_id' => $first->id,
            'command' => 'scan',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('agent_commands', [
            'agent_token_id' => $second->id,
            'command' => 'scan',
            'status' => 'pending',
        ]);

        $this->assertSame(0, AgentCommand::whereNull('agent_token_id')->count());
        $this->assertSame(2, AgentCommand::count());
    }

    /**
     * A device already waiting for a scan is skipped, so one click does not
     * hand the same machine two identical scans and light up two pending rows
     * for work that is already on its way.
     */
    public function test_scan_all_skips_a_device_that_is_already_waiting(): void
    {
        $token = $this->enrollAgent();

        AgentCommand::create([
            'agent_token_id' => $token->id,
            'command' => 'scan',
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin)
            ->from(route('agent.fleet'))
            ->post(route('agent.scanAll'))
            ->assertSessionHas('success');

        $this->assertSame(1, AgentCommand::count());
    }

    public function test_scan_all_returns_json_for_the_in_place_action_modal(): void
    {
        $this->enrollAgent();

        $this->actingAs($this->admin)
            ->postJson(route('agent.scanAll'))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'Scan queued for 1 device(s) — each agent picks it up within a few seconds.');
    }

    public function test_rejected_ajax_scan_returns_an_error_for_the_action_modal(): void
    {
        $token = $this->enrollAgent(['is_active' => false]);

        $this->actingAs($this->admin)
            ->postJson(route('agent.scan', $token))
            ->assertUnprocessable()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', "'field-laptop' is revoked or its token has expired, so the agent can no longer be reached and a scan cannot be delivered. Issue a new token and redeploy the agent on that machine first.");
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
            ->assertDontSee('Remove the agent from')
            ->assertDontSee('uninstall-agent-'.$token->id, false);
    }

    /**
     * The uninstall confirmation is an in-app modal, not a native confirm().
     * A native dialog froze the whole page and could not carry the explanation
     * of what the agent is about to delete.
     */
    public function test_fleet_uninstall_asks_for_confirmation_in_a_modal(): void
    {
        $token = $this->enrollAgent();

        $this->actingAs($this->admin)
            ->get(route('agent.fleet'))
            ->assertOk()
            // The trigger button and the dialog that it opens.
            ->assertSee('open-modal', false)
            ->assertSee('uninstall-agent-'.$token->id, false)
            ->assertSee('Remove the agent from', false)
            // The consequences spelled out, as prose rather than one run-on line.
            ->assertSee('deletes its autostart entry', false)
            ->assertSee('cannot be undone', false)
            // The dialog carries the real POST form, so no JS confirm() needed.
            ->assertSee(route('agent.uninstall', $token), false)
            // Nothing may fall back to the browser's built-in dialog.
            ->assertDontSee('confirm(', false)
            ->assertDontSee('onsubmit', false);
    }

    public function test_uninstall_requires_authentication(): void
    {
        $token = $this->enrollAgent();

        $this->post(route('agent.uninstall', $token))
            ->assertRedirect('/login');

        $this->assertSame(0, AgentCommand::count());
    }

    public function test_uninstall_is_refused_for_an_already_revoked_token(): void
    {
        $token = $this->enrollAgent(['name' => 'revoked-laptop']);
        $token->update(['is_active' => false]);

        // The agent middleware answers 401 for an inactive token, so a command
        // queued here could never be picked up. Queueing it anyway left the
        // fleet page showing "Uninstall queued" forever.
        $this->actingAs($this->admin)
            ->from(route('agent.fleet'))
            ->post(route('agent.uninstall', $token))
            ->assertRedirect(route('agent.fleet'))
            ->assertSessionHas('error');

        $this->assertSame(0, AgentCommand::count(), 'A revoked token must not accumulate commands.');
    }

    /**
     * A revoked device is moved to its own card, which is what finally clears
     * the stuck "Uninstall queued" badge: an agent whose token cannot
     * authenticate is never listed among the machines you can act on.
     */
    public function test_fleet_moves_a_revoked_device_to_the_revoked_card(): void
    {
        $token = $this->enrollAgent(['name' => 'revoked-laptop']);

        $this->actingAs($this->admin)
            ->from(route('agent.fleet'))
            ->post(route('agent.uninstall', $token))
            ->assertRedirect(route('agent.fleet'));

        $token->update(['is_active' => false]);

        // The redirect above left a "Uninstall queued for ..." flash banner, which
        // would be a false positive here. Consume it on a throwaway render.
        $this->actingAs($this->admin)->get(route('agent.fleet'))->assertOk();

        $this->actingAs($this->admin)
            ->get(route('agent.fleet'))
            ->assertOk()
            ->assertSee('Revoked Devices')
            ->assertViewHas('revokedDevices', fn ($list) => $list->contains('id', $token->id))
            // Out of the actionable table, so no queue badge and no button.
            ->assertViewHas('activeDevices', fn ($list) => ! $list->contains('id', $token->id))
            ->assertDontSee('Uninstall queued')
            ->assertDontSee('uninstall-agent-'.$token->id, false);
    }

    public function test_fleet_still_shows_queued_for_a_deliverable_uninstall(): void
    {
        $token = $this->enrollAgent();

        $this->actingAs($this->admin)
            ->from(route('agent.fleet'))
            ->post(route('agent.uninstall', $token))
            ->assertRedirect(route('agent.fleet'));

        $this->actingAs($this->admin)
            ->get(route('agent.fleet'))
            ->assertOk()
            ->assertSee('Uninstall queued')
            ->assertDontSee('Not delivered', false);
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
        return ['Authorization' => 'Bearer '.$token->token_plain];
    }
}
