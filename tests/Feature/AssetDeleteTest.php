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
 * Permanent asset deletion.
 *
 * This is the one action in the app that destroys a device record for good, so
 * the tests care mainly about who can do it and what it takes to be sure they
 * meant to.
 */
class AssetDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function asset(string $assetId = 'MITO-LAP-2026-0001'): Asset
    {
        $company = Company::firstOrCreate(['name' => 'PT. Mahakarya Sukses Indonesia'], ['code' => 'MITO']);
        $category = AssetCategory::firstOrCreate(['name' => 'Laptop'], ['code' => 'LAP']);

        return Asset::create([
            'asset_id' => $assetId,
            'company_id' => $company->id,
            'category_id' => $category->id,
            'brand' => 'Dell',
            'model' => 'Latitude 3420',
            'serial_number' => 'SN-'.uniqid(),
            'hostname' => 'PC-'.uniqid(),
            'location_id' => Location::firstOrCreate(['name' => 'HQ'], ['code' => 'HQ'])->id,
            'asset_status_id' => AssetStatus::firstOrCreate(['name' => 'Active'], ['code' => 'ACT'])->id,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_delete_when_confirming_the_asset_number(): void
    {
        $asset = $this->asset();

        $this->actingAs($this->admin())
            ->delete(route('assets.force-destroy', $asset), ['confirm_asset_id' => $asset->asset_id])
            ->assertRedirect(route('assets.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('assets', ['asset_id' => $asset->asset_id]);
    }

    /**
     * The whole point of asking for the number: a stray submit with a stale or
     * mismatched value must not destroy anything.
     */
    public function test_wrong_confirmation_keeps_the_asset(): void
    {
        $asset = $this->asset();

        $this->actingAs($this->admin())
            ->delete(route('assets.force-destroy', $asset), ['confirm_asset_id' => 'MITO-LAP-2026-9999'])
            ->assertSessionHasErrors('confirm_asset_id');

        $this->assertDatabaseHas('assets', ['asset_id' => $asset->asset_id]);
    }

    public function test_missing_confirmation_keeps_the_asset(): void
    {
        $asset = $this->asset();

        $this->actingAs($this->admin())
            ->delete(route('assets.force-destroy', $asset))
            ->assertSessionHasErrors('confirm_asset_id');

        $this->assertDatabaseHas('assets', ['asset_id' => $asset->asset_id]);
    }

    /** A support user may archive, but not destroy. */
    public function test_non_admin_cannot_delete(): void
    {
        $asset = $this->asset();
        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)
            ->delete(route('assets.force-destroy', $asset), ['confirm_asset_id' => $asset->asset_id])
            ->assertForbidden();

        $this->assertDatabaseHas('assets', ['asset_id' => $asset->asset_id]);
    }

    public function test_guest_cannot_delete(): void
    {
        $asset = $this->asset();

        $this->delete(route('assets.force-destroy', $asset), ['confirm_asset_id' => $asset->asset_id])
            ->assertRedirect(route('login'));

        $this->assertDatabaseHas('assets', ['asset_id' => $asset->asset_id]);
    }

    /** The audit trail must name what was destroyed, even though the row is gone. */
    public function test_deletion_is_recorded_in_the_activity_log(): void
    {
        $asset = $this->asset();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->delete(route('assets.force-destroy', $asset), ['confirm_asset_id' => $asset->asset_id]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'DELETE',
            'entity_type' => 'assets',
            'entity_identifier' => $asset->asset_id,
            'actor_id' => $admin->id,
        ]);
    }

    /** Archiving is a different action and must still leave the row in place. */
    public function test_archive_route_still_only_archives(): void
    {
        $asset = $this->asset();

        $this->actingAs($this->admin())
            ->delete(route('assets.destroy', $asset))
            ->assertRedirect(route('assets.index'));

        $this->assertDatabaseHas('assets', ['asset_id' => $asset->asset_id, 'is_active' => false]);
    }

    /**
     * An asset with audit history cannot be deleted: those rows are the machine's
     * history, and the database refuses the delete without ON DELETE CASCADE. That
     * used to surface as a 500 page with no explanation.
     */
    public function test_asset_with_audit_history_is_refused_with_a_message(): void
    {
        $asset = $this->asset();

        \DB::table('asset_audits')->insert([
            'asset_id' => $asset->id,
            'audit_date' => now()->toDateString(),
            'result' => 'ok',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->delete(route('assets.force-destroy', $asset), ['confirm_asset_id' => $asset->asset_id])
            ->assertSessionHasErrors('delete');

        $this->assertDatabaseHas('assets', ['asset_id' => $asset->asset_id]);
        $this->assertDatabaseHas('asset_audits', ['asset_id' => $asset->id]);
    }

    /** The refusal names what is holding the asset, not just that it failed. */
    public function test_refusal_message_names_the_blocking_history(): void
    {
        $asset = $this->asset();

        \DB::table('asset_audits')->insert([
            'asset_id' => $asset->id,
            'audit_date' => now()->toDateString(),
            'result' => 'ok',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin())
            ->delete(route('assets.force-destroy', $asset), ['confirm_asset_id' => $asset->asset_id]);

        $response->assertSessionHasErrors('delete');

        $message = (string) $response->getSession()->get('errors')->first('delete');

        $this->assertStringContainsString('catatan audit', $message);
        $this->assertStringContainsString('Arsipkan', $message);
    }

    /** No history means nothing blocks it, and the row really goes. */
    public function test_asset_without_history_is_deleted(): void
    {
        $asset = $this->asset();

        $this->actingAs($this->admin())
            ->delete(route('assets.force-destroy', $asset), ['confirm_asset_id' => $asset->asset_id])
            ->assertRedirect(route('assets.index'));

        $this->assertDatabaseMissing('assets', ['asset_id' => $asset->asset_id]);
    }
}
