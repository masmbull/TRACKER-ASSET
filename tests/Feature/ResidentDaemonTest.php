<?php

namespace Tests\Feature;

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

    public function test_scan_all_broadcasts_to_null()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('agent.scanAll'))->assertRedirect();

        $this->assertDatabaseCount('agent_commands', 1);
        $this->assertDatabaseHas('agent_commands', [
            'command' => 'scan',
            'agent_token_id' => null,
            'status' => 'pending',
        ]);
    }

    public function test_update_all_broadcasts_to_null()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('agent.updateAll'))->assertRedirect();

        $this->assertDatabaseCount('agent_commands', 1);
        $this->assertDatabaseHas('agent_commands', [
            'command' => 'update',
            'agent_token_id' => null,
            'status' => 'pending',
        ]);
    }

    public function test_viewer_cannot_enqueue()
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->post(route('agent.scan', $this->apiToken))->assertForbidden();
        $this->actingAs($viewer)->post(route('agent.scanAll'))->assertForbidden();
    }

    // --- Daemon script endpoint ---------------------------------------------

    public function test_daemon_script_injects_token_and_url()
    {
        $url = config('app.url');

        $this->get(route('agent.daemon', ['token' => $this->token]))
            ->assertOk()
            ->assertSee($url, false)
            ->assertSee($this->token, false)
            ->assertDontSee('$env:TRACKER_API_URL')
            ->assertDontSee('$env:TRACKER_AUDIT_TOKEN')
            ->assertSee('Invoke-Scan')
            ->assertSee('Update-Self');
    }

    public function test_daemon_script_rejects_invalid_token()
    {
        $this->get(route('agent.daemon', ['token' => 'tat_bogus']))
            ->assertNotFound();
    }

    public function test_daemon_script_registers_hidden_autostart()
    {
        $body = $this->get(route('agent.daemon', ['token' => $this->token]))->getContent();

        // Starts hidden, registers HKCU Run autostart, polls the inbox, self-updates.
        $this->assertStringContainsString('-WindowStyle Hidden', $body);
        $this->assertStringContainsString('HKCU:\Software\Microsoft\Windows\CurrentVersion\Run', $body);
        $this->assertStringContainsString('/api/agent/inbox', $body);
        $this->assertStringContainsString('Update-Self', $body);
        $this->assertStringContainsString('LOCALAPPDATA', $body);
    }

    public function test_daemon_script_is_ascii_only()
    {
        // PowerShell 5.1 parses BOM-less files as ANSI: a stray multi-byte
        // character (e.g. an em dash) breaks the script on older clients.
        $body = $this->get(route('agent.daemon', ['token' => $this->token]))->getContent();

        $this->assertSame(0, preg_match('/[^\x00-\x7F]/', $body));
    }

    public function test_commands_page_renders_for_admin()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('agent.commands'))
            ->assertOk()
            ->assertSee('Fleet Command Center')
            ->assertSee('Scan All Laptops')
            ->assertSee('Update All Agents');
    }

    public function test_commands_page_autorefreshes_while_pending()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        AgentCommand::create(['agent_token_id' => $this->apiToken->id, 'command' => 'scan']);

        $this->actingAs($admin)->get(route('agent.commands'))
            ->assertOk()
            ->assertSee('window.location.reload()', false);
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
}
