<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use App\Models\AgentFingerprint;
use App\Models\AgentTelemetry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deleting a device from the fleet (agent.destroy).
 *
 * This is the hard delete an operator reaches for when a laptop is scrapped or
 * re-imaged: unlike revoke, nothing about the machine should remain on the
 * fleet page, including its queued work and its last inventory snapshot.
 */
class FleetDeviceDeleteTest extends TestCase
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

    public function test_deleting_a_device_removes_its_commands_telemetry_and_fingerprint(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $token = $this->device('field-laptop');
        $other = $this->device('spare-laptop');

        $command = AgentCommand::create([
            'agent_token_id' => $token->id,
            'command' => 'scan',
            'status' => 'pending',
        ]);

        AgentTelemetry::create([
            'agent_token_id' => $token->id,
            'hostname' => 'HOST-DELETE-01',
            'reported_at' => now(),
        ]);

        AgentFingerprint::create([
            'agent_token_id' => $token->id,
            'device_id' => 'device-delete-01',
            'collected_at' => now(),
        ]);

        $otherCommand = AgentCommand::create([
            'agent_token_id' => $other->id,
            'command' => 'scan',
            'status' => 'pending',
        ]);

        AgentTelemetry::create([
            'agent_token_id' => $other->id,
            'hostname' => 'HOST-KEEP-01',
            'reported_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->from(route('agent.fleet'))
            ->post(route('agent.destroy', $token));

        $response->assertRedirect(route('agent.fleet'));
        $response->assertSessionHas('success', "Device 'field-laptop' deleted from the fleet.");

        $this->assertDatabaseMissing('agent_api_tokens', ['id' => $token->id]);
        $this->assertDatabaseMissing('agent_commands', ['id' => $command->id]);
        $this->assertDatabaseMissing('agent_telemetries', ['agent_token_id' => $token->id]);
        $this->assertDatabaseMissing('agent_fingerprints', ['agent_token_id' => $token->id]);

        // Deleting one laptop must not take the rest of the fleet with it.
        $this->assertDatabaseHas('agent_api_tokens', ['id' => $other->id]);
        $this->assertDatabaseHas('agent_commands', ['id' => $otherCommand->id]);
        $this->assertDatabaseHas('agent_telemetries', [
            'agent_token_id' => $other->id,
            'hostname' => 'HOST-KEEP-01',
        ]);

        $log = ActivityLog::where('action', 'DELETE')
            ->where('entity_type', 'agent_tokens')
            ->where('entity_id', $token->id)
            ->firstOrFail();

        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('field-laptop', $log->entity_identifier);
        $this->assertSame('Live Fleet', $log->source);
        $this->assertSame(['device' => 'field-laptop'], $log->metadata);
    }

    public function test_the_fleet_page_can_delete_over_json(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $token = $this->device('field-laptop');

        $this->actingAs($admin)
            ->postJson(route('agent.destroy', $token))
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'message' => "Device 'field-laptop' deleted from the fleet.",
            ]);

        $this->assertDatabaseMissing('agent_api_tokens', ['id' => $token->id]);
    }

    public function test_only_an_admin_can_delete_a_device(): void
    {
        foreach (['support', 'technician', 'viewer'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $token = $this->device("device-{$role}");

            $this->actingAs($user)
                ->post(route('agent.destroy', $token))
                ->assertForbidden();

            $this->assertDatabaseHas('agent_api_tokens', ['id' => $token->id]);
        }
    }
}
