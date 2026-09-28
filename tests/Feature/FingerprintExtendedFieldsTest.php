<?php

namespace Tests\Feature;

use App\Models\AgentApiToken;
use App\Models\AgentFingerprint;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The agent collects fifteen more readings; these pin that they survive the trip.
 *
 * Every test here exists because of one specific way this goes wrong. Laravel's
 * validate() returns only the keys that have a rule, so a field the agent sends
 * but nobody declared is dropped silently - the report still returns 200, the
 * other four hundred fields still save, and the column is simply empty forever.
 * That is exactly how secure_boot_enabled and bitlocker_method ended up as
 * permanent blanks: the columns, the UI rows and the validation all existed,
 * and no test noticed because nothing asserted the value.
 *
 * The second trap is type handling. The agent sends every boolean as the string
 * "true"/"false", which Laravel's boolean rule rejects outright, and a 422 would
 * take down a report carrying several hundred perfectly good software rows.
 */
class FingerprintExtendedFieldsTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private AgentApiToken $agentToken;

    protected function setUp(): void
    {
        parent::setUp();

        // assets carries non-nullable foreign keys with no defaults, so the
        // reference rows have to exist before any asset is created.
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
        ]);
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    private function fingerprint(array $overrides = []): AgentFingerprint
    {
        $this->postJson('/api/agent/fingerprint', array_merge([
            'hostname' => 'HOST-EXT-01',
            'device_id' => 'ZGV2aWNlLTAwMQ==',
        ], $overrides), $this->headers())->assertOk();

        return AgentFingerprint::where('agent_token_id', $this->agentToken->id)->firstOrFail();
    }

    /**
     * The two columns that had a UI row and no collector at all. If this fails,
     * someone has removed a validation rule and the blank is back.
     */
    public function test_it_persists_the_two_readings_that_had_a_column_but_no_collector(): void
    {
        $fingerprint = $this->fingerprint([
            'secure_boot_enabled' => 'true',
            'bitlocker_method' => 'XTS-AES',
        ]);

        $this->assertTrue($fingerprint->secure_boot_enabled);
        $this->assertSame('XTS-AES', $fingerprint->bitlocker_method);
    }

    public function test_it_persists_sign_in_and_lock_posture(): void
    {
        $fingerprint = $this->fingerprint([
            'auto_logon_enabled' => 'true',
            'screen_lock_enabled' => 'true',
            'lock_timeout_minutes' => 10,
            'guest_account_enabled' => 'false',
        ]);

        $this->assertTrue($fingerprint->auto_logon_enabled);
        $this->assertTrue($fingerprint->screen_lock_enabled);
        $this->assertSame(10, $fingerprint->lock_timeout_minutes);
        $this->assertFalse($fingerprint->guest_account_enabled);
    }

    /**
     * Zero has to survive. It means "lock immediately", and treating it as
     * missing would blank the best-configured machines in the fleet - turning
     * the strongest setting into an apparent absence of one.
     */
    public function test_a_zero_lock_timeout_is_kept_rather_than_treated_as_missing(): void
    {
        $fingerprint = $this->fingerprint([
            'screen_lock_enabled' => 'true',
            'lock_timeout_minutes' => 0,
        ]);

        $this->assertTrue($fingerprint->screen_lock_enabled);
        $this->assertSame(0, $fingerprint->lock_timeout_minutes);
    }

    public function test_it_persists_defender_detail_and_patch_recency(): void
    {
        $fingerprint = $this->fingerprint([
            'defender_realtime_enabled' => 'true',
            'defender_last_scan_days' => 3,
            'hotfix_count' => 42,
            'last_hotfix_installed_at' => '2026-09-01',
        ]);

        $this->assertTrue($fingerprint->defender_realtime_enabled);
        $this->assertSame(3, $fingerprint->defender_last_scan_days);
        $this->assertSame(42, $fingerprint->hotfix_count);
        $this->assertSame('2026-09-01', $fingerprint->last_hotfix_installed_at?->toDateString());
    }

    public function test_it_persists_firmware_chassis_and_architecture(): void
    {
        $fingerprint = $this->fingerprint([
            'bios_version' => 'E16R8IMS.70B',
            'bios_date' => '2025-06-03',
            'chassis_type' => 'Laptop',
            'device_model' => 'Thin 15 B13UC',
            'screen_resolution' => '1920x1080',
            'cpu_architecture' => 'x64',
        ]);

        $this->assertSame('E16R8IMS.70B', $fingerprint->bios_version);
        $this->assertSame('2025-06-03', $fingerprint->bios_date?->toDateString());
        $this->assertSame('Laptop', $fingerprint->chassis_type);
        $this->assertSame('Thin 15 B13UC', $fingerprint->device_model);
        $this->assertSame('1920x1080', $fingerprint->screen_resolution);
        $this->assertSame('x64', $fingerprint->cpu_architecture);
    }

    /**
     * An older agent is still in the fleet and sends none of this. Its report
     * has to be accepted, and the new columns must stay null - not false, and
     * not zero. A default of 0 would be a fabricated reading: it would claim the
     * machine has never applied a patch when the truth is that nobody asked.
     */
    public function test_a_report_without_any_new_field_is_still_accepted_and_stays_null(): void
    {
        $fingerprint = $this->fingerprint(['antivirus_product' => 'Windows Defender']);

        $this->assertSame('Windows Defender', $fingerprint->antivirus_product);

        foreach ([
            'secure_boot_enabled', 'auto_logon_enabled', 'screen_lock_enabled',
            'guest_account_enabled', 'defender_realtime_enabled', 'lock_timeout_minutes',
            'defender_last_scan_days', 'hotfix_count', 'bios_version', 'bios_date',
            'chassis_type', 'device_model', 'screen_resolution', 'cpu_architecture',
            'last_hotfix_installed_at', 'bitlocker_method',
        ] as $field) {
            $this->assertNull($fingerprint->{$field}, $field.' should be null when not reported');
        }
    }

    /**
     * A report carrying an absurd reading must still be stored. 422 here would
     * discard several hundred software rows along with it, and the machine would
     * keep no inventory at all until someone hand-edited the payload.
     */
    public function test_an_out_of_range_reading_does_not_reject_the_whole_report(): void
    {
        $this->postJson('/api/agent/fingerprint', [
            'hostname' => 'HOST-EXT-01',
            'device_id' => 'ZGV2aWNlLTAwMQ==',
            'lock_timeout_minutes' => 999999,
            'defender_last_scan_days' => 99999999,
        ], $this->headers())->assertOk()->assertJsonPath('ok', true);

        $fingerprint = AgentFingerprint::where('agent_token_id', $this->agentToken->id)->firstOrFail();

        $this->assertNull($fingerprint->lock_timeout_minutes);
    }

    /**
     * The fleet modal is where these are read, so a value that saves but does not
     * display is the same blank as never collecting it in the first place.
     */
    public function test_the_fleet_detail_modal_shows_the_new_readings(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin);

        $this->fingerprint([
            'auto_logon_enabled' => 'true',
            'screen_lock_enabled' => 'true',
            'lock_timeout_minutes' => 0,
            'chassis_type' => 'Laptop',
            'cpu_architecture' => 'x64',
            'bios_version' => 'E16R8IMS.70B',
        ]);

        $this->get(route('agent.fleet'))
            ->assertOk()
            // "immediate", not "0" - a bare zero reads as no lock configured.
            ->assertSee('immediate')
            ->assertSee('ON - no password needed')
            ->assertSee('E16R8IMS.70B')
            ->assertSee('x64')
            ->assertSee('Laptop');
    }
}
