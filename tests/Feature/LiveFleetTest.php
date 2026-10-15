<?php

namespace Tests\Feature;

use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use App\Models\AgentTelemetry;
use App\Models\Asset;
use App\Models\AssetAudit;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use App\Services\ScriptPayload;
use App\Support\ScriptPresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Live Fleet (PRD §11): telemetry rides along with the audit agent so the
 * browser fleet view can show battery wear, disk space, RAM and Wi-Fi.
 */
class LiveFleetTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private AgentApiToken $agentToken;

    protected function setUp(): void
    {
        parent::setUp();

        Company::create(['name' => 'Test Co', 'code' => 'TC', 'address' => '']);
        AssetCategory::create(['name' => 'Laptop']);
        Location::create(['name' => 'Jakarta', 'status' => 'active']);
        AssetStatus::create(['name' => 'Active']);

        $this->token = 'tat_'.Str::random(48);

        $this->agentToken = AgentApiToken::create([
            'name' => 'field-laptop',
            'device_id' => 'ZGV2aWNlLTAwMQ==',
            'platform' => 'Windows 11',
            'token_hash' => hash('sha256', $this->token),
            'token_plain' => $this->token,
        ]);
    }

    private function agentHeaders(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    private function telemetryPayload(array $overrides = []): array
    {
        return array_merge([
            'hostname' => 'HOST-LIVE-01',
            'serial_number' => 'SN-LIVE-01',
            'brand' => 'Lenovo',
            'model' => 'ThinkPad T14',
            'processor' => 'Intel Core i5-1235U',
            'ram' => '16GB',
            'storage' => '512GB',
            'os' => 'Microsoft Windows 11 Pro 10.0.22631',
            'mac_address' => 'AA:BB:CC:DD:EE:02',
            'ip_address' => '192.168.1.44',
            'device_id' => 'ZGV2aWNlLTAwMQ==',
            'logged_in_user' => 'mbul',
            'is_elevated' => '1',
            'gpu' => 'Intel Iris Xe Graphics',
            'ram_config' => '2x 8GB @ 3200 MHz',
            'wifi_ssid' => 'MITO-OFFICE',
            'battery_percent' => '82',
            'battery_health' => '74',
            'battery_cycle_count' => '412',
            'disk_free_gb' => '148.5',
            'disk_total_gb' => '476.9',
            'wifi_signal' => '71',
            'uptime_hours' => '126.5',
        ], $overrides);
    }

    public function test_agent_audit_stores_live_telemetry_for_new_device(): void
    {
        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/audits', $this->telemetryPayload())
            ->assertCreated();

        $this->assertDatabaseHas('agent_telemetries', [
            'agent_token_id' => $this->agentToken->id,
            'device_id' => 'ZGV2aWNlLTAwMQ==',
            'hostname' => 'HOST-LIVE-01',
            'logged_in_user' => 'mbul',
            'battery_percent' => 82,
            'battery_health' => 74,
            'battery_cycle_count' => 412,
            'wifi_ssid' => 'MITO-OFFICE',
            'ram_config' => '2x 8GB @ 3200 MHz',
        ]);

        $telemetry = AgentTelemetry::firstOrFail();

        // Telemetry is stored with no asset attached, because there is no asset
        // yet: an unknown device is not registered by scanning. It keeps the
        // laptop visible on the fleet page, and the promote form is prefilled
        // from the scan rather than from here.
        $this->assertNull($telemetry->asset_id);
        $this->assertDatabaseMissing('assets', ['serial_number' => 'SN-LIVE-01']);

        $this->assertSame(148.5, (float) $telemetry->disk_free_gb);
        $this->assertSame(476.9, (float) $telemetry->disk_total_gb);
        $this->assertSame(31.14, round($telemetry->disk_free_percent, 2));
        $this->assertTrue($telemetry->isOnline());
        $this->assertFalse($telemetry->hasLowDisk());
        $this->assertFalse($telemetry->hasWeakBattery());
    }

    /**
     * The fleet has to see which laptops can run a system-level script, so the
     * agent's elevation state is stored with its telemetry and returned by the
     * live refresh. "1"/"0" is the wire form the string payload carries.
     */
    public function test_telemetry_records_and_refreshes_the_elevation_state(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/audits', $this->telemetryPayload(['is_elevated' => '0']))
            ->assertCreated();

        $this->assertFalse(AgentTelemetry::firstOrFail()->is_elevated);

        $refresh = $this->actingAs($admin)->getJson(route('agent.fleet.refresh'));
        $refresh->assertOk();

        $state = collect($refresh->json('devices'))->first();
        $this->assertFalse($state['is_elevated'], 'refresh must expose the elevation state');
    }

    /**
     * An agent too old to report elevation sends nothing, and the column stays
     * null - which the page reads as "unknown", not as "not elevated".
     */
    public function test_elevation_is_null_when_the_agent_does_not_report_it(): void
    {
        $payload = $this->telemetryPayload();
        unset($payload['is_elevated']);

        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/audits', $payload)
            ->assertCreated();

        $this->assertNull(AgentTelemetry::firstOrFail()->is_elevated);
    }

    /**
     * Make-admin queues an `elevate` command for a usable device, the same way
     * every other fleet action queues one, and refuses a revoked device whose
     * agent can no longer be reached.
     */
    public function test_admin_can_request_elevation_for_a_device(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/audits', $this->telemetryPayload(['is_elevated' => '0']))
            ->assertCreated();

        $response = $this->actingAs($admin)->post(route('agent.elevate', $this->agentToken));

        $response->assertRedirect();

        $this->assertDatabaseHas('agent_commands', [
            'agent_token_id' => $this->agentToken->id,
            'command' => 'elevate',
            'status' => 'pending',
        ]);
    }

    public function test_repeated_scans_refresh_the_same_snapshot(): void
    {
        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/audits', $this->telemetryPayload())
            ->assertCreated();

        // Still NEW DEVICE on the second pass, because the device is still not
        // on the register: nothing about scanning twice registers it. The 201
        // used to be a MATCH only because the first scan had silently created an
        // asset for the machine to match against.
        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/audits', $this->telemetryPayload([
                'battery_percent' => '17',
                'battery_health' => '55',
                'disk_free_gb' => '9.4',
            ]))
            ->assertCreated()
            ->assertJsonPath('result', 'NEW DEVICE');

        $this->assertSame(1, AgentTelemetry::count());

        $telemetry = AgentTelemetry::firstOrFail();
        $this->assertSame(17, $telemetry->battery_percent);
        $this->assertTrue($telemetry->hasLowBattery());
        $this->assertTrue($telemetry->hasWeakBattery());
        $this->assertTrue($telemetry->hasLowDisk());
    }

    /**
     * The fleet page must show that a device is not on the register, and offer the
     * way to put it there.
     *
     * Without this the page lists the laptop as healthy and fully detailed while the
     * register has no row for it, and the gap is only visible by going to Assets and
     * searching for a serial number nothing told you about.
     */
    public function test_fleet_offers_to_add_a_device_that_is_not_on_the_register(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/audits', $this->telemetryPayload())
            ->assertCreated();

        $response = $this->actingAs($admin)->get(route('agent.fleet'));

        $response->assertOk();
        $response->assertSee('1 not on the register');
        $response->assertSee(route('agent.promote', $this->agentToken));
        $response->assertSee('Add to register');
        // And counts the scan actually waiting, not "0".
        $response->assertSee('1 scan waiting');
    }

    /**
     * Once promoted, the page must stop asking.
     *
     * The count is derived from scans with no asset, so leaving it on screen after a
     * promotion would mean the fleet page and the register disagree - and an
     * operator who trusted the badge would promote the same laptop twice.
     */
    public function test_fleet_stops_offering_a_device_that_is_on_the_register(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/audits', $this->telemetryPayload())
            ->assertCreated();

        $this->actingAs($admin)->post(route('agent.promote.store', $this->agentToken), [
            'company_id' => Company::first()->id,
            'category_id' => AssetCategory::first()->id,
            'location_id' => Location::first()->id,
            'asset_status_id' => AssetStatus::first()->id,
            'purchase_year' => 2026,
        ]);

        $response = $this->actingAs($admin)->get(route('agent.fleet'));

        $response->assertOk();
        $response->assertDontSee('not on the register');
        $response->assertSee('TC-LAP-2026-0001');
        $response->assertDontSee(route('agent.promote', $this->agentToken));
    }

    /**
     * The summary link must actually filter, or it is a dead end.
     */
    public function test_admin_can_queue_script_push_to_all_devices(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/audits', $this->telemetryPayload())
            ->assertCreated();

        $response = $this->actingAs($admin)->post(route('agent.commands.script'), [
            'target' => 'all',
            'script_name' => 'install-demo.ps1',
            'script_type' => 'powershell',
            'script_body' => 'Write-Host "hello"',
            'working_directory' => 'C:\\Temp\\Scripts',
            'run_as' => 'admin',
            'timeout_seconds' => 180,
            'trigger' => 'once',
            'notes' => 'Deploy demo script to all devices',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('agent_commands', [
            'command' => 'script',
            'agent_token_id' => null,
            'status' => 'pending',
        ]);
    }

    /**
     * The form sends only a body now: the type, the file name and the SHA-256
     * are derived server-side, so a pasted script cannot disagree with a
     * hand-picked type or carry a wrong hand-typed hash.
     */
    public function test_script_type_and_hash_are_derived_from_the_body(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $body = 'Write-Host "deploy"; Get-Service spooler';

        $this->actingAs($admin)->post(route('agent.commands.script'), [
            'target' => 'all',
            'script_body' => $body,
        ])->assertRedirect();

        $command = AgentCommand::where('command', 'script')->latest('id')->firstOrFail();

        $this->assertSame('powershell', $command->payload['script_type']);
        $this->assertSame('tracker-script.ps1', $command->payload['script_name']);
        $this->assertSame(hash('sha256', $body), $command->payload['sha256']);
    }

    /**
     * Push Script must answer the browser's action fetch with the queued
     * command ids, the same contract Update and Scan already use - otherwise the
     * Push Script modal submitted as a plain form and the operator saw a page
     * reload with no progress, however many devices the script was queued for.
     */
    public function test_push_script_returns_command_ids_for_the_progress_modal(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/audits', $this->telemetryPayload())
            ->assertCreated();

        $response = $this->actingAs($admin)->postJson(route('agent.commands.script'), [
            'target' => 'all',
            'script_body' => 'Write-Host "deploy"',
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);

        $ids = $response->json('command_ids');
        $this->assertIsArray($ids);
        $this->assertNotEmpty($ids, 'the modal needs at least one command id to watch');
        $this->assertDatabaseHas('agent_commands', ['id' => $ids[0], 'command' => 'script']);
    }

    /**
     * Every preset must survive the pipeline as a runnable batch script: detect
     * as `cmd`, keep the matching `.bat` name, and queue as a pending command.
     * A preset the server reads as binary or PowerShell would be a one-click
     * button that quietly ships the wrong file to the fleet.
     */
    public function test_presets_queue_as_runnable_batch_scripts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        foreach ([
            'disable-usb' => ScriptPresets::DISABLE_USB,
            'enable-usb' => ScriptPresets::ENABLE_USB,
        ] as $key => $body) {
            $this->assertSame('cmd', ScriptPayload::detectType($body), "preset {$key} must read as cmd");

            $this->actingAs($admin)->post(route('agent.commands.script'), [
                'target' => 'all',
                'script_body' => $body,
                'run_as' => 'system',
            ])->assertRedirect();

            $command = AgentCommand::where('command', 'script')->latest('id')->firstOrFail();

            $this->assertSame('cmd', $command->payload['script_type']);
            $this->assertSame('tracker-script.bat', $command->payload['script_name']);
            $this->assertSame(hash('sha256', $body), $command->payload['sha256']);
        }
    }

    /**
     * The extension must match the type the agent will actually execute, or the
     * agent doubles it up ("tracker-script.bat.ps1") for an aliased type.
     */
    public function test_default_name_matches_the_executed_type(): void
    {
        $this->assertSame('tracker-script.ps1', ScriptPayload::defaultName('ps1'));
        $this->assertSame('tracker-script.ps1', ScriptPayload::defaultName('powershell'));
        $this->assertSame('tracker-script.bat', ScriptPayload::defaultName('bat'));
        $this->assertSame('tracker-script.exe', ScriptPayload::defaultName('exe'));
    }

    public function test_cmd_body_is_detected_as_batch(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('agent.commands.script'), [
            'target' => 'all',
            'script_body' => "@echo off\r\nif exist C:\\Temp goto clean\r\n%~dp0run.bat",
        ])->assertRedirect();

        $command = AgentCommand::where('command', 'script')->latest('id')->firstOrFail();

        $this->assertSame('cmd', $command->payload['script_type']);
        $this->assertSame('tracker-script.bat', $command->payload['script_name']);
    }

    public function test_binary_body_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('agent.commands.script'), [
            'target' => 'all',
            'script_body' => "MZ\x00\x90\x00\x03\x00\x00\x00",
        ])->assertSessionHasErrors('script_body');

        $this->assertDatabaseCount('agent_commands', 0);
    }

    public function test_internal_script_api_can_enqueue_a_targeted_command(): void
    {
        config()->set('services.internal.script_token', 'internal-script-secret');

        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/audits', $this->telemetryPayload())
            ->assertCreated();

        $response = $this->withHeader('Authorization', 'Bearer internal-script-secret')
            ->postJson('/api/internal/agent/commands/script', [
                'target' => 'device',
                'agent_token_id' => $this->agentToken->id,
                'script_name' => 'install-demo.ps1',
                'script_type' => 'powershell',
                'script_body' => 'Write-Host "hello"',
                'working_directory' => 'C:\\Temp\\Scripts',
                'run_as' => 'admin',
                'timeout_seconds' => 180,
                'trigger' => 'once',
                'notes' => 'Deploy demo script to one device',
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('target', 'device');

        $this->assertDatabaseHas('agent_commands', [
            'command' => 'script',
            'agent_token_id' => $this->agentToken->id,
            'status' => 'pending',
        ]);
    }

    /**
     * A caller that still posts a hand-typed hash or type must be verified
     * against the body, not trusted: honouring them let the API skip the binary
     * rejection and hand the agent a hash that did not describe the script.
     */
    public function test_internal_script_api_rejects_a_sha256_that_does_not_match_the_body(): void
    {
        config()->set('services.internal.script_token', 'internal-script-secret');

        $this->withHeader('Authorization', 'Bearer internal-script-secret')
            ->postJson('/api/internal/agent/commands/script', [
                'target' => 'all',
                'script_body' => 'Write-Host "hello"',
                'sha256' => str_repeat('0', 64),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sha256');

        $this->assertDatabaseCount('agent_commands', 0);
    }

    public function test_internal_script_api_rejects_a_type_that_disagrees_with_the_body(): void
    {
        config()->set('services.internal.script_token', 'internal-script-secret');

        $this->withHeader('Authorization', 'Bearer internal-script-secret')
            ->postJson('/api/internal/agent/commands/script', [
                'target' => 'all',
                'script_body' => '@echo off'."\r\n".'pause',
                'script_type' => 'powershell',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('script_type');
    }

    public function test_internal_script_api_accepts_a_matching_type_alias(): void
    {
        config()->set('services.internal.script_token', 'internal-script-secret');

        $this->withHeader('Authorization', 'Bearer internal-script-secret')
            ->postJson('/api/internal/agent/commands/script', [
                'target' => 'all',
                'script_body' => 'Write-Host "hello"',
                'script_type' => 'ps1',
            ])
            ->assertStatus(202);

        $this->assertDatabaseHas('agent_commands', ['command' => 'script', 'status' => 'pending']);
    }

    public function test_admin_can_queue_script_push_to_multiple_specific_devices(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $targetA = AgentApiToken::create([
            'name' => 'laptop-a',
            'device_id' => 'MITO-0001',
            'token_hash' => hash('sha256', 'token-a'),
            'token_plain' => 'token-a',
        ]);

        $targetB = AgentApiToken::create([
            'name' => 'laptop-b',
            'device_id' => 'MITO-0002',
            'token_hash' => hash('sha256', 'token-b'),
            'token_plain' => 'token-b',
        ]);

        $response = $this->actingAs($admin)->post(route('agent.commands.script'), [
            'target' => 'devices',
            'agent_token_ids' => [$targetA->id, $targetB->id],
            'script_name' => 'multi-deploy.ps1',
            'script_type' => 'powershell',
            'script_body' => 'Write-Host "hello"',
            'working_directory' => 'C:\\Temp\\Scripts',
            'run_as' => 'current-user',
            'timeout_seconds' => 180,
            'trigger' => 'once',
            'notes' => 'Deploy to multiple devices',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('agent_commands', [
            'command' => 'script',
            'agent_token_id' => $targetA->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('agent_commands', [
            'command' => 'script',
            'agent_token_id' => $targetB->id,
            'status' => 'pending',
        ]);
    }

    public function test_fleet_can_filter_to_unregistered_devices_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // One device waiting, one already registered.
        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/audits', $this->telemetryPayload())
            ->assertCreated();

        $registered = AgentApiToken::create([
            'name' => 'registered-laptop',
            'device_id' => 'ZGV2aWNlLTAwMg==',
            'token_hash' => hash('sha256', 'tat_'.Str::random(48)),
        ]);

        AgentTelemetry::create([
            'agent_token_id' => $registered->id,
            'asset_id' => Asset::factory()->create(['asset_id' => 'LAPTOP-001'])->id,
            'hostname' => 'HOST-REGISTERED',
            'reported_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('agent.fleet', ['unregistered' => 1]));

        $response->assertOk();
        $response->assertSee('HOST-LIVE-01');
        $response->assertDontSee('HOST-REGISTERED');
    }

    public function test_blank_or_junk_readings_do_not_break_the_audit(): void
    {
        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/audits', $this->telemetryPayload([
                'battery_percent' => '',
                'battery_health' => 'N/A',
                'wifi_ssid' => '',
            ]))
            ->assertSuccessful();

        $telemetry = AgentTelemetry::firstOrFail();
        $this->assertNull($telemetry->battery_percent);
        $this->assertNull($telemetry->battery_health);
        $this->assertNull($telemetry->wifi_ssid);
        $this->assertSame(412, $telemetry->battery_cycle_count);
    }

    /**
     * The fleet page shows every reading the agent sends, as a wrapping
     * label/value grid per device rather than a nine-column table.
     *
     * The assertions below are deliberately about *values* surviving the
     * redesign, not about the old layout strings ("health 74%", "412 cycles"),
     * which no longer exist: the same facts are now labelled cells.
     */
    public function test_live_fleet_page_lists_devices_with_telemetry(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/audits', $this->telemetryPayload())
            ->assertCreated();

        $this->actingAs($admin)
            ->get(route('agent.fleet'))
            ->assertOk()
            ->assertSee('Live Fleet')
            ->assertSee('field-laptop')
            ->assertSee('HOST-LIVE-01')
            ->assertSee('mbul')
            ->assertSee('MITO-OFFICE')
            // Labels proving the grid is rendered, plus the readings that used
            // to be squeezed into single table cells.
            ->assertSee('RAM')
            ->assertSee('2x 8GB @ 3200 MHz')
            ->assertSee('Battery health')
            ->assertSee('74%')
            ->assertSee('Battery cycles')
            ->assertSee('412')
            ->assertSee('Wi-Fi')
            ->assertSee('MITO-OFFICE')
            ->assertDontSee('refreshFleet', false)
            // The concern is an auto-refreshing page, not timer usage in general.
            // The progress dialog now polls one command with its own setInterval,
            // which stops the moment that command settles - asserting on the bare
            // word would forbid that legitimate, bounded use alongside the
            // page-reload the old code did.
            ->assertDontSee('window.location.reload', false)
            ->assertDontSee('location.reload()', false)
            ->assertSee('Switch to dark mode', false)
            ->assertSee('Switch to light mode', false)
            ->assertSee('ajax-action-modal', false)
            ->assertSee('data-modal-action', false);
    }

    public function test_live_fleet_page_does_not_auto_refresh(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('agent.fleet'));

        $response->assertOk();
        $response->assertDontSee('setInterval(refreshFleet', false);
    }

    public function test_live_fleet_refresh_returns_current_device_state_as_json(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/audits', $this->telemetryPayload([
                'battery_percent' => '18',
                'battery_health' => '55',
                'disk_free_gb' => '9.4',
            ]))
            ->assertCreated();

        $this->actingAs($admin)
            ->getJson(route('agent.fleet.refresh'))
            ->assertOk()
            ->assertJsonPath('online', 1)
            ->assertJsonPath('devices.'.$this->agentToken->id.'.status', 'online')
            ->assertJsonPath('devices.'.$this->agentToken->id.'.battery_percent', 18)
            ->assertJsonPath('devices.'.$this->agentToken->id.'.battery_alert', true)
            ->assertJsonPath('devices.'.$this->agentToken->id.'.disk_free_percent', 2);
    }

    /**
     * A device is one tall block, so the page gets long fast. It is paginated
     * and the page size is user-selectable; without this a 40-laptop fleet
     * would render 40 blocks in one scroll.
     */
    public function test_live_fleet_paginates_active_devices(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        foreach (range(1, 12) as $i) {
            AgentApiToken::create([
                'name' => sprintf('laptop-%02d', $i),
                'device_id' => base64_encode("device-$i"),
                'token_hash' => hash('sha256', "tok-$i"),
                'token_plain' => "tok-$i",
            ]);
        }

        $this->actingAs($admin)->get(route('agent.fleet'))
            ->assertOk()
            ->assertSee('Showing 1')
            // Default page size is 10, so the 11th and 12th are not rendered.
            ->assertDontSee('laptop-11')
            ->assertDontSee('laptop-12');

        $this->actingAs($admin)->get(route('agent.fleet', ['page' => 2]))
            ->assertOk()
            ->assertSee('laptop-11')
            ->assertSee('laptop-12');
    }

    /**
     * The page size is whitelisted in the controller. A crafted value must fall
     * back to the default rather than asking Laravel for an enormous paginator.
     */
    public function test_live_fleet_ignores_an_out_of_range_page_size(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('agent.fleet', ['per_page' => 100000]))
            ->assertOk()
            ->assertViewHas('perPage', 10);
    }

    /**
     * The detail modal shows every reading on one line each, grouped by subject.
     *
     * These assert the two bugs the grouped layout would otherwise hide: a
     * duplicated network type ("Ethernet Ethernet Realtek...") on every wired
     * machine, and a disk percentage rendered as a dash when the agent had sent
     * both halves of the ratio and the column was simply never filled.
     */
    public function test_device_detail_labels_network_without_repeating_the_type(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        AgentTelemetry::create([
            'agent_token_id' => $this->agentToken->id,
            'hostname' => 'HOST-LIVE-01',
            'network_type' => 'ethernet',
            'network_adapter' => 'Ethernet Realtek PCIe GbE Family Controller',
            // Both halves present, ratio column left empty on purpose.
            'disk_free_gb' => 134.3,
            'disk_total_gb' => 450.4,
            'reported_at' => now(),
        ]);

        $html = $this->actingAs($admin)->get(route('agent.fleet'))->getContent();

        $this->assertStringContainsString('Ethernet Realtek PCIe GbE Family Controller', $html);
        $this->assertStringNotContainsString('Ethernet Ethernet', $html);
        // 134.3 / 450.4 = 29.8%
        $this->assertStringContainsString('30%', $html);
    }

    /**
     * The asset page keeps its own gate: the fleet must not become readable by
     * anyone who can log in.
     */
    public function test_live_fleet_requires_the_manage_agents_gate(): void
    {
        $this->get(route('agent.fleet'))->assertRedirect('/login');
        $this->get(route('agent.fleet.refresh'))->assertRedirect('/login');

        $support = User::factory()->create(['role' => 'support']);
        $this->actingAs($support)->get(route('agent.fleet'))->assertForbidden();
        $this->actingAs($support)->getJson(route('agent.fleet.refresh'))->assertForbidden();
    }

    /**
     * The heartbeat is what keeps Live Fleet honest: the daemon pings every 60s
     * on its own, with nobody clicking "Scan Now".
     */
    public function test_heartbeat_refreshes_telemetry_without_creating_an_audit(): void
    {
        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/audits', $this->telemetryPayload())
            ->assertCreated();

        $auditsBefore = AssetAudit::count();

        // Push the clock back so the refresh is measurable rather than a no-op
        // against a row written a moment ago.
        AgentTelemetry::firstOrFail()->update(['reported_at' => now()->subMinutes(5)]);

        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/agent/heartbeat', [
                'battery_percent' => '55',
                'uptime_hours' => '200.5',
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $telemetry = AgentTelemetry::firstOrFail();

        $this->assertSame(55, $telemetry->battery_percent);
        $this->assertSame(200.5, (float) $telemetry->uptime_hours);
        $this->assertTrue($telemetry->isOnline(), 'A fresh heartbeat must read as online.');

        // The whole point of a separate endpoint: no audit history is written.
        $this->assertSame(
            $auditsBefore,
            AssetAudit::count(),
            'A heartbeat must never create an asset_audits row.'
        );
    }

    /**
     * A partial ping must not blank out the readings it did not mention: a
     * laptop with no battery reports "" for battery every single minute.
     */
    public function test_heartbeat_keeps_readings_it_did_not_send(): void
    {
        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/audits', $this->telemetryPayload())
            ->assertCreated();

        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/agent/heartbeat', ['uptime_hours' => '201'])
            ->assertOk();

        $telemetry = AgentTelemetry::firstOrFail();

        $this->assertSame('MITO-OFFICE', $telemetry->wifi_ssid, 'Omitted readings must survive the ping.');
        $this->assertSame(82, $telemetry->battery_percent);
        $this->assertSame(148.5, (float) $telemetry->disk_free_gb);
    }

    /**
     * Half a disk reading is not enough to recompute the percentage; the half
     * that was not sent is carried over instead of being treated as zero.
     */
    public function test_heartbeat_with_only_one_disk_value_keeps_the_other(): void
    {
        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/audits', $this->telemetryPayload())
            ->assertCreated();

        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/agent/heartbeat', ['disk_free_gb' => '100'])
            ->assertOk();

        $telemetry = AgentTelemetry::firstOrFail();

        $this->assertSame(100.0, (float) $telemetry->disk_free_gb);
        $this->assertSame(476.9, (float) $telemetry->disk_total_gb);
        $this->assertEqualsWithDelta(20.97, (float) $telemetry->disk_free_percent, 0.01);
    }

    /**
     * Junk readings must not reject the ping — the same tolerance the audit
     * endpoint has, so one flaky sensor cannot silence the heartbeat.
     */
    public function test_heartbeat_ignores_blank_and_junk_readings(): void
    {
        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/audits', $this->telemetryPayload())
            ->assertCreated();

        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/agent/heartbeat', [
                'battery_percent' => 'N/A',
                'battery_health' => '',
                'wifi_ssid' => '',
            ])
            ->assertOk();

        $telemetry = AgentTelemetry::firstOrFail();

        $this->assertNull($telemetry->battery_percent);
        $this->assertNull($telemetry->battery_health);
        $this->assertNull($telemetry->wifi_ssid);
        $this->assertTrue($telemetry->isOnline());
    }

    /**
     * A payload shaped like the real HardwareCollector output, including the
     * fields the heartbeat does not store (identity, specs, battery_status).
     *
     * The unit tests all sent only rule-listed fields and stayed green while
     * every production heartbeat died with a 500 on `os`. This reproduces the
     * collector's actual key set so that class of drift cannot hide again.
     */
    public function test_heartbeat_accepts_a_real_collector_payload(): void
    {
        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/audits', $this->telemetryPayload())
            ->assertCreated();

        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/agent/heartbeat', [
                // Stored by the heartbeat.
                'hostname' => 'MSI',
                'os' => 'Microsoft Windows 11 Pro 10.0.22631',
                'ip_address' => '192.168.1.44',
                'logged_in_user' => 'mbul',
                'gpu' => 'Intel Iris Xe Graphics',
                'ram_config' => '2x 8GB @ 3200 MHz',
                'wifi_ssid' => 'MITO-OFFICE',
                'battery_percent' => '55',
                'battery_health' => '97',
                'battery_cycle_count' => '0',
                'disk_free_gb' => '148.5',
                'disk_total_gb' => '476.9',
                'wifi_signal' => '71',
                'uptime_hours' => '1.5',
                // Sent by the collector but not stored by a heartbeat: these must
                // be ignored, never fatal.
                'serial_number' => 'SN-LIVE-01',
                'brand' => 'Lenovo',
                'model' => 'ThinkPad T14',
                'processor' => 'Intel Core i5-1235U',
                'ram' => '16GB',
                'storage' => '512GB',
                'mac_address' => 'AA:BB:CC:DD:EE:02',
                'device_id' => 'ZGV2aWNlLTAwMQ==',
                'battery_status' => 'Charging',
                'asset_id' => 'AST-1',
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $telemetry = AgentTelemetry::firstOrFail();

        $this->assertSame('Microsoft Windows 11 Pro 10.0.22631', $telemetry->os);
        $this->assertSame(55, $telemetry->battery_percent);
        $this->assertTrue($telemetry->isOnline());
    }

    /**
     * A device that has never been scanned has no fleet row to update. The
     * answer must ask for a scan rather than inventing a half-populated row.
     */
    public function test_heartbeat_before_any_scan_asks_for_a_full_scan(): void
    {
        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/agent/heartbeat', ['battery_percent' => '80'])
            ->assertStatus(202)
            ->assertJson(['ok' => false]);

        $this->assertSame(0, AgentTelemetry::count());
    }

    public function test_heartbeat_requires_a_token(): void
    {
        $this->postJson('/api/agent/heartbeat', [])->assertUnauthorized();
    }

    public function test_heartbeat_is_rejected_for_a_revoked_token(): void
    {
        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/audits', $this->telemetryPayload())
            ->assertCreated();

        $this->agentToken->update(['is_active' => false]);

        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/agent/heartbeat', ['battery_percent' => '80'])
            ->assertUnauthorized();
    }

    /**
     * The online window is the contract the heartbeat interval is designed
     * against: 2 minutes, so one missed ping is tolerated but a machine that
     * has been off for a few minutes is honestly reported as offline.
     */
    public function test_online_window_is_two_minutes(): void
    {
        $telemetry = new AgentTelemetry(['reported_at' => now()]);

        $this->assertTrue($telemetry->isOnline());
        $this->assertTrue(
            (clone $telemetry)->forceFill(['reported_at' => now()->subSeconds(90)])->isOnline(),
            'A ping that is 90 seconds old must still count as online.'
        );
        $this->assertFalse(
            (clone $telemetry)->forceFill(['reported_at' => now()->subMinutes(3)])->isOnline(),
            'Three minutes of silence must read as offline.'
        );
        $this->assertFalse(
            (clone $telemetry)->forceFill(['reported_at' => now()->subMinutes(1)])->isOnline(0),
            'A zero-minute window can never be online.'
        );
    }

    public function test_downloaded_installer_carries_the_server_address(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // CI checkouts have no built exe (storage/app is gitignored) and a real
        // 70 MB binary must not be streamed through memory, so the shipped
        // binary is parked for the duration of the test and a stub served.
        $exePath = storage_path('app/agent/TrackerAgent.exe');
        $backupPath = $exePath.'.testbak';
        $hadRealBinary = file_exists($exePath);

        if (! is_dir(dirname($exePath))) {
            mkdir(dirname($exePath), 0755, true);
        }

        if ($hadRealBinary) {
            rename($exePath, $backupPath);
        }

        file_put_contents($exePath, "MZ\x90\x00stub");

        try {
            $response = $this->actingAs($admin)->get(route('agent.download', $this->agentToken));

            $response->assertOk()
                ->assertDownload('TrackerAgent.exe')
                ->assertHeader('Content-Type', 'application/vnd.microsoft.portable-executable');

            $body = $response->streamedContent();

            $this->assertStringStartsWith("MZ\x90\x00stub", $body);

            preg_match('/<<<TRACKER-AGENT-CONFIG:([A-Za-z0-9+\/=]+):END>>>/', $body, $matches);
            $this->assertNotEmpty($matches, 'The served binary must carry a config trailer.');

            // The trailer sits at the very end so the installer can read it from
            // the tail of its own executable.
            $this->assertStringContainsString($matches[0], substr($body, -1024));

            $config = json_decode(base64_decode($matches[1]), true);
            $this->assertContains(rtrim((string) config('app.url'), '/'), $config['server_urls']);
            $this->assertNotEmpty($config['issued_at']);
        } finally {
            unlink($exePath);

            if ($hadRealBinary) {
                rename($backupPath, $exePath);
            }
        }
    }
}
