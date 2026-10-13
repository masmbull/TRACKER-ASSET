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

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Company::create(['name' => 'Test', 'code' => 'TC', 'address' => '']);
        AssetCategory::create(['name' => 'Laptop']);
        Location::create(['name' => 'Jakarta', 'status' => 'active']);
        AssetStatus::create(['name' => 'Active']);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_dashboard_displays_kpi_cards(): void
    {
        Asset::factory()->count(3)->create();

        $response = $this->actingAs(User::factory()->create())->get('/');

        $response->assertStatus(200);
        $response->assertViewHas([
            'totalAssets', 'activeAssets', 'availableAssets', 'assignedAssets',
            'repairAssets', 'retiredAssets', 'disposedAssets',
            'warnings', 'assetsByCategory', 'assetsByLocation', 'assetsByCompany',
            'statusCounts', 'recentActivity',
        ]);
        $response->assertSee('Total Assets');
        $response->assertSee('Warnings');
        $response->assertSee('flex w-full flex-wrap items-center gap-2 sm:w-auto sm:justify-end', false);
    }

    public function test_dashboard_breakdown_uses_real_lookup_columns(): void
    {
        Asset::factory()->count(2)->create();

        $response = $this->actingAs(User::factory()->create())->get('/');

        $response->assertOk();
        $response->assertViewHas('assetsByCategory', fn ($rows) => (int) $rows->firstWhere('name', 'Laptop')?->total === 2);
        $response->assertViewHas('assetsByLocation', fn ($rows) => (int) $rows->firstWhere('name', 'Jakarta')?->total === 2);
        $response->assertViewHas('assetsByCompany', fn ($rows) => (int) $rows->firstWhere('name', 'Test')?->total === 2);
    }

    public function test_asset_detail_keeps_all_assets_navigation_active(): void
    {
        $asset = Asset::factory()->create();

        $response = $this->actingAs(User::factory()->create())->get(route('assets.show', $asset));

        $response->assertOk();
        $response->assertSee('href="'.route('assets.index').'"', false);
        $response->assertSee('class="menu-item group menu-item-active"', false);
    }
}
