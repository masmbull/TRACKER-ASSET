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
use Illuminate\Http\UploadedFile;
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

    public function test_documents_page_offers_blank_and_existing_asset_choices(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)
            ->get(route('documents.index'))
            ->assertOk()
            ->assertSee('Create blank document')
            ->assertSee($this->asset->asset_id);
    }

    public function test_existing_asset_selection_redirects_to_the_prefilled_form(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)
            ->get(route('documents.create-new', ['asset_id' => $this->asset->asset_id]))
            ->assertRedirect(route('documents.generate', $this->asset));
    }

    public function test_existing_asset_selection_rejects_an_unknown_asset_id(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $this->from(route('documents.index'))
            ->actingAs($support)
            ->get(route('documents.create-new', ['asset_id' => 'UNKNOWN-ASSET']))
            ->assertRedirect(route('documents.index'))
            ->assertSessionHasErrors('asset_id');
    }

    public function test_blank_document_form_accepts_manual_asset_details_and_creates_unlinked_pdf(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)
            ->get(route('documents.generate.blank'))
            ->assertOk()
            ->assertSee('Dokumen kosong')
            ->assertSee('name="asset_os_version"', false)
            ->assertSee('name="asset_storage_type"', false)
            ->assertSee('enctype="multipart/form-data"', false);

        $response = $this->actingAs($support)->post(route('documents.generate.blank.store'), [
            'document_type' => 'FAT',
            'sender_entity' => 'Test Co',
            'signatory_menyerahkan' => 'Andi',
            'receiver_entity' => 'Test Co',
            'signatory_menerima' => 'Budi',
            'asset_type' => 'Laptop',
            'asset_brand_model' => 'Dell Latitude',
            'asset_serial_number' => 'SN-001',
            'asset_tag' => 'MANUAL-001',
            'asset_processor' => 'Intel Core i5',
            'asset_ram' => '16 GB',
            'asset_storage' => '512 GB SSD',
            'asset_storage_type' => 'NVMe',
            'asset_storage_capacity' => '512 GB',
            'asset_os_version' => 'Windows 11 Pro',
        ]);

        $document = Document::firstOrFail();

        $response->assertRedirect(route('documents.preview', $document));
        $this->assertNull($document->asset_id);
        $this->assertSame('MANUAL-001', $document->payload['asset']['tag']);
        $this->assertSame('Windows 11 Pro', $document->payload['asset']['os_version']);
        $this->assertSame('NVMe', $document->payload['asset']['storage_type_detail']);
        $this->assertSame('Andi', $document->payload['parties']['sender']['name']);
        $this->assertModelExists($document);
        Storage::assertExists($document->file_path);

        $this->actingAs($support)
            ->get(route('documents.preview', $document))
            ->assertOk()
            ->assertSee($document->title);

        $this->actingAs($support)
            ->get(route('documents.preview.data', $document))
            ->assertOk()
            ->assertJsonPath('title', $document->title);

        $this->actingAs($support)
            ->get(route('documents.download', $document))
            ->assertOk();
    }

    public function test_uploaded_signature_is_stored_with_the_generated_document(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $signature = UploadedFile::fake()->createWithContent(
            'signature.png',
            file_get_contents(public_path('images/mito-logo-pdf.png'))
        );

        $this->actingAs($support)
            ->post(route('documents.generate.store', $this->asset), [
                'document_type' => 'FAT',
                'signatory_image' => $signature,
            ])
            ->assertRedirect();

        $document = Document::firstOrFail();

        $this->assertIsString($document->signature_image);
        $this->assertSame($document->signature_image, $document->payload['signature_image']);
        Storage::disk('local')->assertExists($document->signature_image);
        Storage::disk('local')->assertExists($document->file_path);
    }

    public function test_viewer_cannot_generate_a_blank_document(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)
            ->post(route('documents.generate.blank.store'), ['document_type' => 'FAT'])
            ->assertForbidden();

        $this->assertDatabaseCount('documents', 0);
    }

    /**
     * The document type is in the URL because the form is not the same form for
     * every type. It used to be ignored, so linking straight to a BAST produced a
     * FAT form. BAST deliberately uses the FAT date wording fallback, but its own
     * type-specific completeness list must still be selected.
     */
    public function test_the_type_in_the_url_selects_that_type(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)
            ->get(route('documents.generate', ['asset' => $this->asset, 'document_type' => 'BAST']))
            ->assertOk();

        $this->assertMatchesRegularExpression(
            '/<option\b(?=[^>]*\bvalue="BAST")(?=[^>]*\bdata-handover-label="Tanggal Serah Terima")(?=[^>]*\bselected\b)[^>]*>/s',
            $response->getContent(),
        );
        $response->assertSee('data-kelengkapan-type="BAST"', false)
            ->assertDontSee('data-kelengkapan-type="BAST" hidden', false)
            ->assertSee('id="handover-date-label"', false)
            ->assertSee('Tanggal Serah Terima');

        // The FAT section stays in the DOM for client-side switching and is only
        // flagged hidden, so match the attribute inside the tag instead of a
        // fixed substring - the surrounding whitespace is up to the template.
        $this->assertMatchesRegularExpression(
            '/<div\b(?=[^>]*\bdata-checklist-type="FAT")(?=[^>]*\bhidden\b)[^>]*>/s',
            $response->getContent(),
        );
    }

    public function test_document_date_fields_use_date_pickers_without_forcing_optional_dates(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)
            ->get(route('documents.generate', $this->asset))
            ->assertOk()
            ->assertSee('type="date" name="document_date"', false)
            ->assertSee('type="date" name="handover_date"', false)
            ->assertSee('type="date" name="received_date" value=""', false)
            ->assertSee('window.flatpickr(', false)
            ->assertDontSee('defaultDate:', false);

        $this->actingAs($support)
            ->get(route('documents.create', $this->asset))
            ->assertOk()
            ->assertSee('type="date" name="generated_date"', false)
            ->assertSee('window.flatpickr(', false)
            ->assertDontSee('defaultDate:', false);
    }

    /**
     * Only FAT has an acceptance checklist. The form keeps it in the DOM for
     * client-side type switching, but hides it when another type is selected.
     */
    public function test_only_a_type_with_a_checklist_gets_one(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('documents.generate', ['asset' => $this->asset, 'document_type' => 'FAT']))
            ->assertOk()
            ->assertSee('Checklist FAT');

        $response = $this->actingAs($admin)
            ->get(route('documents.generate', ['asset' => $this->asset, 'document_type' => 'BAST']))
            ->assertOk();

        $this->assertMatchesRegularExpression(
            '/<div\b(?=[^>]*\bdata-checklist-type="FAT")(?=[^>]*\bhidden\b)[^>]*>/s',
            $response->getContent(),
        );
        $response->assertDontSee('data-checklist-type="BAST"', false);
    }

    /**
     * An assignment is not a handover, so its date label and statement must not
     * say "Serah Terima". These strings are printed on a document people sign.
     */
    public function test_labels_and_statement_follow_the_type(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('documents.generate', ['asset' => $this->asset, 'document_type' => 'ASSIGNMENT']))
            ->assertOk()
            ->assertSee('Tanggal Penyerahan')
            ->assertSee('Aset ini diserahkan kepada penerima untuk dipergunakan dalam pelaksanaan tugas');

        $this->actingAs($admin)
            ->get(route('documents.generate', ['asset' => $this->asset, 'document_type' => 'FAT']))
            ->assertOk()
            ->assertSee('Tanggal Serah Terima');
    }

    /**
     * Entity is a choice from master data, not free text. Typed, "PT Mito" and
     * "PT. Mito" make one organisation look like two on a signed document.
     */
    public function test_the_entity_fields_offer_companies_instead_of_free_text(): void
    {
        Company::create(['name' => 'Anak Perusahaan', 'code' => 'AP', 'address' => '']);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('documents.generate', ['asset' => $this->asset]))
            ->assertOk()
            ->assertSee('<select name="sender_entity"', false)
            ->assertSee('<select name="receiver_entity"', false)
            ->assertSee('Anak Perusahaan')
            // The branch is its own field now rather than "Company / Jakarta"
            // mashed into one string that matches nothing.
            ->assertSee('name="receiver_location"', false);
    }

    /**
     * The whole sender and receiver block used to be validated and then dropped:
     * `buildPayload` never read it and no PDF template rendered it. Whoever filled
     * in the form had no way of knowing none of it was kept.
     */
    public function test_the_handover_parties_reach_the_document(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)->post(route('documents.generate.store', $this->asset), [
            'document_type' => 'FAT',
            'sender_entity' => 'Test Co',
            'sender_division' => 'IT',
            'sender_email' => 'andi@example.com',
            'signatory_menyerahkan' => 'Andi',
            'receiver_entity' => 'Anak Perusahaan',
            'signatory_menerima' => 'Budi',
            'receiver_location' => 'Jakarta',
        ])->assertRedirect();

        $parties = Document::firstOrFail()->payload['parties'];

        $this->assertSame('Test Co', $parties['sender']['entity']);
        $this->assertSame('Andi', $parties['sender']['name']);
        $this->assertSame('IT', $parties['sender']['division']);
        $this->assertSame('Anak Perusahaan', $parties['receiver']['entity']);
        $this->assertSame('Budi', $parties['receiver']['name']);
        $this->assertSame('Jakarta', $parties['receiver']['location']);
    }

    /**
     * Blank parties still produce a usable document: they fall back to the
     * register rather than printing an empty box, per the one-asset rule.
     */
    public function test_parties_fall_back_to_the_register_rather_than_blanks(): void
    {
        $this->asset->forceFill([
            'company_id' => Company::where('name', 'Test Co')->value('id'),
            'location_id' => Location::where('name', 'Jakarta')->value('id'),
        ])->save();

        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)->post(route('documents.generate.store', $this->asset), [
            'document_type' => 'FAT',
        ])->assertRedirect();

        $parties = Document::firstOrFail()->payload['parties'];

        $this->assertSame('Test Co', $parties['sender']['entity']);
        $this->assertSame('Jakarta', $parties['receiver']['location']);
    }

    /**
     * No document view rendered validation errors, so a rejected submission
     * bounced back to a form that looked identical to a successful one and there
     * was no way to see why nothing had happened.
     */
    public function test_a_rejected_submission_says_why(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)
            ->from(route('documents.generate', $this->asset))
            ->post(route('documents.generate.store', $this->asset), [
                'document_type' => 'NOT_A_TYPE',
            ])
            ->assertRedirect(route('documents.generate', $this->asset))
            ->assertSessionHasErrors('document_type');

        $this->actingAs($support)
            ->get(route('documents.generate', $this->asset))
            ->assertOk()
            ->assertSee('document_type');
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
        $this->assertSame('FAT/IT-'.now()->format('m').'-'.now()->format('Y').'/0001', $document->document_number);
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

    /**
     * The signature box on the right is labelled "IT / Penyerah Aset". Its name
     * must follow "Nama Penyerah", the field on the form people actually edit.
     * When both boxes showed the derived default, changing the sender on the
     * form still produced a PDF signed by the administrator for both parties.
     */
    public function test_signature_box_follows_the_sender_name_unless_overridden(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)->post(route('documents.generate.store', $this->asset), [
            'document_type' => 'FAT',
            'signatory_menyerahkan' => 'Andi Penyerah',
            'signatory_menerima' => 'Budi Penerima',
        ]);

        $signatories = Document::firstOrFail()->payload['signatories'];
        $this->assertSame('Andi Penyerah', $signatories['it']);
        $this->assertSame('Budi Penerima', $signatories['menerima']);

        // An explicit IT signer still wins when the two people differ.
        $this->actingAs($support)->post(route('documents.generate.store', $this->asset), [
            'document_type' => 'FAT',
            'signatory_menyerahkan' => 'Andi Penyerah',
            'signatory_menerima' => 'Budi Penerima',
            'signatory_it' => 'Deni IT',
        ]);

        $this->assertSame('Deni IT', Document::latest('id')->firstOrFail()->payload['signatories']['it']);
    }

    public function test_a_generated_document_can_be_edited_and_regenerated_in_place(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)->post(route('documents.generate.blank.store'), [
            'document_type' => 'FAT',
            'sender_entity' => 'Test Co',
            'receiver_entity' => 'Test Co',
            'signatory_menyerahkan' => 'Andi',
            'signatory_menerima' => 'Budi',
        ]);

        $document = Document::firstOrFail();
        $number = $document->document_number;
        $path = $document->file_path;

        $this->actingAs($support)->put(route('documents.update', $document), [
            'document_type' => 'FAT',
            'signatory_menyerahkan' => 'Andi Fixed',
            'signatory_menerima' => 'Budi Fixed',
        ])->assertRedirect(route('documents.preview', $document));

        $document->refresh();
        $this->assertSame(1, Document::count());
        $this->assertSame($number, $document->document_number);
        $this->assertSame($path, $document->file_path);
        $this->assertSame('Andi Fixed', $document->payload['parties']['sender']['name']);
        $this->assertSame('Budi Fixed', $document->payload['parties']['receiver']['name']);
        $this->assertStringStartsWith('%PDF', Storage::get($path));
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'UPDATE_DOCUMENT',
            'entity_identifier' => $number,
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
            'FAT/IT-'.now()->format('m').'-'.now()->format('Y').'/0002',
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
        $this->assertSame('BAST/IT-'.now()->format('m').'-'.now()->format('Y').'/0001', $document->document_number);
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
