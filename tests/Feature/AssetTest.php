<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AssetTest extends TestCase
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

    public function test_guest_can_view_asset_list(): void
    {
        Asset::factory()->count(3)->create();

        $response = $this->get('/assets');

        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_asset_list(): void
    {
        $user = User::factory()->create([
            'role' => 'viewer',
            'password' => Hash::make('password'),
        ]);

        Asset::factory()->count(3)->create();

        $response = $this->actingAs($user)->get('/assets');

        $response->assertStatus(200);
    }

    public function test_authenticated_user_can_create_asset(): void
    {
        $user = User::factory()->create([
            'role' => 'support',
            'password' => Hash::make('password'),
        ]);

        $response = $this->actingAs($user)->post('/assets', [
            'asset_id' => 'LAPTOP-001',
            'company_id' => 1,
            'category_id' => 1,
            'brand' => 'Dell',
            'model' => 'Latitude 5520',
            'serial_number' => 'SN001',
            'hostname' => 'PC-001',
            'location_id' => 1,
            'purchase_year' => 2024,
            'asset_status_id' => 1,
        ]);

        $response->assertRedirect('/assets');
        $this->assertDatabaseHas('assets', ['asset_id' => 'LAPTOP-001']);
    }
}
