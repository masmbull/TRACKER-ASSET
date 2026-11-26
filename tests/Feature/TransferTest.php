<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\AssetTransfer;
use App\Models\Company;
use App\Models\Document;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Transfer workflow (PRD §16, §30, §31, §46): history preserved atomically.
 */
class TransferTest extends TestCase
{
    use RefreshDatabase;

    private User $support;

    private User $owner;

    private User $target;

    private Asset $asset;

    private Location $jakarta;

    private Location $bandung;

    protected function setUp(): void
    {
        parent::setUp();

        // submitTransfer now generates a PDF for every transfer. Keep those files
        // out of the real storage/app/documents directory during tests.
        Storage::fake('local');

        Company::create(['name' => 'Test Co', 'code' => 'TC', 'address' => '']);
        AssetCategory::create(['name' => 'Laptop']);
        $this->jakarta = Location::create(['name' => 'Jakarta', 'status' => 'active']);
        $this->bandung = Location::create(['name' => 'Bandung', 'status' => 'active']);
        AssetStatus::create(['name' => 'Assigned']);

        $this->support = User::factory()->create(['role' => 'support', 'department' => 'IT']);
        $this->owner = User::factory()->create(['role' => 'viewer', 'department' => 'Sales']);
        $this->target = User::factory()->create(['role' => 'viewer', 'department' => 'Finance']);

        $this->asset = Asset::factory()->create([
            'current_user_id' => $this->owner->id,
            'location_id' => $this->jakarta->id,
        ]);

        AssetAssignment::create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->owner->id,
            'assigned_date' => '2024-01-01',
            'from_location_id' => $this->jakarta->id,
            'to_location_id' => $this->jakarta->id,
            'created_by' => $this->support->id,
        ]);
    }

    /**
     * Defaults to Jakarta -> Bandung by name.
     *
     * These used to be the literal ids 1 and 2, which only held because the
     * locations happened to be the first two rows created. A PostgreSQL sequence
     * carries on past a rolled-back test, so from the second test onwards 1 and 2
     * named nothing at all. See the note in AssetFactory.
     */
    private function transferPayload(int $toUser, ?int $fromLocation = null, ?int $toLocation = null): array
    {
        return [
            'from_location_id' => $fromLocation ?? $this->jakarta->id,
            'to_location_id' => $toLocation ?? $this->bandung->id,
            'to_user_id' => $toUser,
            'transfer_date' => '2026-09-22',
            'condition' => 'Good',
            'accessories' => 'Charger + bag',
            'notes' => 'Handover',
        ];
    }

    public function test_transfer_closes_previous_assignment_and_preserves_history(): void
    {
        $response = $this->actingAs($this->support)
            ->post("/assets/{$this->asset->id}/transfer", $this->transferPayload($this->target->id));

        $response->assertRedirect(route('assets.show', $this->asset));

        $asset = $this->asset->fresh();
        $this->assertSame($this->target->id, $asset->current_user_id);
        $this->assertSame($this->bandung->id, $asset->location_id);

        // Old assignment is closed, not deleted.
        $old = AssetAssignment::where('asset_id', $asset->id)
            ->where('user_id', $this->owner->id)
            ->firstOrFail();
        $this->assertSame('2026-09-22', $old->returned_date?->toDateString());

        // New assignment references the transfer record.
        $new = AssetAssignment::where('asset_id', $asset->id)
            ->where('user_id', $this->target->id)
            ->firstOrFail();
        $this->assertNotNull($new->transfer_id);
        $this->assertSame('2026-09-22', $new->assigned_date?->toDateString());

        $transfer = AssetTransfer::firstOrFail();
        $this->assertSame($this->owner->id, $transfer->from_user_id);
        $this->assertSame($this->target->id, $transfer->to_user_id);
        $this->assertSame('Sales', $transfer->from_department);
        $this->assertSame('Finance', $transfer->to_department);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'TRANSFER',
            'entity_identifier' => $asset->asset_id,
        ]);
    }

    public function test_transfer_generates_fat_document_and_pdf(): void
    {
        $this->actingAs($this->support)
            ->post("/assets/{$this->asset->id}/transfer", $this->transferPayload($this->target->id))
            ->assertRedirect();

        $transfer = AssetTransfer::firstOrFail();
        $document = Document::firstOrFail();

        $expectedNumber = str_replace(
            ['{month}', '{year}', '{seq:4}'],
            [now()->format('m'), now()->format('Y'), '0001'],
            config('documents.numbering.FAT')
        );

        $this->assertSame('FAT', $document->document_type);
        $this->assertSame($transfer->id, $document->transfer_id);
        $this->assertSame($this->asset->id, $document->asset_id);
        $this->assertSame($this->support->id, $document->generated_by);
        $this->assertSame($expectedNumber, $document->document_number);
        $this->assertStringStartsWith('documents/', $document->file_path);
        Storage::assertExists($document->file_path);
        $this->assertStringStartsWith('%PDF', Storage::get($document->file_path));
        $this->assertTrue($this->asset->fresh()->documents->contains($document));

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'GENERATE_DOCUMENT',
            'entity_id' => $document->id,
        ]);
    }

    public function test_transfer_history_links_to_generated_fat_pdf(): void
    {
        $this->actingAs($this->support)
            ->post("/assets/{$this->asset->id}/transfer", $this->transferPayload($this->target->id));

        $document = Document::firstOrFail();

        $this->actingAs($this->support)
            ->get('/transfers')
            ->assertOk()
            ->assertSee('FAT PDF')
            ->assertSee(route('documents.download', $document), false);
    }

    public function test_transfer_numbers_are_sequential_per_year(): void
    {
        $this->actingAs($this->support)
            ->post("/assets/{$this->asset->id}/transfer", $this->transferPayload($this->target->id))
            ->assertRedirect();

        // Back the other way, so the second transfer exercises the reverse route.
        $this->actingAs($this->support)
            ->post("/assets/{$this->asset->id}/transfer", $this->transferPayload($this->owner->id, $this->bandung->id, $this->jakarta->id))
            ->assertRedirect();

        $numbers = AssetTransfer::orderBy('id')->pluck('transfer_number');

        $this->assertCount(2, $numbers);
        $this->assertSame('AST/TRF/'.now()->format('Y').'/00001', $numbers[0]);
        $this->assertSame('AST/TRF/'.now()->format('Y').'/00002', $numbers[1]);
    }

    public function test_viewer_cannot_transfer(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)
            ->post("/assets/{$this->asset->id}/transfer", $this->transferPayload($this->target->id))
            ->assertForbidden();

        $this->assertDatabaseCount('asset_transfers', 0);
    }

    public function test_transfer_history_index_requires_auth(): void
    {
        $this->get('/transfers')->assertRedirect('/login');
    }

    public function test_transfer_history_index_is_accessible(): void
    {
        $this->actingAs($this->support)
            ->get('/transfers')
            ->assertOk()
            ->assertSee('Transfer History');
    }

    public function test_transfer_history_shows_transfers(): void
    {
        $this->actingAs($this->support)
            ->post("/assets/{$this->asset->id}/transfer", $this->transferPayload($this->target->id));

        $this->actingAs($this->support)
            ->get('/transfers')
            ->assertOk()
            ->assertSee('AST/TRF');
    }

    public function test_retired_assets_page_is_accessible(): void
    {
        $this->actingAs($this->support)
            ->get('/assets/retired')
            ->assertOk()
            ->assertSee('Retired');
    }

    public function test_retired_assets_shows_retired_items(): void
    {
        $retiredStatus = AssetStatus::create(['name' => 'Retired']);
        $this->asset->update([
            'asset_status_id' => $retiredStatus->id,
            'retire_date' => '2026-01-01',
            'current_user_id' => null,
        ]);

        $this->actingAs($this->support)
            ->get('/assets/retired')
            ->assertOk()
            ->assertSee($this->asset->asset_id);
    }
}
