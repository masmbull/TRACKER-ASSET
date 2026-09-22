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
 * Barcode / QR scan entry point (PRD §42, §46).
 */
class BarcodeTest extends TestCase
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

    public function test_scan_resolves_by_barcode(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $asset = Asset::factory()->create(['barcode' => 'BC-100200300']);

        $this->actingAs($support)->get(route('barcode.form'))->assertOk();

        $this->actingAs($support)->post(route('barcode.resolve'), ['code' => 'BC-100200300'])
            ->assertRedirect(route('assets.show', $asset))
            ->assertSessionHas('success');
    }

    public function test_scan_resolves_by_serial_hostname_and_asset_id(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $asset = Asset::factory()->create();

        foreach ([$asset->serial_number, $asset->hostname, $asset->asset_id] as $code) {
            $this->actingAs($support)->post(route('barcode.resolve'), ['code' => $code])
                ->assertRedirect(route('assets.show', $asset));
        }
    }

    public function test_unknown_code_returns_error(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)->post(route('barcode.resolve'), ['code' => 'NOPE-123'])
            ->assertSessionHasErrors('code');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('barcode.form'))->assertRedirect('/login');
        $this->post(route('barcode.resolve'), ['code' => 'X'])->assertRedirect('/login');
    }
}
