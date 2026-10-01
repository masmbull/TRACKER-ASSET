<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use App\Services\AssetQrCode;
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

    /**
     * The labels printed by this app encode a URL, and a hardware scanner types
     * whatever it reads straight into that form. Resolving only works if the URL
     * is unwrapped back to an identifier first.
     */
    public function test_a_scanned_qr_label_url_resolves_to_the_asset(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $asset = Asset::factory()->create();

        config(['assets.qr.base_url' => 'https://assets.mito.co.id']);

        $labelUrl = AssetQrCode::fromConfig()->targetUrl($asset);

        $this->actingAs($support)->post(route('barcode.resolve'), ['code' => $labelUrl])
            ->assertRedirect(route('assets.show', $asset))
            ->assertSessionHas('success');
    }

    public function test_a_scanned_label_resolves_whatever_host_generated_it(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $asset = Asset::factory()->create();

        // A sticker printed before the base URL was configured encodes the old host.
        foreach (['http://localhost:8000', 'https://assets.mito.co.id', ''] as $host) {
            $code = $host === '' ? "/assets/{$asset->id}" : $host."/assets/{$asset->id}";

            $this->actingAs($support)->post(route('barcode.resolve'), ['code' => $code])
                ->assertRedirect(route('assets.show', $asset));
        }
    }

    /**
     * Unwrapping must stay narrow. Anything looser would let a typed path become
     * a way to fetch records the identifier lookup would not otherwise reach.
     */
    public function test_only_asset_paths_are_unwrapped(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $asset = Asset::factory()->create();

        foreach ([
            '/assets',
            '/users/'.$asset->id,
            '/assets/'.$asset->id.'/edit',
            'https://evil.example.com/assets/'.$asset->id.'/edit',
            '/assets/'.$asset->id.'/../../../users',
        ] as $code) {
            $this->actingAs($support)->post(route('barcode.resolve'), ['code' => $code])
                ->assertSessionHasErrors('code');
        }
    }

    /**
     * Regression: an earlier version matched the primary key in the same query as
     * the identifier columns. SQLite casts 'BC-100200300' to bigint and shrugs, so
     * the suite passed; PostgreSQL raises 22P02 and every scan on the scan page
     * returned a 500. Non-numeric codes are the normal case here, so this pins it.
     */
    public function test_a_non_numeric_code_does_not_break_the_lookup(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $asset = Asset::factory()->create(['barcode' => 'BC-100200300']);

        // A lookup that must miss, with text that is not a valid id.
        $this->actingAs($support)->post(route('barcode.resolve'), ['code' => 'NOPE-123'])
            ->assertSessionHasErrors('code');

        // And one that must hit, so the miss above is not just an early return.
        $this->actingAs($support)->post(route('barcode.resolve'), ['code' => 'BC-100200300'])
            ->assertRedirect(route('assets.show', $asset));
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
