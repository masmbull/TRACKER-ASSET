<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AgentCommandApiController;
use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Progress reporting for install, update and uninstall.
 *
 * The property being protected is honesty about state. A queued badge says the
 * same thing whether an agent picked the work up one second ago, is 80% through
 * it, or died holding it - and during a rollout that is the only question an
 * operator is actually asking. Every test here pins a case where a plausible
 * implementation would show something reassuring that is not true.
 */
class CommandProgressTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private AgentApiToken $agentToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = 'tat_'.Str::random(48);

        $this->agentToken = AgentApiToken::create([
            'name' => 'progress-laptop',
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

    private function pendingCommand(string $command = 'uninstall'): AgentCommand
    {
        return AgentCommand::create([
            'agent_token_id' => $this->agentToken->id,
            'command' => $command,
            'status' => 'pending',
        ]);
    }

    public function test_an_agent_can_report_progress_on_a_queued_command(): void
    {
        $command = $this->pendingCommand();

        $this->postJson("/api/agent/commands/{$command->id}/progress", [
            'stage' => 'Removing the agent...',
            'progress' => 40,
        ], $this->headers())->assertOk()->assertJsonPath('status', 'in_progress');

        $command->refresh();

        $this->assertSame('in_progress', $command->status);
        $this->assertSame(40, $command->progress);
        $this->assertSame('Removing the agent...', $command->stage);
        $this->assertNotNull($command->started_at);
    }

    /**
     * The bug this whole feature would have shipped with.
     *
     * ack used to reject anything that was not 'pending'. An agent that reports
     * progress and then acknowledges - the normal order - would be told its
     * command was "already settled", so the row sat at in_progress forever and
     * the page showed a permanent progress bar for a job that had actually
     * finished.
     */
    public function test_acknowledging_after_reporting_progress_settles_the_command(): void
    {
        $command = $this->pendingCommand();

        $this->postJson("/api/agent/commands/{$command->id}/progress", [
            'stage' => 'Removing the agent...',
            'progress' => 40,
        ], $this->headers())->assertOk();

        $this->postJson("/api/agent/commands/{$command->id}/ack", [
            'status' => 'done',
        ], $this->headers())->assertOk();

        $command->refresh();

        $this->assertSame('done', $command->status);

        // The progress figures belong to the run that just ended. Left in place,
        // "Removing the agent (100%)" next to a failed uninstall reads as a
        // command that never actually finished.
        $this->assertNull($command->progress);
        $this->assertNull($command->stage);
    }

    /**
     * A settled command is history. A late progress report must not reopen it -
     * the same one-way rule ack already follows.
     */
    public function test_progress_for_a_settled_command_is_ignored(): void
    {
        $command = $this->pendingCommand();
        $command->update(['status' => 'done', 'executed_at' => now()]);

        $this->postJson("/api/agent/commands/{$command->id}/progress", [
            'stage' => 'Too late',
            'progress' => 90,
        ], $this->headers())->assertOk();

        $command->refresh();

        $this->assertSame('done', $command->status);
        $this->assertNull($command->progress);
    }

    /**
     * A command the agent picked up and then died on must come back.
     *
     * The inbox only handed out 'pending' rows, so an in_progress command whose
     * agent was killed was never delivered again and nothing else reset it. The
     * row would sit at 45% for the rest of time, indistinguishable from a
     * genuinely slow machine.
     */
    public function test_an_abandoned_in_progress_command_is_handed_out_again(): void
    {
        $command = $this->pendingCommand();

        // Deliberately through the query builder rather than Eloquent's update().
        // Eloquent touches updated_at on every save, so the age being set here
        // would be overwritten with "now" and the command would look live - which
        // is exactly the mistake that makes a stalled test pass for the wrong
        // reason, or fail for a reason the real code never had.
        DB::table('agent_commands')
            ->where('id', $command->id)
            ->update([
                'status' => 'in_progress',
                'progress' => 45,
                'stage' => 'Deleting files...',
                'started_at' => now()->subMinutes(30),
                'updated_at' => now()->subMinutes(30),
            ]);

        $inbox = $this->getJson('/api/agent/inbox', $this->headers())
            ->assertOk()
            ->json('commands');

        $this->assertContains($command->id, array_column($inbox, 'id'));
    }

    /**
     * The other half of that: a live agent mid-command must not be interrupted.
     *
     * Re-delivering a command that is still genuinely running would restart the
     * work - deleting files a second time, or reinstalling over a running copy.
     * The grace window is what separates "abandoned" from "slow".
     */
    public function test_a_live_in_progress_command_is_not_handed_out_again(): void
    {
        $command = $this->pendingCommand();

        DB::table('agent_commands')
            ->where('id', $command->id)
            ->update([
                'status' => 'in_progress',
                'progress' => 70,
                'stage' => 'Copying files...',
                'started_at' => now(),
                'updated_at' => now(),
            ]);

        $inbox = $this->getJson('/api/agent/inbox', $this->headers())
            ->assertOk()
            ->json('commands');

        $this->assertNotContains($command->id, array_column($inbox, 'id'));
    }

    public function test_the_installer_can_report_install_progress(): void
    {
        $this->postJson('/api/agent/install-progress', [
            'command' => 'install',
            'status' => 'in_progress',
            'stage' => 'Running the first hardware scan...',
            'progress' => 94,
        ], $this->headers())->assertOk()->assertJsonPath('status', 'in_progress');

        $command = AgentCommand::where('command', 'install')->firstOrFail();

        $this->assertSame(94, $command->progress);
        $this->assertSame('Running the first hardware scan...', $command->stage);
    }

    /**
     * Re-running the installer must adopt the open row, not stack a second one,
     * or the page would show two half-finished installs and no way to tell which
     * is real.
     */
    public function test_reporting_install_twice_updates_one_row(): void
    {
        foreach ([62, 86, 94] as $percent) {
            $this->postJson('/api/agent/install-progress', [
                'command' => 'install',
                'status' => 'in_progress',
                'progress' => $percent,
            ], $this->headers())->assertOk();
        }

        $this->assertSame(1, AgentCommand::where('command', 'install')->count());
        $this->assertSame(94, AgentCommand::where('command', 'install')->first()->progress);
    }

    public function test_a_finished_install_settles_and_clears_the_bar(): void
    {
        $this->postJson('/api/agent/install-progress', [
            'command' => 'install',
            'status' => 'in_progress',
            'progress' => 94,
        ], $this->headers())->assertOk();

        $this->postJson('/api/agent/install-progress', [
            'command' => 'install',
            'status' => 'done',
            'progress' => 100,
            'stage' => 'Installation complete',
        ], $this->headers())->assertOk()->assertJsonPath('status', 'done');

        $command = AgentCommand::where('command', 'install')->firstOrFail();

        $this->assertSame('done', $command->status);
        $this->assertNull($command->progress);
        $this->assertNotNull($command->executed_at);
    }

    /**
     * A machine that died mid-install must say so rather than sitting at a
     * confident percentage forever.
     */
    public function test_a_stalled_operation_is_reported_as_stalled(): void
    {
        $command = AgentCommand::create([
            'agent_token_id' => $this->agentToken->id,
            'command' => 'install',
            'status' => 'in_progress',
            'progress' => 45,
            'stage' => 'Copying agent files...',
            'started_at' => now()->subMinutes(20),
        ]);

        // Force the timestamp the check reads.
        $command->forceFill(['updated_at' => now()->subMinutes(20)])->save();

        $fresh = AgentCommand::find($command->id);

        $this->assertTrue($fresh->isStalled());
        $this->assertStringContainsString('stalled', $fresh->progressLabel());
        $this->assertStringContainsString('45%', $fresh->progressLabel());
    }

    public function test_a_live_operation_is_not_reported_as_stalled(): void
    {
        $command = AgentCommand::create([
            'agent_token_id' => $this->agentToken->id,
            'command' => 'install',
            'status' => 'in_progress',
            'progress' => 45,
            'stage' => 'Copying agent files...',
            'started_at' => now(),
        ]);

        $this->assertFalse($command->fresh()->isStalled());
    }

    /**
     * A command queued for an agent that never comes back is the same failure
     * seen from the other side, and it must read as stalled too - otherwise a
     * fleet-wide action waits forever on a laptop that is switched off.
     */
    public function test_a_pending_command_nobody_picked_up_is_stalled(): void
    {
        $command = AgentCommand::create([
            'agent_token_id' => $this->agentToken->id,
            'command' => 'update',
            'status' => 'pending',
        ]);

        $command->forceFill(['updated_at' => now()->subMinutes(10)])->save();

        $fresh = AgentCommand::find($command->id);

        $this->assertTrue($fresh->isStalled());
        $this->assertStringContainsString('waiting for the agent', $fresh->progressLabel());
    }

    /**
     * A freshly queued command is not stalled: the agent has not had its 10s
     * poll yet, and flagging it would flash "not responding" on every click.
     */
    public function test_a_freshly_queued_command_is_not_stalled(): void
    {
        $command = AgentCommand::create([
            'agent_token_id' => $this->agentToken->id,
            'command' => 'update',
            'status' => 'pending',
        ]);

        $this->assertFalse($command->fresh()->isStalled());
    }

    /**
     * The batch dialog labels each row by device, so the status endpoint has to
     * carry the name. A bare count of "0 of 3" cannot tell an operator which
     * laptop is behind.
     */
    public function test_command_status_reports_the_device_name(): void
    {
        $command = AgentCommand::create([
            'agent_token_id' => $this->agentToken->id,
            'command' => 'update',
            'status' => 'pending',
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson(route('agent.commands.status', $command))
            ->assertOk()
            ->assertJsonPath('device', $this->agentToken->name);
    }

    public function test_command_status_marks_an_uncollected_update_as_stalled(): void
    {
        $command = AgentCommand::create([
            'agent_token_id' => $this->agentToken->id,
            'command' => 'update',
            'status' => 'pending',
        ]);
        $command->forceFill(['updated_at' => now()->subMinutes(10)])->save();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson(route('agent.commands.status', $command))
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('stalled', true)
            ->assertJsonPath('label', 'waiting for the agent to respond');
    }

    /**
     * The page and the inbox used to hold separate copies of the same number,
     * kept in step only by a comment. Nothing would have failed if they drifted.
     *
     * The failure is lopsided and quiet: the operator is shown a red "stalled"
     * while the server still thinks the command is healthy and never retries it,
     * or the reverse, where a dead install is never retried and nothing is red.
     * One definition removes the possibility, and this test documents why it
     * matters.
     */
    public function test_the_page_and_the_inbox_agree_on_when_a_command_is_stalled(): void
    {
        $this->assertSame(
            AgentCommand::STALL_GRACE_MINUTES,
            AgentCommandApiController::IN_PROGRESS_GRACE_MINUTES,
            'the inbox redelivery window must be the same number the page renders stalled from'
        );
    }

    /**
     * The dialog shows a phase checklist, so each command exposes its phases and
     * which one is current. A settled command marks the last phase done; a queued
     * one sits on the first; a running one is partway through.
     */
    public function test_steps_describe_the_phases_of_a_command(): void
    {
        $queued = AgentCommand::create([
            'agent_token_id' => $this->agentToken->id,
            'command' => 'update',
            'status' => 'pending',
        ]);

        $steps = $queued->fresh()->steps();

        $this->assertNotEmpty($steps);
        $this->assertSame('Queued', $steps[0]['label']);
        $this->assertSame('active', $steps[0]['state']);
        $this->assertSame('pending', $steps[1]['state']);

        $queued->forceFill(['status' => 'done', 'progress' => 100])->save();
        $done = $queued->fresh()->steps();
        $this->assertSame('done', $done[count($done) - 1]['state']);
        $this->assertSame('done', $done[0]['state']);
    }

    public function test_a_failed_command_marks_a_failed_phase(): void
    {
        $command = AgentCommand::create([
            'agent_token_id' => $this->agentToken->id,
            'command' => 'script',
            'status' => 'failed',
            'progress' => 40,
        ]);

        $states = array_column($command->fresh()->steps(), 'state');

        $this->assertContains('failed', $states);
    }

    public function test_step_payload_reaches_the_status_endpoint(): void
    {
        $command = AgentCommand::create([
            'agent_token_id' => $this->agentToken->id,
            'command' => 'scan',
            'status' => 'in_progress',
            'progress' => 50,
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson(route('agent.commands.status', $command))
            ->assertOk()
            ->assertJsonStructure(['steps' => [['label', 'state']]]);
    }

    /**
     * A finished command is a record, not a promise. Last week's completed
     * update is not "stalled".
     */
    public function test_a_settled_command_is_never_reported_as_stalled(): void
    {
        $command = AgentCommand::create([
            'agent_token_id' => $this->agentToken->id,
            'command' => 'install',
            'status' => 'done',
            'executed_at' => now()->subWeek(),
            'started_at' => now()->subWeek(),
        ]);

        $this->assertFalse($command->fresh()->isStalled());
    }

    /**
     * The progress bar is the point of all of the above, so it has to be on the
     * page rather than only in the database.
     */
    public function test_the_fleet_page_renders_install_progress(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        AgentCommand::create([
            'agent_token_id' => $this->agentToken->id,
            'command' => 'install',
            'status' => 'in_progress',
            'progress' => 86,
            'stage' => 'Starting the background agent...',
            'started_at' => now(),
        ]);

        $this->actingAs($admin)->get(route('agent.fleet'))
            ->assertOk()
            ->assertSee('Starting the background agent...')
            ->assertSee('86%');
    }

    public function test_another_device_cannot_report_progress_on_a_command(): void
    {
        $command = $this->pendingCommand();

        $other = 'tat_'.Str::random(48);
        AgentApiToken::create([
            'name' => 'other-laptop',
            'device_id' => 'b3RoZXI=', 'token_hash' => hash('sha256', $other),
            'token_plain' => $other, 'is_active' => true,
        ]);

        $this->postJson("/api/agent/commands/{$command->id}/progress", [
            'stage' => 'not mine', 'progress' => 99,
        ], ['Authorization' => 'Bearer '.$other])->assertForbidden();
    }
}
