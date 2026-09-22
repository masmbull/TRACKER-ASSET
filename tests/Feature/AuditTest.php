<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit workflow (PRD §28, §29, §46): the result is computed server-side.
 */
class AuditTest extends TestCase
{
    use RefreshDatabase;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        Company::create(['name' => 'Test Co', 'code' => 'TC', 'address' => '']);
        AssetCategory::create(['name' => 'Laptop']);
        Location::create(['name' => 'Jakarta', 'status' => 'active']);
        AssetStatus::create(['name' => 'Active']);

        $this->asset = Asset::factory()->create([
            'ram' => '8 GB',
            'mac_address' => 'AA:BB:CC:DD:EE:01',
        ]);
    }

    public function test_matching_audit_is_recorded_as_match(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $asset = $this->asset->fresh();

        $response = $this->actingAs($support)->post(route('assets.audit.submit', $asset), [
            'device_hostname' => $asset->hostname,
            'device_serial' => $asset->serial_number,
            'device_ram' => '8 GB',
            'device_mac' => 'aa:bb:cc:dd:ee:01',
            'notes' => 'On-site check',
        ]);

        $response->assertRedirect(route('assets.audit', $asset));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('asset_audits', [
            'asset_id' => $asset->id,
            'result' => 'MATCH',
            'status' => 'submitted',
        ]);

        $this->assertDatabaseHas('activity_logs', ['action' => 'AUDIT']);
    }

    public function test_mismatched_audit_is_recorded_with_field_detail(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $asset = $this->asset->fresh();

        $response = $this->actingAs($support)->post(route('assets.audit.submit', $asset), [
            'device_hostname' => $asset->hostname,
            'device_serial' => $asset->serial_number,
            'device_ram' => '16 GB',
            'device_mac' => 'AA:BB:CC:DD:EE:01',
        ]);

        $response->assertRedirect(route('assets.audit', $asset));
        $response->assertSessionHas('warning');

        $audit = $asset->audits()->latest('id')->firstOrFail();
        $this->assertSame('MISMATCH', $audit->result);
        $this->assertSame(
            ['registered' => '8 GB', 'actual' => '16 GB'],
            $audit->mismatches['ram']
        );
    }

    public function test_audit_without_identity_is_incomplete(): void
    {
        $technician = User::factory()->create(['role' => 'technician']);

        $response = $this->actingAs($technician)->post(route('assets.audit.submit', $this->asset), [
            'device_hostname' => $this->asset->hostname,
            'device_serial' => '',
        ]);

        $response->assertSessionHasErrors('device_serial');
    }

    public function test_viewer_cannot_submit_audit(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->post(route('assets.audit.submit', $this->asset), [
            'device_hostname' => 'X',
            'device_serial' => 'Y',
        ])->assertForbidden();

        $this->assertDatabaseCount('asset_audits', 0);
    }
}
