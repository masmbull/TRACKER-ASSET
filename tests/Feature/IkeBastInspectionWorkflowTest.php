<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
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
 * IKE/BAST inspection workflow beyond the create/show basics:
 * the list filters, editing the line items, the approval decision, and the
 * printable sheet.
 */
class IkeBastInspectionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Asset $asset;

    private User $support;

    protected function setUp(): void
    {
        parent::setUp();

        Company::create(['name' => 'PT. Test', 'code' => 'TT', 'address' => '']);
        AssetCategory::create(['name' => 'Laptop']);
        Location::create(['name' => 'HO', 'status' => 'active']);
        AssetStatus::create(['name' => 'Active']);

        $this->asset = Asset::factory()->create();
        $this->support = User::factory()->create(['role' => 'support']);
    }

    private function inspection(string $number, array $attributes = []): IkeBastInspectionSession
    {
        return IkeBastInspectionSession::factory()->create([
            'asset_id' => $this->asset->id,
            'created_by' => $this->support->id,
            'session_number' => $number,
            ...$attributes,
        ]);
    }

    public function test_the_inspection_list_can_be_searched_and_filtered(): void
    {
        $submitted = $this->inspection('IKE/20261110/001', ['status' => 'submitted', 'kind' => 'IKE_BAST']);
        $approved = $this->inspection('IKE/20261110/002', ['status' => 'approved', 'kind' => 'FAT']);

        $this->actingAs($this->support)
            ->get(route('ike-bast.inspection.index', ['search' => '001']))
            ->assertOk()
            ->assertSee('IKE/20261110/001')
            ->assertDontSee('IKE/20261110/002');

        $this->actingAs($this->support)
            ->get(route('ike-bast.inspection.index', ['status' => 'approved']))
            ->assertOk()
            ->assertSee($approved->session_number)
            ->assertDontSee($submitted->session_number);

        $this->actingAs($this->support)
            ->get(route('ike-bast.inspection.index', ['kind' => 'FAT']))
            ->assertOk()
            ->assertSee($approved->session_number)
            ->assertDontSee($submitted->session_number);
    }

    public function test_the_create_page_opens_for_the_asset(): void
    {
        $this->actingAs($this->support)
            ->get(route('ike-bast.inspection.create', $this->asset))
            ->assertOk()
            ->assertSee($this->asset->asset_id)
            ->assertSee('IKE_BAST');
    }

    public function test_updating_a_session_replaces_its_line_items(): void
    {
        $session = $this->inspection('IKE/20261110/010', [
            'kind' => 'IKE_BAST',
            'status' => 'draft',
            'notes' => 'old notes',
        ]);

        $oldLine = IkeBastInspectionLineItem::create([
            'session_id' => $session->id,
            'seq' => 1,
            'item_desc' => 'Old line',
        ]);

        $response = $this->actingAs($this->support)
            ->put(route('ike-bast.inspection.update', $session), [
                'inspection_date' => '2026-11-10',
                'kind' => 'BAST',
                'status' => 'submitted',
                'notes' => 'updated notes',
                'lines' => [
                    ['seq' => 1, 'item_desc' => 'Laptop unit', 'qty_planned' => 1, 'qty_received' => 1, 'test_result' => 'pass'],
                    ['seq' => 2, 'item_desc' => 'Charger', 'qty_planned' => 1, 'qty_received' => 0, 'test_result' => 'fail', 'is_defective' => true],
                ],
            ]);

        $response->assertRedirect(route('ike-bast.inspection.show', $session));
        $response->assertSessionHas('success', 'Inspection updated.');

        $session->refresh();
        $this->assertSame('BAST', $session->kind);
        $this->assertSame('submitted', $session->status);
        $this->assertSame('updated notes', $session->notes);
        $this->assertSame('2026-11-10', $session->inspection_date->toDateString());

        // The form is a full replacement, so the removed line must be gone
        // rather than quietly kept alongside the new ones.
        $this->assertDatabaseMissing('ike_bast_inspection_line_items', ['id' => $oldLine->id]);
        $this->assertSame(2, $session->lines()->count());
        $this->assertDatabaseHas('ike_bast_inspection_line_items', [
            'session_id' => $session->id,
            'item_desc' => 'Charger',
            'is_defective' => true,
        ]);
    }

    public function test_approving_and_rejecting_a_session_updates_it_and_writes_the_log(): void
    {
        $approved = $this->inspection('IKE/20261110/020', ['status' => 'submitted']);

        $this->actingAs($this->support)
            ->post(route('ike-bast.inspection.approve', $approved), [
                'status' => 'approved',
                'notes' => 'Semua sesuai.',
            ])
            ->assertRedirect(route('ike-bast.inspection.show', $approved))
            ->assertSessionHas('success', 'Session approved.');

        $approved->refresh();
        $this->assertSame('approved', $approved->status);
        $this->assertSame('Semua sesuai.', $approved->notes);

        $approvedLog = ActivityLog::where('action', 'APPROVED')
            ->where('entity_type', 'ike_bast_inspection_sessions')
            ->where('entity_id', $approved->id)
            ->firstOrFail();

        $this->assertSame($this->support->id, $approvedLog->actor_id);
        $this->assertSame($approved->session_number, $approvedLog->entity_identifier);
        $this->assertSame('IKE_BAST Inspection', $approvedLog->source);

        $rejected = $this->inspection('IKE/20261110/021', ['status' => 'submitted']);

        $this->actingAs($this->support)
            ->post(route('ike-bast.inspection.approve', $rejected), [
                'status' => 'rejected',
                'notes' => 'Serial tidak cocok.',
            ])
            ->assertRedirect(route('ike-bast.inspection.show', $rejected));

        $this->assertSame('rejected', $rejected->fresh()->status);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'REJECTED',
            'entity_type' => 'ike_bast_inspection_sessions',
            'entity_id' => $rejected->id,
        ]);
    }

    public function test_an_invalid_approval_decision_is_rejected(): void
    {
        $session = $this->inspection('IKE/20261110/030', ['status' => 'submitted']);

        $this->actingAs($this->support)
            ->post(route('ike-bast.inspection.approve', $session), ['status' => 'maybe'])
            ->assertSessionHasErrors('status');

        $this->assertSame('submitted', $session->fresh()->status);
    }

    public function test_the_printable_sheet_renders_the_session(): void
    {
        $session = $this->inspection('IKE/20261110/040', ['status' => 'approved']);

        IkeBastInspectionLineItem::create([
            'session_id' => $session->id,
            'seq' => 1,
            'item_desc' => 'Laptop unit',
            'test_result' => 'pass',
        ]);

        $this->actingAs($this->support)
            ->get(route('ike-bast.inspection.print', $session))
            ->assertOk()
            ->assertSee('Lembar Inspeksi Aset IT')
            ->assertSee($session->session_number)
            ->assertSee('Laptop unit');
    }

    public function test_viewers_cannot_run_the_inspection_workflow(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $session = $this->inspection('IKE/20261110/050', ['status' => 'submitted']);

        $this->actingAs($viewer)
            ->get(route('ike-bast.inspection.index'))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->put(route('ike-bast.inspection.update', $session), [
                'kind' => 'IKE_BAST',
                'lines' => [['seq' => 1, 'item_desc' => 'Nope']],
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('ike-bast.inspection.approve', $session), ['status' => 'approved'])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get(route('ike-bast.inspection.print', $session))
            ->assertForbidden();

        $this->assertSame('submitted', $session->fresh()->status);
    }
}
