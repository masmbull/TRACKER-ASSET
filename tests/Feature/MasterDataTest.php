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
 * Master data CRUD (PRD §17–§20, §46) + delete protection.
 */
class MasterDataTest extends TestCase
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

    public function test_support_can_create_and_update_category(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $response = $this->actingAs($support)->post(route('master.store', 'categories'), [
            'name' => 'Tablet',
            'status' => 'active',
        ]);

        $response->assertRedirect(route('master.index', 'categories'));
        $this->assertDatabaseHas('asset_categories', ['name' => 'Tablet']);

        $category = AssetCategory::where('name', 'Tablet')->firstOrFail();

        $this->actingAs($support)->put(route('master.update', ['categories', $category->id]), [
            'name' => 'Tablet Pro',
            'status' => 'inactive',
        ])->assertRedirect(route('master.index', 'categories'));

        $this->assertDatabaseHas('asset_categories', ['id' => $category->id, 'name' => 'Tablet Pro', 'status' => 'inactive']);
    }

    public function test_referenced_record_cannot_be_deleted(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $category = AssetCategory::where('name', 'Laptop')->firstOrFail();

        Asset::factory()->create(['category_id' => $category->id]);

        $response = $this->actingAs($support)->delete(route('master.destroy', ['categories', $category->id]));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('asset_categories', ['id' => $category->id]);
    }

    public function test_unused_record_can_be_deleted(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $company = Company::create(['name' => 'Ghost Co', 'code' => 'GHOST', 'address' => '']);

        $this->actingAs($support)->delete(route('master.destroy', ['companies', $company->id]))
            ->assertRedirect(route('master.index', 'companies'));

        $this->assertDatabaseMissing('companies', ['id' => $company->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'DELETE', 'entity_type' => 'companies']);
    }

    public function test_duplicate_name_is_rejected(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        // "Jakarta" already exists from setUp. A second insert of it is no longer
        // possible either, since locations.name carries a unique index - the
        // database now refuses what the form has always promised to refuse.
        $this->actingAs($support)->post(route('master.store', 'locations'), [
            'name' => 'Jakarta',
            'status' => 'active',
        ])->assertSessionHasErrors('name');

        $this->assertSame(1, Location::where('name', 'Jakarta')->count());
    }

    public function test_viewer_cannot_access_master_data(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->get(route('master.index', 'companies'))->assertForbidden();
        $this->actingAs($viewer)->post(route('master.store', 'companies'), ['name' => 'X'])->assertForbidden();
    }

    public function test_master_create_and_edit_forms_render_for_support(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $category = AssetCategory::where('name', 'Laptop')->firstOrFail();

        $this->actingAs($support)
            ->get(route('master.create', 'categories'))
            ->assertOk()
            ->assertSee('New Category')
            ->assertSee('name="name"', false);

        $this->actingAs($support)
            ->get(route('master.edit', ['categories', $category->id]))
            ->assertOk()
            ->assertSee('Edit Category')
            ->assertSee('value="Laptop"', false);
    }

    public function test_viewer_cannot_open_master_forms(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $company = Company::firstOrFail();

        $this->actingAs($viewer)
            ->get(route('master.create', 'companies'))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get(route('master.edit', ['companies', $company->id]))
            ->assertForbidden();
    }

    public function test_unknown_type_is_404(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)->get(route('master.index', 'weapons'))->assertNotFound();
    }

    public function test_all_four_types_are_reachable(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        foreach (['companies', 'locations', 'categories', 'statuses'] as $type) {
            $this->actingAs($support)->get(route('master.index', $type))->assertOk();
        }
    }
}
