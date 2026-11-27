<?php

namespace Tests\Feature;

use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use App\Models\AgentTelemetry;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use App\Support\ScriptPresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RemoteTest extends TestCase
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
        $this->asset = Asset::factory()->create(['serial_number' => 'SN-REMOTE-01']);
    }

    private function enrollAgent(): AgentApiToken
    {
        $plain = 'tat_'.Str::random(48);
        $token = AgentApiToken::create([
            'name' => 'remote-laptop',
            'device_id' => 'ZGV2aWNlLXJlbW90ZQ==',
            'platform' => 'Windows 11',
            'token_hash' => hash('sha256', $plain),
            'token_plain' => $plain,
        ]);

        AgentTelemetry::create([
            'agent_token_id' => $token->id,
            'asset_id' => $this->asset->id,
            'device_id' => $token->device_id,
            'hostname' => 'HOST-REMOTE-01',
            'payload' => [],
            'reported_at' => now(),
        ]);

        return $token;
    }

    public function test_remote_queues_a_script_that_reads_the_live_ultraviewer_credentials(): void
    {
        $token = $this->enrollAgent();

        $response = $this->actingAs($this->admin)
            ->postJson(route('assets.remote', $this->asset))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $command = AgentCommand::findOrFail($response->json('command_ids.0'));
        $this->assertSame($token->id, $command->agent_token_id);
        $this->assertSame('script', $command->command);
        $this->assertSame(ScriptPresets::READ_ULTRAVIEWER_CREDENTIALS, $command->payload['script_body']);
        $this->assertSame('current-user', $command->payload['run_as']);
        $this->assertSame($this->asset->id, $command->payload['asset_id']);
    }

    public function test_remote_requires_an_enrolled_agent(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('assets.remote', $this->asset))
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_launcher_uses_the_credentials_reported_by_the_agent(): void
    {
        $token = $this->enrollAgent();
        $command = AgentCommand::create([
            'agent_token_id' => $token->id,
            'command' => 'script',
            'status' => 'done',
            'payload' => ['asset_id' => $this->asset->id],
            'result' => ['stdout' => "UV_ID=12345678\nUV_PASS=Abc123!\nUV_RAW=WINDOW: UltraViewer"],
        ]);

        $response = $this->actingAs($this->admin)->get(route('agent.commands.launcher', $command));
        $response->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="tracker-remote-'.$command->id.'.bat"');
        $response->assertSee('UltraViewer_Desktop.exe', false);
        $response->assertSee('-i:12345678', false);
        $response->assertSee('-p:Abc123', false);
        $response->assertDontSee('!', false); // shell metacharacters are stripped
    }

    public function test_command_status_exposes_credentials_for_the_dialog(): void
    {
        $token = $this->enrollAgent();
        $command = AgentCommand::create([
            'agent_token_id' => $token->id,
            'command' => 'script',
            'status' => 'done',
            'payload' => [],
            'result' => ['stdout' => "UV_ID=87654321\nUV_PASS=Pass123\n"],
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('agent.commands.status', $command))
            ->assertOk()
            ->assertJsonPath('credentials.id', '87654321')
            ->assertJsonPath('credentials.password', 'Pass123');
    }

    public function test_fleet_remote_queues_a_credential_read_for_one_device(): void
    {
        $token = $this->enrollAgent();

        $response = $this->actingAs($this->admin)
            ->postJson(route('agent.remote', $token))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $command = AgentCommand::findOrFail($response->json('command_ids.0'));
        $this->assertSame($token->id, $command->agent_token_id);
        $this->assertSame(ScriptPresets::READ_ULTRAVIEWER_CREDENTIALS, $command->payload['script_body']);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'REMOTE',
            'entity_type' => 'agent_tokens',
            'entity_id' => $token->id,
        ]);
    }

    public function test_command_status_exposes_a_signed_protocol_url(): void
    {
        $token = $this->enrollAgent();
        $command = AgentCommand::create([
            'agent_token_id' => $token->id,
            'command' => 'script',
            'status' => 'done',
            'payload' => [],
            'result' => ['stdout' => "UV_ID=12345678\nUV_PASS=Abc123\n"],
        ]);

        $url = $this->actingAs($this->admin)
            ->getJson(route('agent.commands.status', $command))
            ->assertOk()
            ->json('remote_url');

        $this->assertStringStartsWith('tracker-remote:', $url);
        [$encoded, $signature] = explode('.', substr($url, strlen('tracker-remote:')));
        $this->assertSame(
            base64_encode(hash_hmac('sha256', $encoded, config('app.key'), true)),
            $signature
        );
    }

    public function test_protocol_setup_registers_the_browser_handler(): void
    {
        $response = $this->actingAs($this->admin)->get(route('agent.remote.protocol'));

        $response->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="tracker-remote-setup.reg"');
        $response->assertSee('[HKEY_CURRENT_USER\\Software\\Classes\\tracker-remote]', false);
        $response->assertSee('"URL Protocol"=""', false);
        $response->assertSee('-EncodedCommand', false);
    }

    public function test_remote_reports_why_no_credentials_were_found(): void
    {
        $token = $this->enrollAgent();
        $command = AgentCommand::create([
            'agent_token_id' => $token->id,
            'command' => 'script',
            'status' => 'done',
            'payload' => ['script_name' => 'tracker-ultraviewer-read.ps1'],
            'result' => ['stdout' => "UV_ID=\nUV_PASS=\nUV_FOUND=0 UltraViewer process(es)\n", 'message' => 'Script completed successfully.'],
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('agent.commands.status', $command))
            ->assertOk()
            ->assertJsonPath('is_remote', true)
            ->assertJsonPath('credentials', null)
            ->assertJsonPath('remote_url', null)
            ->assertJsonPath('remote_message', 'UltraViewer tidak sedang berjalan di laptop ini. Buka UltraViewer di laptop target, lalu klik Remote lagi.');
    }

    public function test_fleet_page_offers_remote_on_each_device_row(): void
    {
        $token = $this->enrollAgent();

        $this->actingAs($this->admin)
            ->get(route('agent.fleet'))
            ->assertOk()
            ->assertSee('action="'.route('agent.remote', $token).'"', false)
            ->assertSee('data-modal-action', false);
    }
}
