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
 * A rejected form must not look like a saved one.
 *
 * The layout rendered session flashes and nothing else, so a validation failure
 * came back as a page indistinguishable from success. The asset edit form went
 * further and read neither old() nor @error, which meant every field the user had
 * just filled in was replaced by the value already in the database. Filling in
 * twenty fields, tripping one rule and losing all twenty is the worst possible way
 * to be told about a typo.
 */
class FormErrorReportingTest extends TestCase
{
    use RefreshDatabase;

    private User $support;

    private Location $jakarta;

    private Location $bandung;

    protected function setUp(): void
    {
        parent::setUp();

        Company::create(['name' => 'Test Co', 'code' => 'TC', 'address' => '']);
        AssetCategory::create(['name' => 'Laptop']);
        $this->jakarta = Location::create(['name' => 'Jakarta', 'status' => 'active']);
        $this->bandung = Location::create(['name' => 'Bandung', 'status' => 'active']);
        AssetStatus::create(['name' => 'Active']);

        $this->support = User::factory()->create(['role' => 'support']);
    }

    /**
     * @return array<string, mixed>
     */
    private function assetPayload(array $overrides = []): array
    {
        return array_merge([
            'asset_id' => 'LAP-1',
            'brand' => 'Lenovo',
            'model' => 'X1',
            'serial_number' => 'SN-1',
            'hostname' => 'HOST-1',
            'company_id' => 1,
            'category_id' => 1,
            'location_id' => $this->jakarta->id,
            'asset_status_id' => 1,
        ], $overrides);
    }

    public function test_a_rejected_edit_keeps_what_was_typed_and_says_what_is_wrong(): void
    {
        $asset = Asset::factory()->create(['asset_id' => 'LAP-ORIGINAL', 'brand' => 'Lenovo']);
        Asset::factory()->create(['asset_id' => 'LAP-TAKEN']);

        // asset_id is taken, so the whole submission is rejected...
        $this->actingAs($this->support)
            ->from(route('assets.edit', $asset))
            ->put(route('assets.update', $asset), $this->assetPayload([
                'asset_id' => 'LAP-TAKEN',
                'brand' => 'HP',
                'model' => 'ProDesk',
                'hostname' => 'HOST-NEW',
                'location_id' => $this->bandung->id,
            ]))
            ->assertRedirect(route('assets.edit', $asset))
            ->assertSessionHasErrors('asset_id');

        // ...and what comes back has to carry the user's work and the reason.
        $this->followingRedirects()
            ->actingAs($this->support)
            ->from(route('assets.edit', $asset))
            ->put(route('assets.update', $asset), $this->assetPayload([
                'asset_id' => 'LAP-TAKEN',
                'brand' => 'HP',
                'model' => 'ProDesk',
                'hostname' => 'HOST-NEW',
                'location_id' => $this->bandung->id,
            ]))
            ->assertOk()
            ->assertSee('ProDesk')
            ->assertSee('HOST-NEW')
            ->assertSee('Bandung')
            ->assertSee('already been taken');

        // And nothing was written: a rejected form must not half-apply.
        $this->assertSame('Lenovo', $asset->fresh()->brand);
    }

    public function test_a_rejected_transfer_keeps_the_origin_the_user_chose(): void
    {
        $asset = Asset::factory()->create(['location_id' => $this->jakarta->id]);
        $target = User::factory()->create(['role' => 'viewer']);

        // to_location_id is missing, so the transfer is rejected...
        $this->actingAs($this->support)
            ->from(route('assets.transfer', $asset))
            ->post(route('assets.transfer.submit', $asset), [
                'transfer_date' => now()->toDateString(),
                'from_location_id' => $this->bandung->id,
                'to_user_id' => $target->id,
            ])
            ->assertSessionHasErrors('to_location_id');

        // ...and the origin the user picked must not quietly snap back to where
        // the asset happens to be. Two of the three selects on this form already
        // read old(); this one did not, so a rejected transfer would report a
        // journey from a place nobody chose.
        $this->followingRedirects()
            ->actingAs($this->support)
            ->from(route('assets.transfer', $asset))
            ->post(route('assets.transfer.submit', $asset), [
                'transfer_date' => now()->toDateString(),
                'from_location_id' => $this->bandung->id,
                'to_user_id' => $target->id,
            ])
            ->assertOk()
            ->assertSee('Bandung');
    }
}
