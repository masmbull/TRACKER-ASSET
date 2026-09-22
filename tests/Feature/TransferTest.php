<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\AssetTransfer;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Transfer workflow (PRD §16, §30, §31, §46): history preserved atomically.
 */
class TransferTest extends TestCase
{
    use RefreshDatabase;

    private User $support;

    private User $owner;

    private User $target;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        Company::create(['name' => 'Test Co', 'code' => 'TC', 'address' => '']);
        AssetCategory::create(['name' => 'Laptop']);
        Location::create(['name' => 'Jakarta', 'status' => 'active']);
        Location::create(['name' => 'Bandung', 'status' => 'active']);
        AssetStatus::create(['name' => 'Assigned']);

        $this->support = User::factory()->create(['role' => 'support', 'department' => 'IT']);
        $this->owner = User::factory()->create(['role' => 'viewer', 'department' => 'Sales']);
        $this->target = User::factory()->create(['role' => 'viewer', 'department' => 'Finance']);

        $this->asset = Asset::factory()->create([
            'current_user_id' => $this->owner->id,
            'location_id' => 1,
        ]);

        AssetAssignment::create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->owner->id,
            'assigned_date' => '2024-01-01',
            'from_location_id' => 1,
            'to_location_id' => 1,
            'created_by' => $this->support->id,
        ]);
    }

    private function transferPayload(int $toUser, int $fromLocation = 1, int $toLocation = 2): array
    {
        return [
            'from_location_id' => $fromLocation,
            'to_location_id' => $toLocation,
            'to_user_id' => $toUser,
            'transfer_date' => '2026-09-22',
            'condition' => 'Good',
            'accessories' => 'Charger + bag',
            'notes' => 'Handover',
        ];
    }

    public function test_transfer_closes_previous_assignment_and_preserves_history(): void
    {
        $response = $this->actingAs($this->support)
            ->post("/assets/{$this->asset->id}/transfer", $this->transferPayload($this->target->id));

        $response->assertRedirect(route('assets.show', $this->asset));

        $asset = $this->asset->fresh();
        $this->assertSame($this->target->id, $asset->current_user_id);
        $this->assertSame(2, $asset->location_id);

        // Old assignment is closed, not deleted.
        $old = AssetAssignment::where('asset_id', $asset->id)
            ->where('user_id', $this->owner->id)
            ->firstOrFail();
        $this->assertSame('2026-09-22', $old->returned_date?->toDateString());

        // New assignment references the transfer record.
        $new = AssetAssignment::where('asset_id', $asset->id)
            ->where('user_id', $this->target->id)
            ->firstOrFail();
        $this->assertNotNull($new->transfer_id);
        $this->assertSame('2026-09-22', $new->assigned_date?->toDateString());

        $transfer = AssetTransfer::firstOrFail();
        $this->assertSame($this->owner->id, $transfer->from_user_id);
        $this->assertSame($this->target->id, $transfer->to_user_id);
        $this->assertSame('Sales', $transfer->from_department);
        $this->assertSame('Finance', $transfer->to_department);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'TRANSFER',
            'entity_identifier' => $asset->asset_id,
        ]);
    }

    public function test_transfer_numbers_are_sequential_per_year(): void
    {
        $this->actingAs($this->support)
            ->post("/assets/{$this->asset->id}/transfer", $this->transferPayload($this->target->id))
            ->assertRedirect();

        $this->actingAs($this->support)
            ->post("/assets/{$this->asset->id}/transfer", $this->transferPayload($this->owner->id, 2, 1))
            ->assertRedirect();

        $numbers = AssetTransfer::orderBy('id')->pluck('transfer_number');

        $this->assertCount(2, $numbers);
        $this->assertSame('AST/TRF/'.now()->format('Y').'/00001', $numbers[0]);
        $this->assertSame('AST/TRF/'.now()->format('Y').'/00002', $numbers[1]);
    }

    public function test_viewer_cannot_transfer(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)
            ->post("/assets/{$this->asset->id}/transfer", $this->transferPayload($this->target->id))
            ->assertForbidden();

        $this->assertDatabaseCount('asset_transfers', 0);
    }
}
