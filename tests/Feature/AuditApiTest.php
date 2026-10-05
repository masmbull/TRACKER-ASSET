<?php

namespace Tests\Feature;

use App\Models\AgentApiToken;
use App\Models\Asset;
use App\Models\AssetAudit;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Audit agent API (PRD §11, §46): preferred audit architecture.
 */
class AuditApiTest extends TestCase
{
    use RefreshDatabase;

    private Asset $asset;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Company::create(['name' => 'Test Co', 'code' => 'TC', 'address' => '']);
        AssetCategory::create(['name' => 'Laptop']);
        Location::create(['name' => 'Jakarta', 'status' => 'active']);
        AssetStatus::create(['name' => 'Active']);

        $this->asset = Asset::factory()->create([
            'mac_address' => 'AA:BB:CC:DD:EE:01',
            'ram' => '8 GB',
        ]);

        $this->token = 'tat_'.Str::random(48);

        AgentApiToken::create([
            'name' => 'test-agent',
            'token_hash' => hash('sha256', $this->token),
        ]);
    }

    private function agentHeaders(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->postJson('/api/audits', ['hostname' => 'X'])->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Bearer wrong-token'])
            ->postJson('/api/audits', ['hostname' => 'X'])
            ->assertStatus(401);
    }

    public function test_match_response_returns_registered_result(): void
    {
        $response = $this->withHeaders($this->agentHeaders())->postJson('/api/audits', [
            'serial_number' => $this->asset->serial_number,
            'hostname' => $this->asset->hostname,
            'mac_address' => 'aa-bb-cc-dd-ee-01',
            'ram' => '8 GB',
        ]);

        $response->assertOk()->assertJson([
            'asset' => $this->asset->asset_id,
            'result' => 'MATCH',
            'mismatches' => [],
        ]);

        $this->assertDatabaseHas('asset_audits', [
            'asset_id' => $this->asset->id,
            'result' => 'MATCH',
            'status' => 'submitted',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'AUDIT',
            'source' => 'Audit Agent',
            'entity_identifier' => $this->asset->asset_id,
        ]);
    }

    public function test_mismatch_response_includes_field_detail(): void
    {
        $response = $this->withHeaders($this->agentHeaders())->postJson('/api/audits', [
            'serial_number' => $this->asset->serial_number,
            'hostname' => $this->asset->hostname,
            'ram' => '16 GB',
        ]);

        $response->assertOk()->assertJsonPath('result', 'MISMATCH');
        $this->assertSame('16 GB', $response->json('mismatches.ram.actual'));
        $this->assertSame('8 GB', $response->json('mismatches.ram.registered'));
    }

    /**
     * A device nobody has on file must NOT become an asset by itself.
     *
     * This test used to assert the opposite - it was named
     * `test_unknown_device_creates_new_asset_and_audit` and counted the asset table
     * going up by one. The behaviour was then removed, and with it the reason: an
     * unregistered laptop became a register row the moment it scanned, carrying a
     * company, category and location picked from whichever row happened to be
     * first, and nothing on screen said those were guesses. The register is the
     * record the product exists to keep; it should not fill itself with rows nobody
     * entered.
     *
     * The scan is kept, not thrown away - it waits on the fleet page holding its
     * hardware readings until a person promotes it.
     */
    public function test_unknown_device_is_recorded_but_creates_no_asset(): void
    {
        $countBefore = Asset::count();
        $auditCountBefore = AssetAudit::count();

        $response = $this->withHeaders($this->agentHeaders())->postJson('/api/audits', [
            'serial_number' => 'SN-NEW-DEVICE',
            'hostname' => 'HOST-NEW-DEVICE',
            'brand' => 'Micro-Star International Co., Ltd.',
            'model' => 'Thin 15 B13UC',
            'ram' => '8 GB',
        ]);

        $response->assertCreated()->assertJsonPath('result', 'NEW DEVICE');

        // The whole point, and the thing the old test asserted the opposite of.
        $this->assertSame($countBefore, Asset::count(), 'An unregistered device must not create an asset.');
        $this->assertDatabaseMissing('assets', ['serial_number' => 'SN-NEW-DEVICE']);

        // But the reading is not lost: it is held against the agent that sent it,
        // with no asset, so the fleet page can offer it for promotion.
        $this->assertSame($auditCountBefore + 1, AssetAudit::count());

        $scan = AssetAudit::where('device_serial', 'SN-NEW-DEVICE')->firstOrFail();

        $this->assertNull($scan->asset_id);
        $this->assertNotNull($scan->agent_token_id, 'The scan must name the device that sent it.');
        $this->assertSame('Thin 15 B13UC', $scan->device_model, 'Hardware readings must be kept.');
        $this->assertTrue($scan->isUnmatched());

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'AUDIT',
            'source' => 'Audit Agent',
            // No asset id exists yet. The serial names the row so the trail stays
            // searchable without pretending a registration decision was made.
            'entity_identifier' => 'SN-NEW-DEVICE',
        ]);
    }

    /**
     * The agent's answer must not claim an asset id it was never given.
     *
     * Asserting the absence of the key, not just its falsiness: `asset => null`
     * would satisfy a client reading it as a string and still break on the null.
     */
    public function test_unknown_device_response_does_not_name_an_asset(): void
    {
        $response = $this->withHeaders($this->agentHeaders())->postJson('/api/audits', [
            'serial_number' => 'SN-NO-ASSET-KEY',
            'hostname' => 'HOST-NO-ASSET-KEY',
        ]);

        $response->assertCreated()
            ->assertJsonMissingPath('asset');
    }

    public function test_inactive_or_expired_tokens_are_rejected(): void
    {
        AgentApiToken::where('name', 'test-agent')->update(['is_active' => false]);

        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/audits', ['hostname' => 'X'])
            ->assertStatus(401);

        AgentApiToken::where('name', 'test-agent')->update([
            'is_active' => true,
            'expires_at' => now()->subDay(),
        ]);

        $this->withHeaders($this->agentHeaders())
            ->postJson('/api/audits', ['hostname' => 'X'])
            ->assertStatus(401);
    }
}
