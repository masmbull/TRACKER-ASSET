<?php

namespace Tests\Feature;

use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Revoking a device token (agent.revoke).
 *
 * A revoke is not just a flag: the machine can no longer authenticate, so any
 * command still queued for it will never run. Leaving those rows pending would
 * keep the fleet page promising an uninstall that cannot happen.
 */
class AgentTokenRevokeTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $name): AgentApiToken
    {
        return AgentApiToken::create([
            'name' => $name,
            'token_hash' => hash('sha256', $name),
            'token_plain' => $name,
        ]);
    }

    private function command(AgentApiToken $token, string $status, string $command = 'scan'): AgentCommand
    {
        return AgentCommand::create([
            'agent_token_id' => $token->id,
            'command' => $command,
            'status' => $status,
        ]);
    }

    public function test_revoke_blocks_the_device_and_closes_only_its_pending_commands(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $token = $this->device('field-laptop');
        $other = $this->device('spare-laptop');

        $pending = $this->command($token, 'pending', 'uninstall');
        $running = $this->command($token, 'in_progress');
        $otherPending = $this->command($other, 'pending');

        $response = $this->actingAs($admin)
            ->from(route('agent.index'))
            ->post(route('agent.revoke', $token));

        $response->assertRedirect(route('agent.index'));
        $response->assertSessionHas('success');

        $this->assertFalse($token->fresh()->is_active);

        $pending->refresh();
        $this->assertSame('failed', $pending->status);
        $this->assertNotNull($pending->executed_at);
        $this->assertSame(
            ['message' => 'Not delivered: the agent token was revoked.'],
            $pending->result
        );

        // A command already picked up is a record of what happened, not a
        // delivery promise, and another device's queue is none of its business.
        $this->assertSame('in_progress', $running->fresh()->status);
        $this->assertSame('pending', $otherPending->fresh()->status);
    }

    public function test_revoke_reports_how_many_queued_commands_were_closed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $token = $this->device('field-laptop');

        $this->command($token, 'pending');
        $this->command($token, 'pending');

        $this->actingAs($admin)
            ->post(route('agent.revoke', $token))
            ->assertSessionHas(
                'success',
                "Device 'field-laptop' revoked. 2 queued command(s) closed as undeliverable."
            );
    }

    public function test_revoke_without_queued_work_does_not_claim_any(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $token = $this->device('field-laptop');

        $this->actingAs($admin)
            ->post(route('agent.revoke', $token))
            ->assertSessionHas('success', "Device 'field-laptop' revoked.");
    }

    public function test_only_an_admin_can_revoke_a_device(): void
    {
        foreach (['support', 'technician', 'viewer'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $token = $this->device("device-{$role}");

            $this->actingAs($user)
                ->post(route('agent.revoke', $token))
                ->assertForbidden();

            $this->assertTrue($token->fresh()->is_active);
        }
    }
}
