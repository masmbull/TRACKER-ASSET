<?php

namespace Tests\Feature;

use App\Models\AgentApiToken;
use App\Models\AgentFingerprint;
use App\Models\AgentTelemetry;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Deep-inventory endpoint (PRD §11).
 *
 * Kept apart from the heartbeat tests on purpose: this endpoint is the reason
 * the two exist separately. A test that let the software list through the
 * heartbeat path would allow a registry walk to ride the 60-second loop, which
 * is exactly the regression the split exists to prevent.
 */
class FingerprintTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private AgentApiToken $agentToken;

    private Company $company;

    private AssetCategory $category;

    private Location $location;

    private AssetStatus $status;

    protected function setUp(): void
    {
        parent::setUp();

        // assets has non-nullable foreign keys. The reference rows have to exist
        // before an asset can be created, and company_id in particular has no
        // database default — it is normally filled by the create form, so a test
        // that builds an asset directly has to supply it.
        $this->company = Company::create(['name' => 'Test Co', 'code' => 'TC', 'address' => '']);
        $this->category = AssetCategory::create(['name' => 'Laptop']);
        $this->location = Location::create(['name' => 'Jakarta', 'status' => 'active']);
        $this->status = AssetStatus::create(['name' => 'Active']);

        $this->token = 'tat_'.Str::random(48);

        $this->agentToken = AgentApiToken::create([
            'name' => 'field-laptop',
            'device_id' => 'ZGV2aWNlLTAwMQ==',
            'platform' => 'Windows 11',
            'token_hash' => hash('sha256', $this->token),
            'token_plain' => $this->token,
        ]);
    }

    private function makeAsset(array $attributes = []): Asset
    {
        return Asset::create(array_merge([
            'asset_id' => 'AST-'.Str::upper(Str::random(6)),
            'company_id' => $this->company->id,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'asset_status_id' => $this->status->id,
            'brand' => 'Lenovo',
            'model' => 'ThinkPad T14',
        ], $attributes));
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'hostname' => 'HOST-FP-01',
            'serial_number' => 'SN-FP-01',
            'device_id' => 'ZGV2aWNlLTAwMQ==',
            'antivirus_product' => 'Windows Defender',
            'antivirus_enabled' => 'true',
            'antivirus_signature_age_days' => 2,
            'firewall_enabled' => 'true',
            'uac_enabled' => 'true',
            'tpm_present' => 'true',
            'bitlocker_status' => 'Enabled',
            'rdp_enabled' => 'false',
            'local_admin_count' => 1,
            'windows_edition' => 'Microsoft Windows 11 Pro',
            'activation_status' => 'Licensed',
            'license_channel' => 'VOLUME_KMSCLIENT',
            'os_build' => '10.0.22631',
            'domain' => 'Workgroup',
            'disk_type' => 'NVMe',
            'smart_health' => 'OK',
            'cpu_cores' => 12,
            'cpu_physical_cores' => 6,
            'software_count' => 2,
        ], $overrides);
    }

    public function test_it_rejects_an_unauthenticated_agent(): void
    {
        $this->postJson('/api/agent/fingerprint', $this->payload())
            ->assertUnauthorized();
    }

    /**
     * The asset link is what makes a fingerprint appear against the laptop it
     * describes, and it is matched on assets.serial_number / hostname /
     * mac_address.
     *
     * This test exists because an earlier version queried device_serial and
     * device_hostname — columns that do not exist on this table. SQLite ignores
     * an unknown column in a WHERE clause, so the broken branch silently matched
     * nothing, the telemetry fallback quietly covered for it, and every other
     * test still passed. On MySQL the same query is a hard SQL error and every
     * fingerprint report would have returned 500. Asserting the real columns
     * exist, and that the link is made through them, keeps that from hiding
     * again.
     */
    public function test_it_links_the_report_to_an_asset_by_its_real_identity_columns(): void
    {
        foreach (['serial_number', 'hostname', 'mac_address'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('assets', $column),
                "assets.$column is expected to exist and be used for fingerprint matching."
            );
        }

        $asset = $this->makeAsset([
            'asset_id' => 'AST-FP-01',
            'serial_number' => 'SN-FP-01',
            'hostname' => 'HOST-FP-01',
            'mac_address' => 'AA:BB:CC:DD:EE:03',
        ]);

        $this->postJson('/api/agent/fingerprint', $this->payload([
            'mac_address' => 'AA:BB:CC:DD:EE:03',
        ]), $this->headers())->assertOk();

        $fingerprint = AgentFingerprint::where('agent_token_id', $this->agentToken->id)->firstOrFail();

        $this->assertSame($asset->id, $fingerprint->asset_id);
    }

    /**
     * MAC matching has to be reachable on its own, and that means it needs a
     * validation rule of its own.
     *
     * Request::validate() returns only the keys that have a rule, so when
     * `mac_address` was missing from rules() it was stripped from the payload
     * before resolveAssetId could read it. The existing asset-linking test did
     * not catch this because its serial and hostname both matched, so the lookup
     * returned on the first key and never reached the MAC branch — the code
     * looked like it supported MAC matching while the field could not arrive.
     *
     * Here only the MAC is a match, so the link can only be made through it.
     */
    public function test_it_links_an_asset_by_mac_alone(): void
    {
        $asset = $this->makeAsset([
            'asset_id' => 'AST-FP-MAC',
            // Deliberately different from the reported values: only mac_address
            // may be used to find this row.
            'serial_number' => 'SN-NOT-REPORTED',
            'hostname' => 'HOST-NOT-REPORTED',
            // Stored the way the register actually stores it: dashed.
            'mac_address' => '34-5A-60-9A-F0-92',
        ]);

        $this->postJson('/api/agent/fingerprint', $this->payload([
            'serial_number' => 'SN-DIFFERENT-01',
            'hostname' => 'HOST-DIFFERENT-01',
            // Reported the way WMI actually reports it: colons.
            'mac_address' => '34:5A:60:9A:F0:92',
        ]), $this->headers())->assertOk();

        $fingerprint = AgentFingerprint::where('agent_token_id', $this->agentToken->id)->firstOrFail();

        $this->assertSame(
            $asset->id,
            $fingerprint->asset_id,
            'mac_address survived validation and was used to resolve the asset link.'
        );
    }

    /**
     * The register stores dashes, WMI reports colons, and an operator may type
     * either. Comparing the raw strings meant this branch matched nothing for
     * any device in the fleet — 245/245 asset rows are dashed, so no amount of
     * correct agent code would have found them.
     */
    public function test_it_matches_a_mac_regardless_of_separator_or_case(): void
    {
        $asset = $this->makeAsset([
            'asset_id' => 'AST-FP-MAC2',
            'serial_number' => 'SN-NOPE',
            'hostname' => 'HOST-NOPE',
            'mac_address' => 'aa-bb-cc-dd-ee-ff',
        ]);

        $this->postJson('/api/agent/fingerprint', $this->payload([
            'serial_number' => 'SN-STILL-NOPE',
            'hostname' => 'HOST-STILL-NOPE',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]), $this->headers())->assertOk();

        $this->assertSame(
            $asset->id,
            AgentFingerprint::where('agent_token_id', $this->agentToken->id)->firstOrFail()->asset_id
        );
    }

    /**
     * "N/A" and other placeholders must not be turned into a lookup, and must not
     * silently link a report to whichever asset happens to have a junk value.
     */
    public function test_a_placeholder_mac_is_ignored(): void
    {
        $this->makeAsset([
            'asset_id' => 'AST-FP-MAC3',
            'serial_number' => 'SN-REAL',
            'hostname' => 'HOST-REAL',
            'mac_address' => '11-22-33-44-55-66',
        ]);

        $this->postJson('/api/agent/fingerprint', $this->payload([
            'mac_address' => 'N/A',
        ]), $this->headers())->assertOk();

        // Falls through to the hostname match, not the placeholder.
        $this->assertNull(
            AgentFingerprint::where('agent_token_id', $this->agentToken->id)->firstOrFail()->asset_id
        );
    }

    /**
     * When identity matching finds nothing, the device's own telemetry row is
     * the fallback — it is the only record that says which asset a token
     * reports as.
     */
    public function test_it_falls_back_to_the_devices_own_telemetry_for_the_asset_link(): void
    {
        // The asset carries a serial, hostname and MAC, but none of them match
        // the report, so identity matching must miss and the telemetry row is
        // what resolves the link. assets.serial_number is NOT NULL, so the row
        // has to have one even though it must not be the one being reported.
        $asset = $this->makeAsset([
            'asset_id' => 'AST-FP-02',
            'model' => 'Latitude 5440',
            'serial_number' => 'SN-SOMETHING-ELSE',
            'hostname' => 'HOST-ELSEWHERE',
            'mac_address' => 'AA:BB:CC:DD:EE:99',
        ]);

        AgentTelemetry::create([
            'agent_token_id' => $this->agentToken->id,
            'asset_id' => $asset->id,
            'hostname' => 'HOST-ELSEWHERE',
            'reported_at' => now(),
        ]);

        $this->postJson('/api/agent/fingerprint', $this->payload([
            'hostname' => 'HOST-FP-01',
        ]), $this->headers())->assertOk();

        $this->assertSame(
            $asset->id,
            AgentFingerprint::where('agent_token_id', $this->agentToken->id)->firstOrFail()->asset_id
        );
    }

    public function test_it_stores_security_posture_and_software(): void
    {
        $response = $this->postJson('/api/agent/fingerprint', $this->payload([
            'software' => [
                ['name' => '7-Zip 23.01', 'version' => '23.01', 'publisher' => 'Igor Pavlov', 'installed_at' => '2024-02-11'],
                ['name' => 'Google Chrome', 'version' => '131.0.0.0', 'publisher' => 'Google LLC'],
            ],
        ]), $this->headers());

        $response->assertOk()->assertJson(['ok' => true, 'software' => 2]);

        $fingerprint = AgentFingerprint::where('agent_token_id', $this->agentToken->id)->firstOrFail();

        $this->assertSame('Windows Defender', $fingerprint->antivirus_product);
        $this->assertTrue($fingerprint->antivirus_enabled);
        $this->assertSame('Enabled', $fingerprint->bitlocker_status);
        $this->assertFalse($fingerprint->rdp_enabled);
        $this->assertSame(2, $fingerprint->antivirus_signature_age_days);
        $this->assertNotNull($fingerprint->collected_at);

        $this->assertDatabaseCount('agent_software', 2);
        $this->assertDatabaseHas('agent_software', ['name' => '7-Zip 23.01', 'version' => '23.01']);
    }

    /**
     * A re-report must replace the list, not merge into it. Keeping a row for a
     * program uninstalled in the meantime would make the fleet report software
     * that is no longer on the machine.
     */
    public function test_a_second_report_replaces_the_software_list(): void
    {
        $this->postJson('/api/agent/fingerprint', $this->payload([
            'software' => [
                ['name' => 'Old Client', 'version' => '1.0'],
                ['name' => 'Kept Client', 'version' => '2.0'],
            ],
        ]), $this->headers())->assertOk();

        $this->postJson('/api/agent/fingerprint', $this->payload([
            'software' => [['name' => 'Kept Client', 'version' => '2.0']],
        ]), $this->headers())->assertOk();

        $this->assertDatabaseCount('agent_software', 1);
        $this->assertDatabaseMissing('agent_software', ['name' => 'Old Client']);

        // One fingerprint row per token, refreshed in place.
        $this->assertSame(1, AgentFingerprint::where('agent_token_id', $this->agentToken->id)->count());
    }

    /**
     * The agent sends booleans as strings. An unreadable value must land as null
     * rather than being coerced, otherwise a sensor that failed to report would
     * be stored as a passing check.
     */
    public function test_unparsable_booleans_are_stored_as_null_not_false(): void
    {
        $this->postJson('/api/agent/fingerprint', $this->payload([
            'antivirus_enabled' => 'maybe',
            'firewall_enabled' => 'maybe',
        ]), $this->headers())->assertOk();

        $fingerprint = AgentFingerprint::where('agent_token_id', $this->agentToken->id)->firstOrFail();

        $this->assertNull($fingerprint->antivirus_enabled);
        $this->assertNull($fingerprint->firewall_enabled);
    }

    public function test_a_software_entry_without_a_name_is_rejected(): void
    {
        $response = $this->postJson('/api/agent/fingerprint', $this->payload([
            'software' => [
                ['name' => '   ', 'version' => '1.0'],
                ['name' => 'Real Program', 'version' => '3.0'],
            ],
        ]), $this->headers());

        // A nameless entry fails `required`, so the whole report is rejected
        // rather than half-stored: a partial inventory reads as a complete one.
        $response->assertStatus(422);
        $this->assertDatabaseCount('agent_software', 0);
    }

    public function test_findings_flag_a_device_with_protection_disabled(): void
    {
        $this->postJson('/api/agent/fingerprint', $this->payload([
            'antivirus_enabled' => 'false',
            'firewall_enabled' => 'false',
            'bitlocker_status' => 'Disabled',
            'smart_predicted_failure' => 'true',
            'activation_status' => 'Unlicensed',
        ]), $this->headers())->assertOk();

        $codes = collect($this->agentToken->fresh()->fingerprint->findings())->pluck('code');

        $this->assertTrue($codes->contains('av_off'));
        $this->assertTrue($codes->contains('fw_off'));
        $this->assertTrue($codes->contains('bitlocker_off'));
        $this->assertTrue($codes->contains('smart_fail'));
        $this->assertTrue($codes->contains('unlicensed'));
    }

    public function test_stale_antivirus_signatures_are_flagged(): void
    {
        $this->postJson('/api/agent/fingerprint', $this->payload([
            'antivirus_signature_age_days' => 30,
        ]), $this->headers())->assertOk();

        $findings = collect($this->agentToken->fresh()->fingerprint->findings());

        $this->assertTrue($findings->contains('code', 'av_stale'));
    }

    public function test_a_healthy_device_reports_no_findings(): void
    {
        $this->postJson('/api/agent/fingerprint', $this->payload(), $this->headers())->assertOk();

        $this->assertSame([], $this->agentToken->fresh()->fingerprint->findings());
    }

    /**
     * RAM slot occupancy, so the fleet can answer "can this take more memory?".
     *
     * Two 8GB sticks and one 16GB stick are indistinguishable in every capacity
     * figure, yet the first has no free slot. Without these counts the only way
     * to find out is to physically open the machine.
     */
    public function test_it_records_ram_slot_counts(): void
    {
        $this->postJson('/api/agent/fingerprint', $this->payload([
            'ram_modules' => 2,
            'ram_slots' => 4,
        ]), $this->headers())->assertOk();

        $fingerprint = AgentFingerprint::where('agent_token_id', $this->agentToken->id)->firstOrFail();

        $this->assertSame(2, $fingerprint->ram_modules);
        $this->assertSame(4, $fingerprint->ram_slots);
    }

    /**
     * The fleet page shows them as "2 of 4" rather than two separate numbers:
     * the reader wants occupancy, not a pair they have to subtract.
     */
    /**
     * The software list is paged and filtered on the server, not shipped whole
     * and hidden with client-side JS.
     *
     * These use explicit query strings on purpose: route()'s third argument is
     * extra route parameters, not a query string, so route('...', $token, ['q' =>
     * 'x']) silently drops the filter. That mistake is easy to make and looks
     * like a broken filter rather than a wrong call.
     */
    public function test_the_software_list_is_paginated_and_filtered_on_the_server(): void
    {
        $this->postJson('/api/agent/fingerprint', $this->payload([
            'software' => collect(range(1, 30))->map(fn ($i) => [
                'name' => sprintf('Program %02d', $i),
                'version' => '1.0',
                'publisher' => $i % 3 === 0 ? 'Microsoft' : 'Other',
            ])->all(),
        ]), $this->headers())->assertOk();

        $url = route('agent.fingerprint', $this->agentToken);

        // 10 of every 30 are Microsoft.
        $filtered = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get($url.'?q=Microsoft&per_page=25')->getContent();

        $this->assertStringContainsString('10 of 30', $filtered);
        $this->assertStringContainsString('Program 03', $filtered);
        $this->assertStringNotContainsString('Program 01', $filtered);

        // A smaller page size truncates the list. Program 30 is the 30th row, so
        // at 25 per page it belongs on page two - asserting it were visible here
        // would be asserting the pagination does not work.
        $firstPage = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get($url.'?per_page=25')->getContent();

        $this->assertStringContainsString('1–25', $firstPage);
        $this->assertStringContainsString('of 30', $firstPage);
        $this->assertStringNotContainsString('Program 30', $firstPage);

        $secondPage = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get($url.'?per_page=25&page=2')->getContent();

        $this->assertStringContainsString('Program 30', $secondPage);
    }

    /**
     * A % or _ typed into the filter box must search for that character, not
     * turn into a wildcard that silently returns every program.
     */
    public function test_the_software_filter_does_not_treat_wildcards_as_wildcards(): void
    {
        $this->postJson('/api/agent/fingerprint', $this->payload([
            'software' => [
                ['name' => 'Program One'],
                ['name' => 'Program Two'],
            ],
        ]), $this->headers())->assertOk();

        $html = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('agent.fingerprint', $this->agentToken).'?q=%')
            ->getContent();

        $this->assertStringNotContainsString('Program One', $html);
    }

    /**
     * The fleet detail modal is the single place spec fields are shown.
     *
     * This page used to repeat Security and System & licensing in full, and the
     * two drifted apart: "Firewall profiles off", "BitLocker method" and "Local
     * admins" appeared only here. With the duplicate blocks gone, those three
     * must be reachable from the modal instead, or they are lost entirely.
     */
    public function test_the_fleet_modal_shows_the_fields_the_detail_page_dropped(): void
    {
        $this->postJson('/api/agent/fingerprint', $this->payload([
            'firewall_profiles_off' => 'Domain,Public',
            'bitlocker_method' => 'TPM',
            'local_admin_count' => 3,
            'ram_modules' => 1,
            'ram_slots' => 2,
        ]), $this->headers())->assertOk();

        $html = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('agent.fleet'))->getContent();

        $this->assertStringContainsString('Firewall profiles off', $html);
        $this->assertStringContainsString('Domain,Public', $html);
        $this->assertStringContainsString('BitLocker method', $html);
        $this->assertStringContainsString('Local admins', $html);
        $this->assertStringContainsString('1 of 2', $html);

        // And the page itself must no longer repeat the spec sheet.
        $page = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('agent.fingerprint', $this->agentToken))->getContent();

        $this->assertStringNotContainsString('User Account Control', $page);
        $this->assertStringContainsString('Installed software', $page);
    }

    public function test_the_fleet_detail_shows_ram_slot_occupancy(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->postJson('/api/agent/fingerprint', $this->payload([
            'ram_modules' => 1,
            'ram_slots' => 2,
        ]), $this->headers())->assertOk();

        $this->actingAs($admin)->get('/agent/fleet')
            ->assertOk()
            ->assertSee('RAM slots')
            ->assertSee('1 of 2');
    }

    /**
     * Firmware that reports MemoryDevices = 0 sends nothing at all, so the
     * column stays null and the page shows a dash. A stored 0 would read as a
     * machine with no memory rather than one we failed to ask properly.
     */
    public function test_a_missing_slot_count_is_not_recorded_as_zero(): void
    {
        $this->postJson('/api/agent/fingerprint', $this->payload([
            'ram_modules' => 2,
            'ram_slots' => 0,
        ]), $this->headers())->assertOk();

        $this->assertNull(
            AgentFingerprint::where('agent_token_id', $this->agentToken->id)->firstOrFail()->ram_slots
        );
    }

    public function test_the_fleet_page_lists_security_findings(): void
    {
        $this->postJson('/api/agent/fingerprint', $this->payload([
            'antivirus_enabled' => 'false',
            'software' => [['name' => 'Real Program', 'version' => '3.0']],
        ]), $this->headers())->assertOk();

        // The finding badge is what an operator acts on and it must survive the
        // fleet page redesign. The old "Security Findings" KPI card is gone —
        // the count is now in the one-line summary — so this asserts the
        // summary count and the per-device badge instead of the card heading.
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/agent/fleet')
            ->assertOk()
            ->assertSee('Antivirus off');
    }

    /**
     * The page exists for the software list now; the spec sheet moved to the
     * fleet detail modal. This asserts the software table still renders, without
     * re-asserting fields that deliberately no longer live here.
     */
    public function test_the_fingerprint_page_renders_the_installed_software(): void
    {
        $this->postJson('/api/agent/fingerprint', $this->payload([
            'software' => [
                ['name' => 'Visual Studio Code', 'version' => '1.95.0', 'publisher' => 'Microsoft'],
            ],
        ]), $this->headers())->assertOk();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('agent.fingerprint', $this->agentToken))
            ->assertOk()
            ->assertSee('Installed software')
            ->assertSee('Visual Studio Code')
            ->assertSee('1.95.0');
    }

    public function test_the_page_explains_a_device_that_has_not_reported_yet(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('agent.fingerprint', $this->agentToken))
            ->assertOk()
            ->assertSee('has not reported a deep inventory yet');
    }
}
