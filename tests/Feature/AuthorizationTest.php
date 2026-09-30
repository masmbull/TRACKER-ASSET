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
 * RBAC matrix (PRD §13, §46).
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Company::create(['name' => 'Test Co', 'code' => 'TC', 'address' => '']);
        AssetCategory::create(['name' => 'Laptop']);
        Location::create(['name' => 'Jakarta', 'status' => 'active']);
        AssetStatus::create(['name' => 'Active']);
    }

    private function assetPayload(string $assetId = 'LAPTOP-001'): array
    {
        return [
            'asset_id' => $assetId,
            // Resolved rather than hardcoded to 1: see the note in AssetFactory.
            'company_id' => Company::firstOrFail()->id,
            'category_id' => AssetCategory::firstOrFail()->id,
            'brand' => 'Dell',
            'model' => 'Latitude 5520',
            'serial_number' => 'SN'.$assetId,
            'hostname' => 'PC-'.$assetId,
            'location_id' => Location::firstOrFail()->id,
            'asset_status_id' => AssetStatus::firstOrFail()->id,
        ];
    }

    public function test_viewer_can_view_but_cannot_create_or_edit(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $asset = Asset::factory()->create();

        $this->actingAs($viewer)->get('/assets')->assertOk();
        $this->actingAs($viewer)->get("/assets/{$asset->id}")->assertOk();

        $this->actingAs($viewer)->get('/assets/create')->assertForbidden();
        $this->actingAs($viewer)->get("/assets/{$asset->id}/edit")->assertForbidden();
        $this->actingAs($viewer)->post('/assets', $this->assetPayload())->assertForbidden();

        $this->assertDatabaseMissing('assets', ['asset_id' => 'LAPTOP-001']);
    }

    public function test_viewer_cannot_archive_import_or_export(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $asset = Asset::factory()->create();

        $this->actingAs($viewer)->delete("/assets/{$asset->id}")->assertForbidden();
        $this->actingAs($viewer)->get('/assets/import')->assertForbidden();
        $this->actingAs($viewer)->post('/assets/import/analyze')->assertForbidden();
        $this->actingAs($viewer)->get('/assets/export')->assertForbidden();

        // Archive must not have happened.
        $this->assertTrue((bool) $asset->fresh()->is_active);
    }

    public function test_support_can_create_edit_and_archive(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)->post('/assets', $this->assetPayload('LAP-777'))
            ->assertRedirect('/assets');

        $asset = Asset::where('asset_id', 'LAP-777')->firstOrFail();

        $this->actingAs($support)->put("/assets/{$asset->id}", $this->assetPayload('LAP-777'))
            ->assertRedirect("/assets/{$asset->id}");

        $this->actingAs($support)->delete("/assets/{$asset->id}")
            ->assertRedirect('/assets');

        // Archived, never destroyed (PRD §54).
        $this->assertFalse((bool) $asset->fresh()->is_active);
        $this->assertDatabaseHas('assets', ['asset_id' => 'LAP-777']);
    }

    public function test_technician_can_audit_but_not_transfer(): void
    {
        $tech = User::factory()->create(['role' => 'technician']);
        $asset = Asset::factory()->create();

        $this->actingAs($tech)->get("/assets/{$asset->id}/audit")->assertOk();
        $this->actingAs($tech)->get("/assets/{$asset->id}/transfer")->assertForbidden();
    }

    public function test_viewer_cannot_reach_templates_master_data(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->get('/templates')->assertForbidden();
    }

    public function test_inactive_user_is_blocked_everywhere(): void
    {
        $inactive = User::factory()->create(['role' => 'admin', 'is_active' => false]);
        $asset = Asset::factory()->create();

        $this->actingAs($inactive)->get('/assets')->assertForbidden();
        $this->actingAs($inactive)->get("/assets/{$asset->id}")->assertForbidden();
    }

    public function test_export_downloads_excel_and_writes_activity_log(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        Asset::factory()->count(2)->create();

        $response = $this->actingAs($support)->get('/assets/export');

        $response->assertOk();
        $response->assertHeader('content-disposition');
        $this->assertDatabaseHas('activity_logs', ['action' => 'EXPORT', 'source' => 'Export']);
    }
}
