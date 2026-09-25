<?php

namespace Tests\Feature;

use App\Models\AgentApiToken;
use App\Models\Asset;
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

    public function test_unknown_device_creates_new_asset_and_audit(): void
    {
        $countBefore = Asset::count();

        $response = $this->withHeaders($this->agentHeaders())->postJson('/api/audits', [
            'serial_number' => 'SN-NEW-DEVICE',
            'hostname' => 'HOST-NEW-DEVICE',
            'brand' => 'Micro-Star International Co., Ltd.',
            'model' => 'Thin 15 B13UC',
            'ram' => '8 GB',
        ]);

        $response->assertCreated()->assertJsonPath('result', 'NEW DEVICE');

        $this->assertDatabaseCount('assets', $countBefore + 1);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'AUDIT',
            'source' => 'Audit Agent',
        ]);
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
