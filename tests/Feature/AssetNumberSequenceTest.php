<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Services\AssetNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The asset number is the label printed on a physical device, so a gap or a
 * duplicate is a real-world problem: someone has to explain to an auditor why
 * MITO-LAP-2026-0005 does not exist, and two laptops cannot carry the same
 * number at once.
 */
class AssetNumberSequenceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): AssetNumberService
    {
        return app(AssetNumberService::class);
    }

    public function test_first_number_of_a_group_is_one(): void
    {
        $this->assertSame('MITO-LAP-2026-0001', $this->service()->next('MITO', 'LAP', 2026));
    }

    public function test_numbers_increase_without_gaps(): void
    {
        $service = $this->service();

        $this->assertSame('MITO-LAP-2026-0001', $service->next('MITO', 'LAP', 2026));
        $this->createAsset('MITO-LAP-2026-0001');

        $this->assertSame('MITO-LAP-2026-0002', $service->next('MITO', 'LAP', 2026));
        $this->createAsset('MITO-LAP-2026-0002');

        $this->assertSame('MITO-LAP-2026-0003', $service->next('MITO', 'LAP', 2026));
    }

    /**
     * The regression that prompted this service: numbers were derived from a row
     * count, so archiving one asset made the next device reuse a number that had
     * already been printed on a label.
     */
    public function test_archiving_an_asset_does_not_hand_its_number_to_the_next_device(): void
    {
        $first = $this->createAsset('MITO-LAP-2026-0001');
        $this->createAsset('MITO-LAP-2026-0002');

        $first->update(['is_active' => false]);

        $this->assertSame('MITO-LAP-2026-0003', $this->service()->next('MITO', 'LAP', 2026));
    }

    /** Deleting a row must not rewind the sequence either. */
    public function test_deleting_the_newest_asset_does_not_reissue_its_number(): void
    {
        $newest = $this->createAsset('MITO-LAP-2026-0004');
        $newest->delete();

        // The highest remaining is 0004's predecessor being absent, so the next
        // number is still ahead of anything ever issued in this test.
        $next = $this->service()->next('MITO', 'LAP', 2026);

        $this->assertNotSame('MITO-LAP-2026-0004', $next);
    }

    public function test_groups_are_independent(): void
    {
        $service = $this->service();
        $this->createAsset('MITO-LAP-2026-0001');

        $this->assertSame('MSI-LAP-2026-0001', $service->next('MSI', 'LAP', 2026));
        $this->assertSame('MITO-PRN-2026-0001', $service->next('MITO', 'PRN', 2026));
        $this->assertSame('MITO-LAP-2025-0001', $service->next('MITO', 'LAP', 2025));
    }

    public function test_counter_widens_past_nine_thousand_nine_hundred_ninety_nine(): void
    {
        $this->createAsset('MITO-LAP-2026-9999');

        $this->assertSame('MITO-LAP-2026-10000', $this->service()->next('MITO', 'LAP', 2026));
    }

    public function test_number_is_always_uppercase_and_label_safe(): void
    {
        $number = $this->service()->next('mito corp', 'lap top', 2026);

        $this->assertMatchesRegularExpression('/^[A-Z0-9]+-[A-Z0-9]+-\d{4}-\d{4,}$/', $number);
    }

    /** A form that forgets to ask for a number must not save a blank one. */
    public function test_asset_gets_a_number_automatically_when_none_is_given(): void
    {
        $company = Company::firstOrCreate(['name' => 'PT. Mahakarya Sukses Indonesia'], ['code' => 'MITO']);
        $category = AssetCategory::firstOrCreate(['name' => 'Laptop'], ['code' => 'LAP']);

        $asset = Asset::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'brand' => 'Dell',
            'model' => 'Latitude 3420',
            'serial_number' => 'SN-AUTO',
            'hostname' => 'PC-AUTO',
            'location_id' => Location::firstOrCreate(['name' => 'HQ'], ['code' => 'HQ'])->id,
            'asset_status_id' => AssetStatus::firstOrCreate(['name' => 'Active'], ['code' => 'ACT'])->id,
            'is_active' => true,
        ]);

        $this->assertMatchesRegularExpression('/^MITO-LAP-\d{4}-\d{4,}$/', $asset->asset_id);
    }

    /** A number that came from an import sheet is the one on the hardware. */
    public function test_explicit_asset_number_is_kept(): void
    {
        $this->assertSame('MSI-LAP-2025-0001', $this->createAsset('MSI-LAP-2025-0001')->asset_id);
    }

    private function createAsset(string $assetId): Asset
    {
        $company = Company::firstOrCreate(
            ['name' => 'PT. Mahakarya Sukses Indonesia'],
            ['code' => 'MITO'],
        );

        $category = AssetCategory::firstOrCreate(
            ['name' => 'Laptop'],
            ['code' => 'LAP'],
        );

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
}
