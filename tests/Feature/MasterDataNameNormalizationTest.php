<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use App\Services\MasterDataNormalizer;
use App\Support\NameNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * One entity, one row (PRD §17-§20).
 *
 * The register held two companies whose names were identical on screen and
 * different in the database, separated by a UTF-8 byte order mark, with five
 * assets attached to the copy that could not be seen. Three things have to hold
 * for that not to come back: writes are cleaned, the repair folds the pairs
 * together, and the repair does not over-reach.
 */
class MasterDataNameNormalizationTest extends TestCase
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

    /**
     * Insert past the model's mutator, the way the importer used to.
     *
     * Anything written through Eloquent is cleaned now, so the bad rows this
     * repair exists for can only be reproduced by writing raw - which is exactly
     * how they got there in the first place.
     */
    private function insertRaw(string $table, array $attributes): int
    {
        return (int) DB::table($table)->insertGetId($attributes);
    }

    public function test_invisible_characters_never_reach_the_database(): void
    {
        // The exact byte order mark an Excel export puts on the sheet, plus the
        // other invisibles a copy-paste can carry.
        $company = Company::create(['name' => "\u{FEFF}PT. Mito", 'code' => 'MITO-B', 'address' => '']);
        $location = Location::create(['name' => "Jakarta\u{200B}HQ", 'status' => 'active']);
        $category = AssetCategory::create(['name' => "Net\u{00AD}work"]);
        $status = AssetStatus::create(['name' => "In\u{200C}Use"]);

        $this->assertSame('PT. Mito', $company->fresh()->name);
        $this->assertSame('JakartaHQ', $location->fresh()->name);
        $this->assertSame('Network', $category->fresh()->name);
        $this->assertSame('InUse', $status->fresh()->name);
    }

    public function test_a_name_that_normalises_onto_an_existing_one_is_refused_by_the_database(): void
    {
        // setUp already holds "Laptop". A soft hyphen inside "Lap-top" is not a
        // different category, it is the same word, and asset_categories.name has
        // carried a unique index all along - this is what stopped the register
        // splitting, and why companies and locations were the ones that did.
        $this->expectException(UniqueConstraintViolationException::class);

        AssetCategory::create(['name' => "Lap\u{00AD}top"]);
    }

    public function test_an_invisible_space_between_words_stays_a_space(): void
    {
        // A non-breaking space is invisible but is standing in for a real
        // boundary. Deleting it would silently glue two words together, turning
        // "PT. Mito" into "PT.Mito" - a different company name, not a tidy one.
        $location = Location::create(['name' => "Bandung\u{00A0}Office", 'status' => 'active']);

        $this->assertSame('Bandung Office', $location->fresh()->name);
    }

    public function test_surrounding_and_repeated_whitespace_collapses(): void
    {
        $category = AssetCategory::create(['name' => "  CCTV   \u{FEFF} Camera  "]);

        $this->assertSame('CCTV Camera', $category->fresh()->name);
    }

    public function test_a_lookup_matches_a_name_that_differs_only_by_an_invisible_character(): void
    {
        // Stored clean, queried dirty - the direction a pasted or exported value
        // arrives in.
        $found = Company::query()->whereNameIs("\u{FEFF}test co")->first();

        $this->assertNotNull($found);
        $this->assertSame('Test Co', $found->name);
    }

    public function test_the_repair_merges_an_invisible_duplicate_and_repoints_its_assets(): void
    {
        $duplicate = $this->insertRaw('companies', [
            'name' => "\u{FEFF}Test Co",
            'code' => 'TC-DUP',
            'status' => 'active',
        ]);

        $stranded = Asset::factory()->create(['company_id' => $duplicate]);

        $report = (new MasterDataNormalizer)->repair();

        $this->assertArrayHasKey((string) $duplicate, $report['companies']['merged']);
        $this->assertDatabaseMissing('companies', ['id' => $duplicate]);
        $this->assertSame(1, Company::count());
        $this->assertSame('Test Co', Company::firstOrFail()->name);

        // The asset has to follow, or it would point at a row that is gone.
        $this->assertSame(1, $stranded->fresh()->company_id);
    }

    public function test_the_repair_merges_a_duplicate_location(): void
    {
        $duplicate = $this->insertRaw('locations', [
            'name' => "Jakarta\u{00A0}",
            'status' => 'active',
        ]);

        $asset = Asset::factory()->create(['location_id' => $duplicate]);

        (new MasterDataNormalizer)->repair();

        $this->assertDatabaseMissing('locations', ['id' => $duplicate]);
        $this->assertSame(1, $asset->fresh()->location_id);
    }

    public function test_the_repair_rewrites_a_self_referencing_category_without_creating_a_loop(): void
    {
        // asset_categories.parent_id points at asset_categories. Folding a parent
        // away has to move its children across, and must not leave the surviving
        // row hanging under itself.
        $parentDuplicate = $this->insertRaw('asset_categories', [
            'name' => "\u{FEFF}Network",
            'status' => 'active',
        ]);

        $this->insertRaw('asset_categories', [
            'name' => 'Switch',
            'parent_id' => $parentDuplicate,
            'status' => 'active',
        ]);

        (new MasterDataNormalizer)->repair();

        $parent = AssetCategory::where('name', 'Network')->firstOrFail();
        $movedChild = AssetCategory::where('name', 'Switch')->firstOrFail();

        $this->assertSame($parent->id, $movedChild->parent_id);
        $this->assertNotSame($movedChild->id, $movedChild->parent_id);
    }

    public function test_the_repair_leaves_names_that_merely_look_alike_alone(): void
    {
        // "PT. Mito" and "PT Mito" are different strings. Concluding they are the
        // same organisation is a business decision, and guessing it would destroy
        // a distinction somebody may have made deliberately.
        $this->insertRaw('companies', ['name' => 'PT. Mito', 'code' => 'A', 'status' => 'active']);
        $this->insertRaw('companies', ['name' => 'PT Mito', 'code' => 'B', 'status' => 'active']);

        (new MasterDataNormalizer)->repair();

        $this->assertSame(3, Company::count());
    }

    public function test_the_repair_is_idempotent(): void
    {
        $this->insertRaw('companies', ['name' => "\u{FEFF}Test Co", 'code' => 'TC-DUP', 'status' => 'active']);

        $repair = new MasterDataNormalizer;
        $first = $repair->repair();
        $second = $repair->repair();

        $this->assertNotEmpty($first['companies']['merged']);
        $this->assertSame(['renamed' => 0, 'merged' => []], $second['companies']);
        $this->assertSame(1, Company::count());
    }

    public function test_the_master_data_form_rejects_a_name_that_differs_only_by_an_invisible_character(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        // The model normalises on the way in, so without normalising the request
        // first this passes the unique rule, is then cleaned, and collides with
        // the row the form was meant to reject - an unhandled integrity error
        // rather than a message under the field.
        $this->actingAs($support)->post(route('master.store', 'locations'), [
            'name' => "\u{FEFF}Jakarta",
            'status' => 'active',
        ])->assertSessionHasErrors('name');

        $this->assertSame(1, Location::count());
    }

    public function test_editing_a_record_keeps_its_own_name_available(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $location = Location::where('name', 'Jakarta')->firstOrFail();

        // Re-saving a record under the name it already has must not read as a
        // duplicate of itself, which is what the ignore-id half of the rule is for.
        $this->actingAs($support)
            ->put(route('master.update', ['locations', $location->id]), [
                'name' => "\u{FEFF}Jakarta",
                'status' => 'inactive',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('inactive', $location->fresh()->status);
    }

    public function test_the_index_guard_names_rows_that_differ_only_by_capitalisation(): void
    {
        $this->insertRaw('locations', ['name' => 'jakarta', 'status' => 'active']);

        // MySQL would reject a unique index here and SQLite would accept it, so
        // the two databases would quietly disagree. The caller has to be able to
        // see that, and a case variant stays two rows: unlike an invisible
        // character, somebody typed it and can see that they did.
        $this->assertSame(
            ['jakarta'],
            (new MasterDataNormalizer)->capitalisationCollisions('locations')
        );
    }

    public function test_a_table_without_case_variants_reports_nothing_to_skip(): void
    {
        $this->assertSame([], (new MasterDataNormalizer)->capitalisationCollisions('companies'));
    }

    public function test_the_normalizer_reports_an_empty_name_rather_than_a_string_of_spaces(): void
    {
        $this->assertNull(NameNormalizer::normalize("\u{FEFF}\u{200B}"));
        $this->assertNull(NameNormalizer::normalize('   '));
        $this->assertSame('MITO', NameNormalizer::normalize("\u{FEFF} MITO \u{200B}"));
    }
}
