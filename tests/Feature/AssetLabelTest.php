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
 * The compact asset identification label (PRD §41).
 *
 * The label is a physical sticker, so the assertions are about what is printed
 * rather than how the page is styled: the register fields have to be the real
 * ones, the QR has to stay the same public target as the existing label, and
 * the PDF has to be a real document rather than a placeholder.
 */
class AssetLabelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private AssetCategory $laptop;

    private AssetCategory $monitor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'BPK Penabur', 'code' => 'BPK', 'address' => '']);
        $this->laptop = AssetCategory::create(['name' => 'Laptop']);
        $this->monitor = AssetCategory::create(['name' => 'Monitor']);
        Location::create(['name' => 'WMS', 'status' => 'active']);
        AssetStatus::create(['name' => 'Active']);

        $this->user = User::factory()->create(['role' => 'viewer']);
    }

    private function asset(array $attributes = []): Asset
    {
        return Asset::factory()->create(array_merge([
            'company_id' => $this->company->id,
            'category_id' => $this->laptop->id,
        ], $attributes));
    }

    public function test_individual_label_shows_the_register_fields(): void
    {
        $asset = $this->asset([
            'asset_id' => 'AST-2026-0001',
            'brand' => 'Lenovo',
            'model' => 'ThinkPad E14',
            'serial_number' => 'LPE14-0001',
        ]);

        $response = $this->actingAs($this->user)->get(route('assets.label', $asset));

        $response->assertOk();
        $response->assertSee('AST-2026-0001');
        $response->assertSee('Lenovo ThinkPad E14');
        $response->assertSee('SN: LPE14-0001');
        $response->assertSee('BPK Penabur · Laptop');
        $response->assertSee('asset-label', false);
    }

    public function test_the_label_page_offers_print_and_pdf_actions(): void
    {
        $asset = $this->asset();

        $response = $this->actingAs($this->user)->get(route('assets.label', $asset));

        $response->assertOk();
        $response->assertSee('Print label');
        $response->assertSee(route('assets.label.pdf', $asset), false);
    }

    public function test_the_individual_label_downloads_as_a_pdf(): void
    {
        $asset = $this->asset();

        $response = $this->actingAs($this->user)->get(route('assets.label.pdf', $asset));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_bulk_sheet_renders_compact_labels_with_selection(): void
    {
        $this->asset();

        $response = $this->actingAs($this->user)->get(route('assets.qr.sheet'));

        $response->assertOk();
        $response->assertSee('asset-label', false);
        $response->assertSee('Print selected');
        $response->assertSee('Download PDF');
        $response->assertSee('label-select', false);
        $response->assertSee('bulk-label-form', false);
    }

    public function test_the_bulk_sheet_filters_by_category_and_searches_the_model(): void
    {
        $laptop = $this->asset([
            'asset_id' => 'AST-LAPTOP-1',
            'model' => 'XPS-13-UNIQUE',
            'category_id' => $this->laptop->id,
        ]);

        $monitor = $this->asset([
            'asset_id' => 'AST-MONITOR-1',
            'model' => 'UltraSharp',
            'category_id' => $this->monitor->id,
        ]);

        $this->actingAs($this->user)
            ->get(route('assets.qr.sheet', ['category' => $this->monitor->id]))
            ->assertOk()
            ->assertSee($monitor->asset_id)
            ->assertDontSee($laptop->asset_id);

        $this->actingAs($this->user)
            ->get(route('assets.qr.sheet', ['search' => 'XPS-13-UNIQUE']))
            ->assertOk()
            ->assertSee($laptop->asset_id)
            ->assertDontSee($monitor->asset_id);
    }

    public function test_print_selected_renders_only_the_ticked_labels(): void
    {
        $first = $this->asset(['asset_id' => 'AST-FIRST-1']);
        $second = $this->asset(['asset_id' => 'AST-SECOND-2']);

        $response = $this->actingAs($this->user)
            ->get(route('assets.qr.print', ['ids' => [$first->id]]));

        $response->assertOk();
        $response->assertSee($first->asset_id);
        $response->assertDontSee($second->asset_id);
        $response->assertSee('asset-label', false);
    }

    public function test_printing_without_a_selection_falls_back_to_the_filters(): void
    {
        $laptop = $this->asset(['asset_id' => 'AST-LAPTOP-9', 'category_id' => $this->laptop->id]);
        $monitor = $this->asset(['asset_id' => 'AST-MONITOR-9', 'category_id' => $this->monitor->id]);

        $response = $this->actingAs($this->user)
            ->get(route('assets.qr.print', ['category' => $this->laptop->id]));

        $response->assertOk();
        $response->assertSee($laptop->asset_id);
        $response->assertDontSee($monitor->asset_id);
    }

    public function test_the_bulk_pdf_downloads_the_selected_labels(): void
    {
        $this->asset();

        $response = $this->actingAs($this->user)->get(route('assets.qr.pdf'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_long_names_and_special_characters_are_escaped(): void
    {
        $asset = $this->asset([
            'brand' => 'ACME <script>alert(1)</script>',
            'model' => 'Model & Co "Pro"',
            'serial_number' => "SN'<>&",
        ]);

        $response = $this->actingAs($this->user)->get(route('assets.label', $asset));

        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;', false);
        $response->assertSee('Model &amp; Co', false);
        $response->assertSee('SN&#039;&lt;&gt;&amp;', false);
    }

    public function test_missing_optional_information_falls_back_to_placeholders(): void
    {
        $asset = $this->asset([
            'brand' => '',
            'model' => '',
            'serial_number' => '',
        ]);

        $response = $this->actingAs($this->user)->get(route('assets.label', $asset));

        $response->assertOk();
        $response->assertSee('SN: —');
        $response->assertSee('BPK Penabur · Laptop');
    }

    /**
     * The compact label must not carry the logo plate: the plate covers modules
     * a scanner needs, and the label already prints the company in text.
     */
    public function test_the_plain_qr_option_omits_the_logo_overlay(): void
    {
        $asset = $this->asset();

        $response = $this->actingAs($this->user)->get(route('assets.qr', [$asset, 'plain' => 1]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/svg+xml');

        $svg = $response->getContent();
        $this->assertStringNotContainsString('<image', $svg);
        $this->assertStringNotContainsString('MITO GROUP ASSETS', $svg);
    }

    /** The existing square label keeps its mark and caption. */
    public function test_the_existing_qr_label_still_carries_its_logo_and_caption(): void
    {
        $asset = $this->asset();

        $response = $this->actingAs($this->user)->get(route('assets.qr', $asset));

        $response->assertOk();
        $response->assertSee('<image', false);
        $response->assertSee('MITO GROUP ASSETS');
    }

    public function test_a_guest_cannot_reach_an_individual_label(): void
    {
        $asset = $this->asset();

        $this->get(route('assets.label', $asset))->assertRedirect('/login');
        $this->get(route('assets.label.pdf', $asset))->assertRedirect('/login');
        $this->get(route('assets.qr.print'))->assertRedirect('/login');
        $this->get(route('assets.qr.pdf'))->assertRedirect('/login');
    }
}
