<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Document;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * FAT/BAST generation: auto-populate, preview, PDF download (PRD §32–§35, §46).
 */
class DocumentGenerationTest extends TestCase
{
    use RefreshDatabase;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Company::create(['name' => 'Test Co', 'code' => 'TC', 'address' => '']);
        AssetCategory::create(['name' => 'Laptop']);
        Location::create(['name' => 'Jakarta', 'status' => 'active']);
        AssetStatus::create(['name' => 'Active']);

        $this->asset = Asset::factory()->create();
    }

    public function test_generate_fat_creates_numbered_document_with_pdf(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $response = $this->actingAs($support)->post(route('documents.generate.store', $this->asset), [
            'document_type' => 'FAT',
            'document_date' => '2026-09-22',
            'signatory_menyerahkan' => 'Andi',
            'signatory_menerima' => 'Budi',
            'signatory_mengetahui' => 'Citra',
            'signatory_it' => 'Deni',
            'checklist' => [0 => 'pass', 1 => 'fail'],
            'notes' => 'Unit sealed box',
        ]);

        $document = Document::firstOrFail();

        $response->assertRedirect(route('documents.preview', $document));
        $response->assertSessionHas('success');

        // Sequential, duplicate-proof numbering.
        $this->assertSame('FAT/IT/'.now()->format('Y').'/00001', $document->document_number);
        $this->assertSame('FAT', $document->document_type);
        $this->assertSame('final', $document->status);
        $this->assertSame('pdf', $document->file_type);

        // Payload populated from the register — no copy/paste.
        $this->assertSame($this->asset->asset_id, $document->payload['asset']['asset_id']);
        $this->assertSame($this->asset->serial_number, $document->payload['asset']['serial_number']);
        $this->assertSame('Andi', $document->payload['signatories']['menyerahkan']);
        $this->assertSame('Unit sealed box', $document->payload['notes']);
        $this->assertSame('pass', $document->payload['checklist'][0]['result']);
        $this->assertSame('fail', $document->payload['checklist'][1]['result']);
        $this->assertSame('na', $document->payload['checklist'][2]['result']);

        Storage::assertExists($document->file_path);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'GENERATE_DOCUMENT',
            'entity_identifier' => $document->document_number,
        ]);
    }

    public function test_second_document_gets_next_number(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)->post(route('documents.generate.store', $this->asset), [
            'document_type' => 'FAT',
        ]);

        $response = $this->actingAs($support)->post(route('documents.generate.store', $this->asset), [
            'document_type' => 'FAT',
        ]);

        $response->assertRedirect(route('documents.preview', Document::latest('id')->firstOrFail()));

        $this->assertSame(
            'FAT/IT/'.now()->format('Y').'/00002',
            Document::latest('id')->firstOrFail()->document_number
        );
    }

    public function test_preview_and_pdf_download(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)->post(route('documents.generate.store', $this->asset), [
            'document_type' => 'FAT',
        ]);

        $document = Document::firstOrFail();

        $this->actingAs($viewer)->get(route('documents.preview', $document))
            ->assertOk()
            ->assertSee($document->document_number);

        $inline = $this->actingAs($viewer)->get(route('documents.download', $document).'?inline=1');
        $inline->assertOk();
        $this->assertStringContainsString('application/pdf', $inline->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $inline->streamedContent());

        $download = $this->actingAs($viewer)->get(route('documents.download', $document));
        $download->assertOk();
        $this->assertStringContainsString('attachment', $download->headers->get('Content-Disposition'));
    }

    public function test_generate_bast_without_checklist(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $response = $this->actingAs($support)->post(route('documents.generate.store', $this->asset), [
            'document_type' => 'BAST',
            'signatory_menyerahkan' => 'Andi',
            'signatory_menerima' => 'Budi',
        ]);

        $document = Document::firstOrFail();

        $response->assertRedirect(route('documents.preview', $document));
        $this->assertSame('BAST/IT/'.now()->format('Y').'/00001', $document->document_number);
        $this->assertSame([], $document->payload['checklist']);
    }

    public function test_viewer_cannot_generate_documents(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->get(route('documents.generate', $this->asset))->assertForbidden();
        $this->actingAs($viewer)->post(route('documents.generate.store', $this->asset), [
            'document_type' => 'FAT',
        ])->assertForbidden();

        $this->assertDatabaseCount('documents', 0);
    }
}
