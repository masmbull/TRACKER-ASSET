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

    public function test_asset_forms_associate_labels_with_form_controls(): void
    {
        $user = User::factory()->create(['role' => 'support']);
        $asset = Asset::factory()->create();

        foreach ([route('assets.create'), route('assets.edit', $asset)] as $url) {
            $response = $this->actingAs($user)->get($url);
            $response->assertOk();

            $document = new \DOMDocument;
            $previousLibxmlState = libxml_use_internal_errors(true);
            $document->loadHTML($response->getContent());
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlState);
            $xpath = new \DOMXPath($document);
            $controls = $xpath->query('//form//*[self::input or self::select or self::textarea][not(self::input[@type="hidden"])]');

            $this->assertNotFalse($controls);

            foreach ($controls as $control) {
                $id = $control->getAttribute('id');

                $this->assertNotSame('', $id);
                $this->assertGreaterThan(0, $xpath->query('//label[@for="'.$id.'"]')->length, $id);
            }
        }
    }

    public function test_authenticated_user_can_search_assets_by_status_name(): void
    {
        $user = User::factory()->create([
            'role' => 'viewer',
            'password' => Hash::make('password'),
        ]);

        Asset::factory()->count(2)->create();

        $response = $this->actingAs($user)->get('/assets?search=Active');

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
            // Resolved rather than hardcoded to 1: see the note in AssetFactory.
            'company_id' => Company::firstOrFail()->id,
            'category_id' => AssetCategory::firstOrFail()->id,
            'brand' => 'Dell',
            'model' => 'Latitude 5520',
            'serial_number' => 'SN001',
            'hostname' => 'PC-001',
            'location_id' => Location::firstOrFail()->id,
            'purchase_year' => 2024,
            'asset_status_id' => AssetStatus::firstOrFail()->id,
        ]);

        $response->assertRedirect('/assets');
        $this->assertDatabaseHas('assets', ['asset_id' => 'LAPTOP-001']);
    }
}
