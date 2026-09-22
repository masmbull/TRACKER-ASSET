<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Activity log page (PRD §40, §46) — immutable trail, filterable.
 */
class ActivityLogTest extends TestCase
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

    public function test_support_can_view_filtered_activity(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $asset = Asset::factory()->create();

        ActivityLog::create([
            'actor_id' => $support->id,
            'action' => 'CREATE',
            'entity_type' => 'assets',
            'entity_id' => $asset->id,
            'entity_identifier' => $asset->asset_id,
            'source' => 'Web UI',
        ]);

        ActivityLog::create([
            'actor_id' => $support->id,
            'action' => 'TRANSFER',
            'entity_type' => 'assets',
            'entity_id' => $asset->id,
            'entity_identifier' => $asset->asset_id,
            'source' => 'Transfer',
        ]);

        $page = $this->actingAs($support)->get(route('activity.index'));
        $page->assertOk()->assertSee('TRANSFER');

        $filtered = $this->actingAs($support)->get(route('activity.index', ['action' => 'TRANSFER']));
        $filtered->assertOk()->assertSee('TRANSFER');

        $search = $this->actingAs($support)->get(route('activity.index', ['search' => $asset->asset_id]));
        $search->assertOk();
    }

    public function test_viewer_cannot_view_activity(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->get(route('activity.index'))->assertForbidden();
    }
}
