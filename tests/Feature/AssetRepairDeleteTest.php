<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetRepair;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deleting a repair record from an asset's history (assets.repair.destroy).
 *
 * The route carries both the asset and the repair, so the two must actually
 * belong together: an id from another machine is not a delete request for this
 * one, it is a malformed URL.
 */
class AssetRepairDeleteTest extends TestCase
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

    private function repairFor(Asset $asset, User $user, string $problem = 'Keyboard mati'): AssetRepair
    {
        return AssetRepair::create([
            'asset_id' => $asset->id,
            'date' => now()->toDateString(),
            'problem' => $problem,
            'action' => 'Ganti keyboard',
            'status' => 'done',
            'created_by' => $user->id,
        ]);
    }

    public function test_support_can_delete_a_repair_and_it_is_written_to_the_activity_log(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $asset = Asset::factory()->create();
        $repair = $this->repairFor($asset, $support);

        $response = $this->actingAs($support)
            ->delete(route('assets.repair.destroy', [$asset, $repair]));

        $response->assertRedirect(route('assets.show', $asset));
        $response->assertSessionHas('success', 'Repair record deleted.');

        $this->assertDatabaseMissing('asset_repairs', ['id' => $repair->id]);

        $log = ActivityLog::where('action', 'DELETE_REPAIR')
            ->where('entity_type', 'asset_repairs')
            ->where('entity_id', $repair->id)
            ->firstOrFail();

        $this->assertSame($support->id, $log->actor_id);
        $this->assertSame($asset->asset_id, $log->entity_identifier);
        $this->assertSame('Repair', $log->source);
    }

    public function test_a_repair_belonging_to_another_asset_is_not_found(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $asset = Asset::factory()->create();
        $otherAsset = Asset::factory()->create();
        $repair = $this->repairFor($otherAsset, $support);

        $this->actingAs($support)
            ->delete(route('assets.repair.destroy', [$asset, $repair]))
            ->assertNotFound();

        $this->assertDatabaseHas('asset_repairs', ['id' => $repair->id]);
    }

    public function test_a_technician_may_delete_a_repair(): void
    {
        $technician = User::factory()->create(['role' => 'technician']);
        $asset = Asset::factory()->create();
        $repair = $this->repairFor($asset, $technician);

        $this->actingAs($technician)
            ->delete(route('assets.repair.destroy', [$asset, $repair]))
            ->assertRedirect(route('assets.show', $asset));

        $this->assertDatabaseMissing('asset_repairs', ['id' => $repair->id]);
    }

    public function test_a_viewer_cannot_delete_a_repair(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $asset = Asset::factory()->create();
        $repair = $this->repairFor($asset, $viewer);

        $this->actingAs($viewer)
            ->delete(route('assets.repair.destroy', [$asset, $repair]))
            ->assertForbidden();

        $this->assertDatabaseHas('asset_repairs', ['id' => $repair->id]);
    }
}
