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
 * Windows Update and CVE state.
 *
 * The whole point of these fields is the difference between "we asked and the
 * answer was zero" and "we never got to ask". The second must survive the trip
 * as null, because a patch register that renders an unasked question as zero
 * asserts the fleet is fully patched on precisely the machines that are least
 * likely to be - the ones behind a slow network, where the agent's 25-second
 * search budget expires.
 */
class WindowsUpdateStateTest extends TestCase
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
        ]);
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    private function fingerprint(array $overrides = []): AgentFingerprint
    {
        $this->postJson('/api/agent/fingerprint', array_merge([
            'hostname' => 'HOST-WU-01',
            'device_id' => 'ZGV2aWNlLTAwMQ==',
        ], $overrides), $this->headers())->assertOk();

        return AgentFingerprint::where('agent_token_id', $this->agentToken->id)->firstOrFail();
    }

    public function test_it_persists_the_full_update_state(): void
    {
        $fingerprint = $this->fingerprint([
            'windows_updates_pending' => 3,
            'security_updates_pending' => 0,
            'updates_succeeded' => 142,
            'updates_failed' => 10,
            'security_updates_installed' => 6,
            'last_security_update_at' => '2026-09-15',
            'hotfix_count' => 4,
            'last_hotfix_installed_at' => '2026-12-08',
        ]);

        $this->assertSame(3, $fingerprint->windows_updates_pending);
        // Zero is a real answer here and must not be confused with absent.
        $this->assertSame(0, $fingerprint->security_updates_pending);
        $this->assertNotNull($fingerprint->security_updates_pending);
        $this->assertSame(142, $fingerprint->updates_succeeded);
        $this->assertSame(10, $fingerprint->updates_failed);
        $this->assertSame(6, $fingerprint->security_updates_installed);
        $this->assertSame('2026-09-15', $fingerprint->last_security_update_at?->toDateString());
    }

    /**
     * The machine behind a captive network. Its search timed out, so the agent
     * sent no pending count at all - and the columns must be empty rather than
     * defaulted, so the page can say "not checked" instead of "up to date".
     */
    public function test_a_timed_out_search_leaves_the_pending_counts_null_not_zero(): void
    {
        $fingerprint = $this->fingerprint([
            'updates_succeeded' => 142,
            'updates_failed' => 3,
        ]);

        $this->assertSame(142, $fingerprint->updates_succeeded);

        foreach ([
            'windows_updates_pending', 'security_updates_pending',
            'last_security_update_at', 'security_updates_installed',
        ] as $field) {
            $this->assertNull($fingerprint->{$field}, $field.' must stay null when not reported');
        }
    }

    /**
     * The single most damaging rendering bug this feature could have: a machine
     * we know nothing about displayed as "up to date".
     */
    public function test_the_fleet_page_never_shows_an_unasked_search_as_up_to_date(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin);

        $this->fingerprint(['updates_succeeded' => 142]);

        $html = $this->get(route('agent.fleet'))->assertOk()->getContent();

        $this->assertStringContainsString('not checked', $html);
        $this->assertStringNotContainsString('>up to date<', $html);
    }

    public function test_a_machine_that_reports_zero_pending_is_shown_as_up_to_date(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin);

        $this->fingerprint(['windows_updates_pending' => 0]);

        $this->get(route('agent.fleet'))
            ->assertOk()
            ->assertSee('up to date');
    }

    /**
     * A failed update is not protection, and it is invisible to the hotfix count
     * which lists it as though it applied. It has to be surfaced on its own.
     */
    public function test_failed_updates_are_visible_on_the_fleet_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin);

        $this->fingerprint(['updates_failed' => 10, 'updates_succeeded' => 142]);

        $this->get(route('agent.fleet'))
            ->assertOk()
            ->assertSee('Updates failed')
            ->assertSee('10');
    }

    public function test_an_out_of_range_pending_count_does_not_reject_the_report(): void
    {
        $this->postJson('/api/agent/fingerprint', [
            'hostname' => 'HOST-WU-01',
            'device_id' => 'ZGV2aWNlLTAwMQ==',
            'windows_updates_pending' => 999999,
        ], $this->headers())->assertOk()->assertJsonPath('ok', true);

        $fingerprint = AgentFingerprint::where('agent_token_id', $this->agentToken->id)->firstOrFail();

        // Discarded rather than clamped: storing 500 would read as a machine
        // with 500 updates waiting, which is a different and wrong claim.
        $this->assertNull($fingerprint->windows_updates_pending);
    }
}
