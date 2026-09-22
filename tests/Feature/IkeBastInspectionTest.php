<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\IkeBastInspectionLineItem;
use App\Models\IkeBastInspectionSession;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IKE_BAST Inspection Table workflow (new, best-effort).
 */
class IkeBastInspectionTest extends TestCase
{
    use RefreshDatabase;

    protected $base;

    protected function setUp(): void
    {
        parent::setUp();
        Company::create(['name' => 'PT. Test', 'code' => 'TT', 'address' => '']);
        AssetCategory::create(['name' => 'Laptop']);
        Location::create(['name' => 'HO', 'status' => 'active']);
        AssetStatus::create(['name' => 'Active']);
        $this->base = Asset::factory()->create();
    }

    public function test_support_can_create_inspection_session_with_lines(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $response = $this->actingAs($support)->post(route('ike-bast.inspection.store'), [
            'asset_id' => $this->base->id,
            'kind' => 'IKE_BAST',
            'inspection_date' => now()->toDateString(),
            'notes' => 'test',
            'lines' => [
                ['seq' => 1, 'item_desc' => 'Unit utama', 'qty_planned' => 1, 'qty_received' => 1, 'test_result' => 'pass'],
                ['seq' => 2, 'item_desc' => 'Aksesoris', 'qty_planned' => 2, 'qty_received' => 1, 'test_result' => 'fail', 'is_defective' => true],
            ],
        ]);
        $response->assertRedirect();
        $session = IkeBastInspectionSession::firstOrFail();
        $this->assertEquals(2, $session->lines()->count());
        $this->assertStringContainsString('IKE/', $session->session_number);
    }

    public function test_show_inspection_session_displays_lines(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $session = IkeBastInspectionSession::factory()->create(['created_by' => $support->id, 'asset_id' => $this->base->id]);
        IkeBastInspectionLineItem::create(['session_id' => $session->id, 'seq' => 1, 'item_desc' => 'test item']);
        $response = $this->actingAs($support)->get(route('ike-bast.inspection.show', $session));
        $response->assertOk()->assertSee('test item');
    }

    public function test_viewer_cannot_create_inspection(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)->post(route('ike-bast.inspection.store'), [
            'asset_id' => $this->base->id, 'kind' => 'IKE_BAST', 'lines' => [['seq' => 1]],
        ])->assertForbidden();
    }
}
