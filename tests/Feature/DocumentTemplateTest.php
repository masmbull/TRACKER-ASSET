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
        $this->assertCount(count((array) config('documents.checklists.FAT')), array_filter(
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

    /**
     * The create form posts a JSON string, because that is all a textarea can send.
     *
     * The rule said `required|array`, which a textarea can never satisfy, so this
     * form could not save anything. It went unnoticed because nothing covered the
     * store path - the tests exercised the seeder and the generate picker, and the
     * layout showed no validation errors, so a rejected submission came back as a
     * pristine empty form.
     */
    public function test_a_template_can_be_created_from_the_form(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $response = $this->actingAs($support)->post(route('templates.store'), [
            'name' => 'FAT Form A',
            'document_type' => 'FAT',
            'template_content' => '{"asset_id": {"label": "Asset ID", "type": "text"}}',
        ]);

        $response->assertRedirect(route('templates.index'))->assertSessionHasNoErrors();

        $template = DocumentTemplate::where('name', 'FAT Form A')->firstOrFail();
        $this->assertSame('FAT', $template->document_type);
        $this->assertSame(
            ['label' => 'Asset ID', 'type' => 'text'],
            $template->template_content['asset_id']
        );
    }

    /**
     * Broken JSON has to say what is wrong with the JSON.
     *
     * "The template content must be an array" was both unhelpful and untrue from
     * the author's side: they did write a JSON object, and the mistake is always
     * one specific character. The draft also has to survive, since a hand-written
     * schema is the most painful thing on any form in the app to retype.
     */
    public function test_broken_json_is_rejected_with_a_readable_complaint_and_keeps_the_draft(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $draft = '{"asset_id": {"label": "Asset ID",},}';

        $this->actingAs($support)
            ->from(route('templates.create'))
            ->post(route('templates.store'), [
                'name' => 'FAT Form A',
                'document_type' => 'FAT',
                'template_content' => $draft,
            ])
            ->assertRedirect(route('templates.create'))
            ->assertSessionHasErrors('template_content');

        $this->assertSame(0, DocumentTemplate::count());

        $this->followingRedirects()
            ->actingAs($support)
            ->from(route('templates.create'))
            ->post(route('templates.store'), [
                'name' => 'FAT Form A',
                'document_type' => 'FAT',
                'template_content' => $draft,
            ])
            ->assertSee('not valid JSON')
            // The name, the type and the whole schema come back.
            ->assertSee('FAT Form A')
            ->assertSee('Asset ID');
    }

    /**
     * A caller that posts a real array is unaffected by the decoding above.
     */
    public function test_a_direct_array_submission_still_works(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)->post(route('templates.store'), [
            'name' => 'BAST Form B',
            'document_type' => 'BAST',
            'template_content' => ['it_name' => ['label' => 'Nama IT', 'type' => 'text']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            ['label' => 'Nama IT', 'type' => 'text'],
            DocumentTemplate::where('name', 'BAST Form B')->firstOrFail()->template_content['it_name']
        );
    }

    /**
     * There was no way to reach a document once its tab was closed.
     *
     * The Documents tab on the asset page answers "what does this one asset
     * have?". It cannot answer "show me every BAST issued this quarter" or "find
     * the invoice for that asset somebody else registered" - and since the only
     * route to a document was a direct link, a document that left the browser
     * became unreachable from the UI entirely.
     */
    public function test_documents_can_be_listed_across_every_asset(): void
    {
        $user = User::factory()->create(['role' => 'support']);
        $asset = Asset::factory()->create(['asset_id' => 'LAP-DOC']);

        $this->seed(DocumentTemplateSeeder::class);

        $this->actingAs($user)->post(route('documents.generate.store', $asset), [
            'document_type' => 'FAT',
            'document_date' => '2026-09-26',
        ])->assertRedirect();

        $document = Document::firstOrFail();

        $this->actingAs($user)->get(route('documents.index'))
            ->assertOk()
            ->assertSee($document->document_number)
            ->assertSee('FAT')
            ->assertSee('LAP-DOC');
    }

    public function test_the_document_list_narrows_by_type_and_searches_by_number(): void
    {
        $user = User::factory()->create(['role' => 'support']);
        $asset = Asset::factory()->create();

        foreach ([['FAT', '2026-09-26'], ['BAST', '2026-09-27']] as [$type, $date]) {
            Document::create([
                'asset_id' => $asset->id,
                'document_type' => $type,
                'document_number' => "X/{$type}/2026/00001",
                'title' => $type.' record',
                'file_path' => 'documents/test.pdf',
                'file_type' => 'pdf',
                'generated_date' => $date,
                'generated_by' => $user->id,
            ]);
        }

        $this->actingAs($user)->get(route('documents.index', ['type' => 'BAST']))
            ->assertOk()
            ->assertSee('X/BAST/2026/00001')
            ->assertDontSee('X/FAT/2026/00001');

        $this->actingAs($user)->get(route('documents.index', ['q' => 'X/FAT']))
            ->assertOk()
            ->assertSee('X/FAT/2026/00001')
            ->assertDontSee('X/BAST/2026/00001');
    }

    public function test_an_empty_document_list_distinguishes_no_filter_from_no_results(): void
    {
        $user = User::factory()->create(['role' => 'support']);

        // "No documents yet." - and a pointer at how to make one.
        $this->actingAs($user)->get(route('documents.index'))
            ->assertOk()
            ->assertSee('No documents yet.')
            ->assertSee('Documents tab');

        Document::create([
            'document_type' => 'FAT',
            'document_number' => 'FAT/IT/2026/00001',
            'title' => 'FAT record',
            'file_path' => 'documents/test.pdf',
            'file_type' => 'pdf',
            'generated_date' => '2026-09-26',
            'generated_by' => $user->id,
        ]);

        // A filter that matches nothing must not read as a broken page.
        $this->actingAs($user)->get(route('documents.index', ['q' => 'nothing-matches-this']))
            ->assertOk()
            ->assertSee('No documents match this filter.')
            ->assertDontSee('No documents yet.');
    }

    public function test_documents_require_authentication(): void
    {
        $this->get(route('documents.index'))->assertRedirect(route('login'));
    }

    public function test_template_show_page_lists_every_field_grouped_by_its_section(): void
    {
        User::factory()->create(['role' => 'admin']);
        $this->seed(DocumentTemplateSeeder::class);

        $template = DocumentTemplate::where('document_type', 'FAT')->firstOrFail();
        $fieldCount = count($template->template_content);
        $this->assertGreaterThan(0, $fieldCount);

        $response = $this->actingAs(User::first())->get(route('templates.show', $template));

        $response->assertOk();

        // Headline count, so the page cannot silently render an empty schema.
        $response->assertSee("{$fieldCount} fields");

        // Each field's label and raw key must be visible — that pairing is the
        // whole point of the page; the index page only ever showed a number.
        $response->assertSee($fieldCount.' fields ·');
        foreach (array_slice($template->template_content, 0, 10, true) as $key => $definition) {
            $response->assertSee($key);
            $response->assertSee($definition['label']);
        }

        // Sections come from the data, not a hardcoded map, so the paper-form
        // heading ("A. Informasi Yang Menyerahkan") must survive to the screen.
        $response->assertSee('Informasi Yang Menyerahkan');
    }

    public function test_template_show_page_requires_authentication(): void
    {
        // The seeder skips itself when no user exists, so a user must exist even
        // though this test deliberately never acts as them.
        User::factory()->create(['role' => 'admin']);
        $this->seed(DocumentTemplateSeeder::class);

        $template = DocumentTemplate::where('document_type', 'FAT')->firstOrFail();

        $this->get(route('templates.show', $template))->assertRedirect(route('login'));
    }
}
