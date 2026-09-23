<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retire + dispose lifecycle (PRD §G3).
 */
class AssetLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Company::create(['name' => 'T', 'code' => 'TC', 'address' => '']);
        AssetCategory::create(['name' => 'Laptop']);
        Location::create(['name' => 'Jakarta', 'status' => 'active']);
        AssetStatus::create(['name' => 'Active']);
        AssetStatus::create(['name' => 'Retired']);
        AssetStatus::create(['name' => 'Disposed']);
    }

    private function assetStatusId(string $name): int
    {
        return AssetStatus::where('name', $name)->value('id');
    }

    public function test_retire_sets_status_and_closes_assignment(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $asset = Asset::factory()->create(['asset_status_id' => $this->assetStatusId('Active')]);

        AssetAssignment::create([
            'asset_id' => $asset->id,
            'user_id' => $support->id,
            'assigned_date' => '2024-01-01',
            'from_location_id' => 1,
            'to_location_id' => 1,
        ]);

        $this->actingAs($support)->post(route('assets.retire', $asset))->assertRedirect();

        $asset->refresh();
        $this->assertSame('Retired', AssetStatus::find($asset->asset_status_id)->name);
        $this->assertNotNull($asset->retire_date);
        $this->assertNull($asset->current_user_id);

        $this->assertDatabaseHas('activity_logs', ['action' => 'RETIRE', 'entity_identifier' => $asset->asset_id]);
    }

    public function test_dispose_sets_disposed_status(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $asset = Asset::factory()->create(['asset_status_id' => $this->assetStatusId('Active')]);

        $this->actingAs($support)->post(route('assets.dispose', $asset))->assertRedirect();

        $asset->refresh();
        $this->assertSame('Disposed', AssetStatus::find($asset->asset_status_id)->name);
        $this->assertDatabaseHas('activity_logs', ['action' => 'DISPOSE', 'entity_identifier' => $asset->asset_id]);
    }
}
