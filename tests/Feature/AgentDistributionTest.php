<?php

namespace Tests\Feature;

use App\Models\AgentApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Agent fleet distribution (PRD §11): web-served bootstrap script,
 * one-liner install commands, revocable tokens.
 */
class AgentDistributionTest extends TestCase
{
    use RefreshDatabase;

    private function issueToken(string $name = 'lab-agent'): string
    {
        $plain = 'tat_' . Str::random(48);

        AgentApiToken::create([
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
        ]);

        return $plain;
    }

    public function test_admin_can_manage_agents_and_others_cannot(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($admin)->get(route('agent.index'))->assertOk();

        $this->actingAs($support)->get(route('agent.index'))->assertForbidden();
        $this->actingAs($support)->post(route('agent.store'), ['name' => 'x'])->assertForbidden();
        $this->get(route('agent.index'))->assertRedirect('/login');
    }

    public function test_creating_token_returns_one_liner_commands_once(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->post(route('agent.store'), [
            'name' => 'laptop-fin-01',
            'expires_days' => 30,
        ]);

        $response->assertRedirect(route('agent.index'));
        $response->assertSessionHas('created_token');

        $created = session('created_token');

        $this->assertStringContainsString('iwr', $created['run_once']);
        $this->assertStringContainsString('agent/run.ps1?token=tat_', $created['run_once']);
        $this->assertStringContainsString('install=1', $created['install']);
        $this->assertStringContainsString($created['plain_text'], $created['run_once']);

        $token = AgentApiToken::where('name', 'laptop-fin-01')->firstOrFail();
        $this->assertTrue($token->expires_at?->isFuture() ?? false);
        // Only the hash is persisted — never the plaintext.
        $this->assertNotSame($created['plain_text'], $token->token_hash);
    }

    public function test_script_endpoint_injects_url_and_token(): void
    {
        $plain = $this->issueToken('serve-me');
        $url = config('app.url');

        $response = $this->get(route('agent.run', ['token' => $plain]));

        $response->assertOk();
        $body = $response->getContent();

        $this->assertStringContainsString("'" . rtrim($url, '/') . "'", $body);
        $this->assertStringContainsString("'" . $plain . "'", $body);
        $this->assertStringContainsString('Get-CimInstance Win32_ComputerSystem', $body); // real collector
        $this->assertStringNotContainsString('$env:TRACKER_AUDIT_TOKEN', $body);
    }

    public function test_script_endpoint_install_flag_prepares_scheduled_task(): void
    {
        $plain = $this->issueToken('install-me');

        $body = $this->get(route('agent.run', ['token' => $plain, 'install' => 1]))->getContent();

        $this->assertStringContainsString('[switch]$Install = $true,', $body);
        $this->assertStringContainsString('Register-ScheduledTask', $body);
    }

    public function test_unknown_or_revoked_token_gets_404(): void
    {
        $this->get(route('agent.run', ['token' => 'tat_bogus']))->assertNotFound();

        $plain = $this->issueToken('to-revoke');
        $this->get(route('agent.run', ['token' => $plain]))->assertOk();

        $admin = User::factory()->create(['role' => 'admin']);
        $token = AgentApiToken::where('name', 'to-revoke')->firstOrFail();
        $this->actingAs($admin)->post(route('agent.revoke', $token))->assertRedirect(route('agent.index'));

        $this->get(route('agent.run', ['token' => $plain]))->assertNotFound();
        $this->assertFalse($token->fresh()->is_active);
    }

    public function test_duplicate_token_names_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('agent.store'), ['name' => 'dup-name'])
            ->assertRedirect(route('agent.index'));

        $this->actingAs($admin)->post(route('agent.store'), ['name' => 'dup-name'])
            ->assertSessionHasErrors('name');
    }
}
