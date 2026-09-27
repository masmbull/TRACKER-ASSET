<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\DocumentTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pakem FAT/BAST templates (seeded from the official Excel form
 * "Template Form Fixed Transfer Asset IT v1 (2026)") + generate-form wiring.
 */
class DocumentTemplateTest extends TestCase
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

    public function test_seeder_creates_fat_and_bast_pakem_templates(): void
    {
        User::factory()->create(['role' => 'admin']);

        $this->seed(DocumentTemplateSeeder::class);
        $this->seed(DocumentTemplateSeeder::class); // idempotent

        $this->assertSame(2, DocumentTemplate::count());

        $fat = DocumentTemplate::where('document_type', 'FAT')->firstOrFail();
        $bast = DocumentTemplate::where('document_type', 'BAST')->firstOrFail();

        // FAT mirrors sheets SERAH/TERIMA: sections A-F + checklist from config.
        $this->assertArrayHasKey('sender_name', $fat->template_content);
        $this->assertArrayHasKey('receiver_nik', $fat->template_content);
        $this->assertArrayHasKey('kelengkapan_laptop', $fat->template_content);
        $this->assertArrayHasKey('statement', $fat->template_content);
        $this->assertArrayHasKey('checklist_0', $fat->template_content);
        $this->assertGreaterThan(30, count($fat->template_content));
        $this->assertCount(count((array) config('documents.fat_checklist')), array_filter(
            $fat->template_content,
            static fn (array $field): bool => $field['type'] === 'pass_fail_na'
        ));

        // BAST mirrors sheet "Template BAST - BARU": sections A-F.
        $this->assertArrayHasKey('it_name', $bast->template_content);
        $this->assertArrayHasKey('asset_os', $bast->template_content);
        $this->assertArrayHasKey('handover_location', $bast->template_content);
        $this->assertArrayHasKey('signatory_it_date', $bast->template_content);
        $this->assertGreaterThan(30, count($bast->template_content));
    }

    public function test_templates_page_lists_pakem_templates(): void
    {
        User::factory()->create(['role' => 'admin']);
        $this->seed(DocumentTemplateSeeder::class);

        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)
            ->get(route('templates.index'))
            ->assertOk()
            ->assertSee('FAT Pakem')
            ->assertSee('BAST Pakem');
    }

    public function test_generate_form_offers_template_picker_and_document_keeps_selection(): void
    {
        User::factory()->create(['role' => 'admin']);
        $this->seed(DocumentTemplateSeeder::class);

        $support = User::factory()->create(['role' => 'support']);
        $template = DocumentTemplate::where('document_type', 'BAST')->firstOrFail();

        $this->actingAs($support)
            ->get(route('documents.generate', $this->asset))
            ->assertOk()
            ->assertSee('name="template_id"', false);

        $response = $this->actingAs($support)->post(route('documents.generate.store', $this->asset), [
            'document_type' => 'BAST',
            'template_id' => $template->id,
            'document_date' => '2026-09-26',
        ]);

        $document = Document::firstOrFail();

        $response->assertRedirect(route('documents.preview', $document));
        $this->assertSame($template->id, $document->template_id);
    }
}
