<?php

namespace Tests\Feature;

use App\Models\AgentApiToken;
use App\Models\AgentTelemetry;
use App\Models\AssetAudit;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
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

        // Dual ingestion: the unknown device became a Master Asset and the
        // telemetry snapshot points at it.
        $this->assertNotNull($telemetry->asset_id);
        $this->assertDatabaseHas('assets', [
            'id' => $telemetry->asset_id,
            'hostname' => 'HOST-LIVE-01',
            'serial_number' => 'SN-LIVE-01',
        ]);

        $this->assertSame(148.5, (float) $telemetry->disk_free_gb);
        $this->assertSame(476.9, (float) $telemetry->disk_total_gb);
        $this->assertSame(31.14, round($telemetry->disk_free_percent, 2));
        $this->assertTrue($telemetry->isOnline());
        $this->assertFalse($telemetry->hasLowDisk());
        $this->assertFalse($telemetry->hasWeakBattery());
    }

    public function test_repeated_scans_refresh_the_same_snapshot(): void
    {
        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/audits', $this->telemetryPayload())
            ->assertCreated();

        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/audits', $this->telemetryPayload([
                'battery_percent' => '17',
                'battery_health' => '55',
                'disk_free_gb' => '9.4',
            ]))
            ->assertOk();

        $this->assertSame(1, AgentTelemetry::count());

        $telemetry = AgentTelemetry::firstOrFail();
        $this->assertSame(17, $telemetry->battery_percent);
        $this->assertTrue($telemetry->hasLowBattery());
        $this->assertTrue($telemetry->hasWeakBattery());
        $this->assertTrue($telemetry->hasLowDisk());
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
            ->assertSee('2x 8GB @ 3200 MHz')
            ->assertSee('health 74%')
            ->assertSee('412 cycles');
    }

    public function test_live_fleet_requires_the_manage_agents_gate(): void
    {
        $this->get(route('agent.fleet'))->assertRedirect('/login');

        $support = User::factory()->create(['role' => 'support']);
        $this->actingAs($support)->get(route('agent.fleet'))->assertForbidden();
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
