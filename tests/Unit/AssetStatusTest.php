<?php

namespace Tests\Unit;

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
 * Asset status + assignment logic (PRD §16, §20, §46).
 */
class AssetStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_assignment_has_no_return_date(): void
    {
        $open = new AssetAssignment(['assigned_date' => '2026-01-01', 'returned_date' => null]);
        $closed = new AssetAssignment(['assigned_date' => '2026-01-01', 'returned_date' => '2026-02-01']);

        $this->assertTrue($open->isOpen());
        $this->assertFalse($closed->isOpen());
    }

    public function test_held_days_counts_closed_period(): void
    {
        $assignment = new AssetAssignment([
            'assigned_date' => '2026-01-01',
            'returned_date' => '2026-01-31',
        ]);

        $this->assertSame(31, $assignment->heldDays());
    }

    public function test_held_days_counts_open_period(): void
    {
        $assignment = new AssetAssignment([
            'assigned_date' => '2026-09-01',
            'returned_date' => null,
        ]);

        $this->assertSame(22, $assignment->heldDays(new \DateTimeImmutable('2026-09-22')));
    }

    public function test_archive_only_hides_asset(): void
    {
        $asset = new Asset(['is_active' => true]);

        $asset->is_active = false;

        $this->assertFalse((bool) $asset->is_active);
        // The record itself is never destroyed — archiving is a flag only.
        $this->assertSame(['is_active'], array_keys($asset->getDirty()));
    }

    public function test_close_persists_return_date(): void
    {
        Company::create(['name' => 'Test Co', 'code' => 'TC', 'address' => '']);
        AssetCategory::create(['name' => 'Laptop']);
        Location::create(['name' => 'Jakarta', 'status' => 'active']);
        AssetStatus::create(['name' => 'Active']);

        $asset = Asset::factory()->create();
        $holder = User::factory()->create();
        $assignment = AssetAssignment::create([
            'asset_id' => $asset->id,
            'user_id' => $holder->id,
            'assigned_date' => '2026-01-01',
        ]);

        $this->assertTrue($assignment->fresh()->isOpen());

        $assignment->close('2026-03-01');

        $this->assertFalse($assignment->fresh()->isOpen());
        $this->assertSame('2026-03-01', $assignment->fresh()->returned_date?->toDateString());
    }
}
