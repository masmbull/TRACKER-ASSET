<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AgentCommandApiController;
use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Resident daemon fleet: inbox polling, command enqueuing, auto-update (PRD §11).
 */
class ResidentDaemonTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private AgentApiToken $apiToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = sprintf('tat_%s', Str::random(48));

        $this->apiToken = AgentApiToken::create([
            'name' => 'laptop-dev',
            'token_hash' => hash('sha256', $this->token),
            'abilities' => ['audit:submit', 'assets:lookup'],
        ]);
    }

    private function bearer(): array
    {
        return ['Authorization' => sprintf('Bearer %s', $this->token)];
    }

    // --- API inbox + ack -----------------------------------------------------

    public function test_inbox_returns_pending_commands_for_token()
    {
        AgentCommand::create(['agent_token_id' => $this->apiToken->id, 'command' => 'scan']);
        AgentCommand::create(['agent_token_id' => null, 'command' => 'update']);

        $response = $this->withHeaders($this->bearer())->getJson('/api/agent/inbox');

        $response->assertOk();
        $this->assertCount(2, $response->json('commands'));
    }

    /**
     * `agent_token_id` is nullOnDelete, so deleting a token row rewrites its
     * pending uninstall into a fleet-wide broadcast. A live agent then picks it
     * up and removes itself — verified in production, where a single deleted
     * token took out every machine that polled in the next 10 seconds.
     *
     * Uninstall is only ever created per device, so a null target is always
     * corruption rather than an intent, and the inbox must refuse it.
     */
    public function test_uninstall_is_never_delivered_as_a_broadcast(): void
    {
        $orphan = AgentCommand::create([
            'agent_token_id' => null,
            'command' => 'uninstall',
            'status' => 'pending',
        ]);

        $this->getJson('/api/agent/inbox', $this->bearer())
            ->assertOk()
            ->assertJsonPath('commands', [])
            ->assertJsonMissing(['id' => $orphan->id]);

        $this->assertSame('pending', $orphan->fresh()->status, 'The command must be left alone, not silently taken.');
    }

    /** A broadcast scan is legitimate and must keep working. */
    public function test_broadcast_commands_are_still_delivered(): void
    {
        AgentCommand::create([
            'agent_token_id' => null,
            'command' => 'scan',
            'status' => 'pending',
        ]);

        $this->getJson('/api/agent/inbox', $this->bearer())
            ->assertOk()
            ->assertJsonCount(1, 'commands');
    }

    /**
     * A command settles exactly once.
     *
     * A broadcast is acked by whichever agent answers first, so without this a
     * slow second agent could overwrite a real 'done' with its own 'failed'.
     * The same overwrite was possible the other way round: the stale resolver
     * flips a row to 'failed' after 10 minutes, and an agent that was merely
     * slow would then erase that verdict with its own success.
     */
    public function test_a_command_can_only_be_settled_once(): void
    {
        $command = AgentCommand::create([
            'agent_token_id' => null,
            'command' => 'scan',
            'status' => 'pending',
        ]);

        $this->postJson("/api/agent/commands/{$command->id}/ack", [
            'status' => 'done',
        ], $this->bearer())->assertOk();

        $this->postJson("/api/agent/commands/{$command->id}/ack", [
            'status' => 'failed',
        ], $this->bearer())->assertOk();

        $this->assertSame('done', $command->fresh()->status, 'The late ack must not overwrite the first verdict.');
    }

    /**
     * The resolver and the agent race: a command the resolver already gave up on
     * stays failed even if the agent eventually completes it.
     */
    public function test_an_ack_cannot_overwrite_a_verdict_from_the_stale_resolver(): void
    {
        $command = AgentCommand::create([
            'agent_token_id' => $this->apiToken->id,
            'command' => 'uninstall',
            'status' => 'failed',
            'result' => ['message' => 'Not delivered: the agent token was revoked or had expired...'],
        ]);

        $this->postJson("/api/agent/commands/{$command->id}/ack", [
            'status' => 'done',
        ], $this->bearer())->assertOk();

        $this->assertSame('failed', $command->fresh()->status);
    }

    /**
     * A late ack must not be able to revoke a token, or a second poll after an
     * uninstall could re-trigger the side effect the first one already caused.
     */
    public function test_a_replayed_uninstall_ack_does_not_revoke_again(): void
    {
        $command = AgentCommand::create([
            'agent_token_id' => $this->apiToken->id,
            'command' => 'uninstall',
            'status' => 'pending',
        ]);

        $payload = ['status' => 'done', 'result' => ['message' => AgentCommandApiController::UNINSTALL_DONE_MARKER]];

        $this->postJson("/api/agent/commands/{$command->id}/ack", $payload, $this->bearer())->assertOk();
        $this->assertFalse((bool) $this->apiToken->fresh()->is_active);

        // Re-enable, then replay: the token must survive the second ack.
        $this->apiToken->update(['is_active' => true]);

        $this->postJson("/api/agent/commands/{$command->id}/ack", $payload, $this->bearer())->assertOk();

        $this->assertTrue((bool) $this->apiToken->fresh()->is_active, 'A replayed ack must not re-run the side effect.');
    }

    public function test_ack_marks_command_done()
    {
        $command = AgentCommand::create(['agent_token_id' => $this->apiToken->id, 'command' => 'scan']);

        $this->withHeaders($this->bearer())
            ->postJson(sprintf('/api/agent/commands/%s/ack', $command->id), ['status' => 'done'])
            ->assertOk();

        $command->refresh();
        $this->assertSame('done', $command->status);
        $this->assertNotNull($command->executed_at);
    }

    public function test_wrong_token_cannot_ack()
    {
        $otherToken = sprintf('tat_%s', Str::random(48));
        AgentApiToken::create(['name' => 'other', 'token_hash' => hash('sha256', $otherToken)]);
        $command = AgentCommand::create(['agent_token_id' => $this->apiToken->id, 'command' => 'scan']);

        $this->withHeaders(['Authorization' => sprintf('Bearer %s', $otherToken)])
            ->postJson(sprintf('/api/agent/commands/%s/ack', $command->id), ['status' => 'done'])
            ->assertForbidden();

        $this->assertSame('pending', $command->fresh()->status);
    }

    public function test_inbox_without_token_returns_401()
    {
        $this->getJson('/api/agent/inbox')->assertUnauthorized();
    }

    public function test_scan_now_enqueues_command()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('agent.scan', $this->apiToken))
            ->assertRedirect();

        $this->assertDatabaseCount('agent_commands', 1);
        $this->assertDatabaseHas('agent_commands', [
            'command' => 'scan',
            'agent_token_id' => $this->apiToken->id,
            'status' => 'pending',
        ]);
    }

    /**
     * "Scan now" on a dead token must refuse, not queue.
     *
     * AssetController::agentScan and the uninstall action both already refused
     * an unusable token; this button did not, so it would create a command the
     * middleware rejects with 401 and then tell the operator it was queued.
     */
    public function test_scan_now_refuses_a_token_the_agent_can_no_longer_use(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->apiToken->update(['expires_at' => now()->subDay()]);

        $this->actingAs($admin)->post(route('agent.scan', $this->apiToken))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, AgentCommand::count());
    }

    /**
     * A revoked token is the other half of the same rule, and the one the fleet
     * page is most likely to be clicked from.
     */
    public function test_scan_now_refuses_a_revoked_token(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->apiToken->update(['is_active' => false]);

        $this->actingAs($admin)->post(route('agent.scan', $this->apiToken))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, AgentCommand::count());
    }

    /**
     * Manual sweep shares the scheduled sweep's rule. It used to filter on
     * `is_active` alone, so every click queued a scan for expired tokens too.
     */
    public function test_manual_sweep_skips_expired_tokens(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->apiToken->update(['expires_at' => now()->subDay()]);

        $this->actingAs($admin)->post(route('agent.sweep'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(0, AgentCommand::count());
    }

    /**
     * Scan All targets each device instead of broadcasting.
     *
     * A broadcast row is one shared command no agent can settle honestly: the
     * page stayed "queued" after every machine had scanned, and per-device
     * progress had nowhere to land. Update All still broadcasts because it is
     * filtered per device at hand-out (see AgentLongPollTest); a scan has no
     * such filter and has to be addressed to a machine to be acknowledged.
     */
    public function test_scan_all_targets_each_device(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('agent.scanAll'))->assertRedirect();

        $this->assertDatabaseCount('agent_commands', 1);
        $this->assertDatabaseHas('agent_commands', [
            'command' => 'scan',
            'agent_token_id' => $this->apiToken->id,
            'status' => 'pending',
        ]);
    }

    public function test_update_all_targets_each_behind_device()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('agent.updateAll'))->assertRedirect();

        // One command per behind device, not one broadcast row. A shared row was
        // settled by whichever agent acked first, closing the rollout dialog
        // while the rest of the fleet was still updating.
        $this->assertDatabaseCount('agent_commands', 1);
        $this->assertDatabaseHas('agent_commands', [
            'command' => 'update',
            'agent_token_id' => $this->apiToken->id,
            'status' => 'pending',
        ]);
    }

    /**
     * The update command must advertise the binary download, not the retired
     * PowerShell script endpoint. A deployed agent only ever fetches an exe, so
     * pointing the payload at a .ps1 meant the fleet page promised a file that
     * no installed agent would ever look for.
     */
    public function test_update_all_points_agents_at_the_binary_download()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('agent.updateAll'))->assertRedirect();

        $command = AgentCommand::firstOrFail();

        $this->assertSame('update', $command->command);
        $this->assertSame(
            route('agent.download.latest'),
            $command->payload['url'] ?? null,
            'The update payload must carry the exe download route.'
        );
        $this->assertStringNotContainsString('.ps1', $command->payload['url'] ?? '');
    }

    public function test_viewer_cannot_enqueue()
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->post(route('agent.scan', $this->apiToken))->assertForbidden();
        $this->actingAs($viewer)->post(route('agent.scanAll'))->assertForbidden();
    }

    // --- Retired PowerShell agent -------------------------------------------
    //
    // The .ps1 collector and daemon were replaced by TrackerAgent.exe. The
    // serving endpoints (/agent/run.ps1, /agent/daemon.ps1) are gone; this test
    // exists to prove it, so a stale install command fails loudly instead of
    // silently downloading nothing.
    public function test_retired_powershell_agent_endpoints_are_gone(): void
    {
        $this->get('/agent/run.ps1?token='.$this->token)->assertNotFound();
        $this->get('/agent/daemon.ps1?token='.$this->token)->assertNotFound();
    }

    public function test_commands_page_renders_with_sweep_button()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('agent.commands'))
            ->assertOk()
            ->assertSee('Sweep Now')
            ->assertSee('confirm-update-all-agents', false)
            ->assertSee('Update all agents');
    }

    public function test_commands_page_autorefreshes_while_pending()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        AgentCommand::create(['agent_token_id' => $this->apiToken->id, 'command' => 'scan']);

        // Soft-poll, not a full reload: only the commands region refreshes, so
        // scroll position and open modals survive the tick.
        $this->actingAs($admin)->get(route('agent.commands'))
            ->assertOk()
            ->assertSee('data-live-region="commands"', false)
            ->assertSee('data-poll-interval="10"', false)
            ->assertDontSee('window.location.reload()', false);
    }

    public function test_commands_page_keeps_polling_without_pending_commands()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // A command can be queued, run and settled *after* this page was opened.
        // Starting the timer only when one was already pending at page load meant
        // an operator who opened the page a moment too early never saw that
        // result without a manual reload. The region poll is soft (no shell
        // reload), so keeping it running costs one small HTML fetch per tick.
        $this->actingAs($admin)->get(route('agent.commands'))
            ->assertOk()
            ->assertSee('data-live-region="commands"', false)
            ->assertSee('data-poll-interval="10"', false);
    }

    public function test_dashboard_shows_fleet_health()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->apiToken->update(['last_used_at' => now()]);
        AgentCommand::create(['agent_token_id' => $this->apiToken->id, 'command' => 'scan']);

        $this->actingAs($admin)->get('/')
            ->assertOk()
            ->assertSee('Agent Fleet')
            ->assertViewHas('fleet', fn (array $fleet) => $fleet['online'] === 1 && $fleet['pending_commands'] === 1);
    }

    /**
     * A pending command aimed at a revoked token is undeliverable by
     * construction: the agent middleware answers 401 before the inbox is read.
     * Without a sweep it stayed `pending` forever, which is what pinned the
     * fleet page to a permanent "Uninstall queued" badge.
     */
    public function test_stale_resolver_fails_commands_whose_token_is_revoked(): void
    {
        $command = AgentCommand::create([
            'agent_token_id' => $this->apiToken->id,
            'command' => 'uninstall',
            'status' => 'pending',
        ]);

        $command->forceFill(['created_at' => now()->subHour()])->save();
        $this->apiToken->update(['is_active' => false]);

        $this->artisan('agent:resolve-stale-commands')
            ->assertExitCode(0)
            ->expectsOutputToContain('Resolved 1');

        $command->refresh();

        $this->assertSame('failed', $command->status);
        $this->assertNotNull($command->executed_at);
        $this->assertStringContainsString('revoked', $command->result['message']);
    }

    /**
     * An offline laptop is a normal state: its queued uninstall must survive so
     * it still runs when the machine comes back.
     */
    public function test_stale_resolver_leaves_deliverable_commands_alone(): void
    {
        $command = AgentCommand::create([
            'agent_token_id' => $this->apiToken->id,
            'command' => 'uninstall',
            'status' => 'pending',
        ]);

        $command->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->artisan('agent:resolve-stale-commands')->assertExitCode(0);

        $this->assertSame('pending', $command->fresh()->status);
    }

    /**
     * A broadcast is claimed by any live agent, so revoking one token must not
     * fail the broadcast for everyone else.
     */
    public function test_stale_resolver_leaves_broadcasts_alone(): void
    {
        $this->apiToken->update(['is_active' => false]);

        $command = AgentCommand::create([
            'agent_token_id' => null,
            'command' => 'scan',
            'status' => 'pending',
        ]);

        $command->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->artisan('agent:resolve-stale-commands')->assertExitCode(0);

        $this->assertSame('pending', $command->fresh()->status);
    }

    /**
     * Expired is the same dead end as revoked: `isUsable()` refuses it, so the
     * middleware answers 401 before the inbox is read. Matching only
     * `is_active` here left these rows pending forever.
     */
    public function test_stale_resolver_fails_commands_whose_token_has_expired(): void
    {
        $command = AgentCommand::create([
            'agent_token_id' => $this->apiToken->id,
            'command' => 'uninstall',
            'status' => 'pending',
        ]);

        $command->forceFill(['created_at' => now()->subHour()])->save();
        $this->apiToken->update(['expires_at' => now()->subDay()]);

        $this->artisan('agent:resolve-stale-commands')
            ->assertExitCode(0)
            ->expectsOutputToContain('Resolved 1');

        $this->assertSame('failed', $command->fresh()->status);
        $this->assertStringContainsString('expired', $command->fresh()->result['message']);
    }

    /**
     * A token that is still valid but expires tomorrow is offline, not dead.
     * Failing its queued command would uninstall the agent from a laptop that
     * is simply switched off.
     */
    public function test_stale_resolver_leaves_commands_for_a_token_that_has_not_expired_yet(): void
    {
        $command = AgentCommand::create([
            'agent_token_id' => $this->apiToken->id,
            'command' => 'uninstall',
            'status' => 'pending',
        ]);

        $command->forceFill(['created_at' => now()->subDays(3)])->save();
        $this->apiToken->update(['expires_at' => now()->addDay()]);

        $this->artisan('agent:resolve-stale-commands')->assertExitCode(0);

        $this->assertSame('pending', $command->fresh()->status);
    }

    /**
     * Queuing a scan for an expired token creates a command that can never be
     * delivered, and the weekly sweep would re-create one every run.
     */
    public function test_audit_sweep_does_not_queue_scans_for_expired_tokens(): void
    {
        $this->apiToken->update(['expires_at' => now()->subDay()]);

        $this->artisan('audit:sweep')
            ->assertExitCode(0)
            ->expectsOutputToContain('No active agent tokens found.');

        $this->assertDatabaseCount('agent_commands', 0);
    }

    public function test_audit_sweep_queues_scan_per_active_token()
    {
        $second = AgentApiToken::create(['name' => 'other', 'token_hash' => hash('sha256', 'bogus')]);

        $this->artisan('audit:sweep')
            ->assertExitCode(0)
            ->expectsOutputToContain('queued 2, skipped 0');

        $this->assertDatabaseCount('agent_commands', 2);
        $this->assertDatabaseHas('agent_commands', [
            'command' => 'scan',
            'agent_token_id' => $this->apiToken->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('agent_commands', [
            'agent_token_id' => $second->id,
        ]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'AUDIT_SWEEP']);
    }

    public function test_audit_sweep_skips_agents_with_pending_or_recent_scan()
    {
        AgentCommand::create(['agent_token_id' => $this->apiToken->id, 'command' => 'scan']);

        $this->artisan('audit:sweep')->assertExitCode(0);

        // No second command queued for the busy token.
        $this->assertCount(1, AgentCommand::where('agent_token_id', $this->apiToken->id)->get());
    }

    public function test_audit_sweep_accepts_single_token_filter()
    {
        AgentApiToken::create(['name' => 'skipped', 'token_hash' => hash('sha256', 'other')]);

        $this->artisan('audit:sweep', ['--token' => 'laptop-dev', '--force' => true])
            ->assertExitCode(0);

        $this->assertDatabaseCount('agent_commands', 1);
    }

    public function test_manual_sweep_queues_for_idle_agents_only()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $busy = AgentApiToken::create(['name' => 'busy', 'token_hash' => hash('sha256', 'busy-secret')]);
        AgentCommand::create(['agent_token_id' => $busy->id, 'command' => 'scan']);

        $response = $this->actingAs($admin)->post(route('agent.sweep'));

        $response->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('agent_commands', [
            'command' => 'scan',
            'agent_token_id' => $this->apiToken->id,
        ]);
        $this->assertCount(1, AgentCommand::where('agent_token_id', $busy->id)->get());
    }
}
