<?php

namespace Tests\Feature;

use App\Models\AgentApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentDistributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_agents_and_others_cannot(): void
    {
        $this->get(route('agent.index'))->assertRedirect('/login');

        $admin = User::factory()->create(['role' => 'admin']);
        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($admin)->get(route('agent.index'))->assertOk();

        $this->actingAs($support)->get(route('agent.index'))->assertForbidden();
    }

    public function test_download_latest_redirects_to_existing_token(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $token = AgentApiToken::create([
            'name' => 'existing-device',
            'token_hash' => hash('sha256', 'tok'),
            'token_plain' => 'tok',
        ]);

        $this->actingAs($admin)->get(route('agent.download.latest'))
            ->assertRedirect(route('agent.download', $token));
    }

    public function test_download_latest_creates_token_when_none_exists(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('agent.download.latest'))
            ->assertRedirect();
    }

    public function test_download_serves_exe(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $token = AgentApiToken::create([
            'name' => 'exe-device',
            'token_hash' => hash('sha256', 'tok'),
            'token_plain' => 'tok',
        ]);

        // CI checkouts have no built exe (storage/app is gitignored) — create a
        // stub so the download path is exercised everywhere, then clean it up.
        $exePath = storage_path('app/agent/TrackerAgent.exe');
        $createdStub = false;
        if (! file_exists($exePath)) {
            if (! is_dir(dirname($exePath))) {
                mkdir(dirname($exePath), 0755, true);
            }
            file_put_contents($exePath, "MZ\x90\x00stub");
            $createdStub = true;
        }

        try {
            $this->actingAs($admin)->get(route('agent.download', $token))
                ->assertOk()
                ->assertDownload('TrackerAgent.exe')
                ->assertHeader('Content-Type', 'application/vnd.microsoft.portable-executable');
        } finally {
            if ($createdStub) {
                unlink($exePath);
            }
        }
    }

    public function test_device_registration_issues_token(): void
    {
        $payload = [
            'device_id' => 'ZGV2aWNlLTAwMQ==',
            'name' => 'field-laptop',
            'platform' => 'Windows 11',
        ];

        $first = $this->postJson('/api/agent/register', $payload);
        $first->assertOk()->assertJsonStructure(['token', 'message']);
        $this->assertNotEmpty($first->json('token'));

        $second = $this->postJson('/api/agent/register', $payload);
        $second->assertOk()->assertJsonPath('token', $first->json('token'));

        $this->assertDatabaseHas('agent_api_tokens', [
            'device_id' => 'ZGV2aWNlLTAwMQ==',
            'platform' => 'Windows 11',
        ]);
    }
}
