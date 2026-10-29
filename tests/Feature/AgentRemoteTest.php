<?php

namespace Tests\Feature;

use App\Models\AgentApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Remote button in Live Fleet.
 *
 * Everything here is about the credential: it must be unique per device, stored
 * encrypted, reachable only by admins, and never appear in a log or a list.
 */
class AgentRemoteTest extends TestCase
{
    use RefreshDatabase;

    private function device(array $attributes = []): AgentApiToken
    {
        return AgentApiToken::create(array_merge([
            'name' => 'MBUL',
            'token_hash' => hash('sha256', 'token-'.uniqid()),
            'token_plain' => 'plain-'.uniqid(),
            'is_active' => true,
            'rustdesk_id' => '149246498',
        ], $attributes));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_remote_returns_a_usable_session_for_a_ready_device(): void
    {
        $device = $this->device();

        $response = $this->actingAs($this->admin())
            ->postJson(route('agent.remote', $device))
            ->assertOk();

        $response->assertJson([
            'ok' => true,
            'id' => '149246498',
            'uri' => 'rustdesk://149246498',
        ]);

        $this->assertNotEmpty($response->json('password'));
    }

    /**
     * The password is stable per device by design: IT asked for unattended access,
     * and a value that changes under them breaks every saved connection.
     */
    public function test_password_is_reused_across_sessions(): void
    {
        $device = $this->device();
        $admin = $this->admin();

        $first = $this->actingAs($admin)->postJson(route('agent.remote', $device))->json('password');
        $second = $this->actingAs($admin)->postJson(route('agent.remote', $device))->json('password');

        $this->assertSame($first, $second);
    }

    public function test_rotate_issues_a_new_password_and_persists_it(): void
    {
        $device = $this->device();
        $admin = $this->admin();

        $original = $this->actingAs($admin)->postJson(route('agent.remote', $device))->json('password');

        $this->actingAs($admin)->postJson(route('agent.remote.rotate', $device))->assertOk();

        $this->assertNotSame($original, $device->fresh()->rustdesk_password);
    }

    /** Encrypted at rest: the column must not contain the plain value. */
    public function test_password_is_encrypted_in_the_database(): void
    {
        $device = $this->device();
        $this->actingAs($this->admin())->postJson(route('agent.remote', $device))->assertOk();

        $raw = \DB::table('agent_api_tokens')->where('id', $device->id)->value('rustdesk_password');

        $this->assertNotSame($device->fresh()->rustdesk_password, $raw);
        $this->assertStringNotContainsString('"', (string) $raw);
    }

    public function test_device_without_rustdesk_id_is_rejected(): void
    {
        $device = $this->device(['rustdesk_id' => null]);

        $this->actingAs($this->admin())
            ->postJson(route('agent.remote', $device))
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    public function test_revoked_device_is_rejected(): void
    {
        $device = $this->device(['is_active' => false]);

        $this->actingAs($this->admin())
            ->postJson(route('agent.remote', $device))
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    public function test_non_admin_cannot_start_a_session(): void
    {
        $device = $this->device();
        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)
            ->postJson(route('agent.remote', $device))
            ->assertForbidden();
    }

    public function test_guest_cannot_start_a_session(): void
    {
        $device = $this->device();

        $this->postJson(route('agent.remote', $device))->assertUnauthorized();
    }

    public function test_session_is_recorded_in_the_activity_log(): void
    {
        $device = $this->device();
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('agent.remote', $device))->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'REMOTE',
            'entity_type' => 'agent_tokens',
            'entity_id' => $device->id,
            'actor_id' => $admin->id,
        ]);
    }

    /** The password must never be written to the audit trail. */
    public function test_activity_log_does_not_contain_the_password(): void
    {
        $device = $this->device();
        $this->actingAs($this->admin())->postJson(route('agent.remote', $device))->assertOk();

        $password = $device->fresh()->rustdesk_password;
        $logged = \DB::table('activity_logs')->get()->toJson();

        $this->assertStringNotContainsString($password, $logged);
    }

    /** A queued rustdesk command carries the settings the agent must apply. */
    public function test_remote_queues_a_rustdesk_command(): void
    {
        $device = $this->device();

        $this->actingAs($this->admin())->postJson(route('agent.remote', $device))->assertOk();

        $this->assertDatabaseHas('agent_commands', [
            'agent_token_id' => $device->id,
            'command' => 'rustdesk',
            'status' => 'pending',
        ]);
    }
}
