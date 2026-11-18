<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use App\Services\AssetQrCode;
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
        $response->assertSee('MITO GROUP ASSETS');
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
        $response->assertSee('MITO GROUP ASSETS');
    }

    /**
     * The footer is the organisation mark and nothing else.
     *
     * No company (every label is MITO's) and no site name either: a location
     * printed on the sticker goes stale the first time a machine moves, while
     * the asset code above is what tells two identical laptops apart.
     */
    public function test_the_label_footer_is_the_organisation_mark_only(): void
    {
        $location = Location::create(['name' => 'IT Support', 'status' => 'active']);

        $asset = $this->asset(['location_id' => $location->id]);

        $this->actingAs($this->user)->get(route('assets.label', $asset))
            ->assertOk()
            ->assertSee('MITO GROUP ASSETS')
            ->assertDontSee('IT Support')
            ->assertDontSee('BPK Penabur');
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

    /**
     * The code on the sticker has to open the public page, not the app.
     *
     * A label is scanned by whoever is holding the machine - an auditor, a new
     * hire, a courier - and most of them have no account. The existing tests prove
     * the code is a well-formed SVG; this proves the thing that makes the sticker
     * worth printing: what it encodes resolves without a login, and it is the
     * unguessable token that identifies the asset rather than the numeric id.
     */
    public function test_the_label_qr_opens_the_public_scan_page_without_a_login(): void
    {
        $asset = $this->asset();
        $token = $asset->mintQrToken();

        $target = AssetQrCode::fromConfig()->targetUrl($asset);

        $this->assertStringContainsString('/a/'.$token, $target);
        $this->assertStringNotContainsString(
            '/assets/'.$asset->id,
            $target,
            'The code must not encode the authenticated detail URL; a scan would land on a sign-in page.'
        );

        $this->get($target)->assertOk()->assertSee($asset->asset_id);
    }

    /**
     * Printing a sheet is the act of publishing its codes.
     *
     * The sheet mints a token for every label on the page, because a code that
     * encodes /assets/{id} is dead to the phone that scans it. A label with no
     * token still has to render, which is exactly how a batch of unreachable
     * stickers used to be produced.
     */
    public function test_the_sheet_publishes_a_scan_target_for_every_label_it_prints(): void
    {
        $asset = $this->asset(['qr_token' => null]);

        $this->actingAs($this->user)->get(route('assets.qr.sheet'))->assertOk();

        $asset->refresh();

        $this->assertNotNull($asset->qr_token, 'Printing a sheet must mint a token for each label on it.');
        $this->assertStringContainsString('/a/'.$asset->qr_token, AssetQrCode::fromConfig()->targetUrl($asset));
    }

    /** A sheet printed from inside the dashboard would carry the sidebar onto the paper. */
    public function test_the_label_page_carries_no_dashboard_chrome(): void
    {
        $html = $this->actingAs($this->user)
            ->get(route('assets.label', $this->asset()))
            ->getContent();

        $this->assertStringNotContainsString('<aside', $html);
        $this->assertStringNotContainsString('data-sidebar-toggle', $html);
    }

    /**
     * Preview, print page and PDF all render one component, so the geometry they
     * share is the sticker's physical size. Asserted rather than assumed: sizes
     * are inline millimetres because DomPDF loads no Tailwind build, and a class
     * that happens to work in the browser collapses to an unstyled block there.
     */
    public function test_the_preview_and_the_print_page_share_the_label_geometry(): void
    {
        $first = $this->asset();
        $second = $this->asset();

        $geometry = sprintf(
            'width: %smm; height: %smm',
            (float) config('assets.label.width_mm'),
            (float) config('assets.label.height_mm')
        );

        $this->actingAs($this->user)->get(route('assets.label', $first))
            ->assertOk()
            ->assertSee($geometry, false);

        $this->actingAs($this->user)->get(route('assets.qr.print', ['ids' => [$first->id, $second->id]]))
            ->assertOk()
            ->assertSee($geometry, false);
    }

    public function test_the_label_pdf_is_named_after_the_asset(): void
    {
        $asset = $this->asset(['asset_id' => 'AST-PDF-0001']);

        $this->actingAs($this->user)->get(route('assets.label.pdf', $asset))
            ->assertOk()
            // DomPDF writes the filename unquoted, so the header is matched as it
            // emits it rather than as an HTTP client would quote it.
            ->assertHeader('Content-Disposition', 'attachment; filename=label-AST-PDF-0001.pdf');
    }

    /** A sticker outlives the register entry it was printed for. */
    public function test_the_sheet_only_labels_active_assets(): void
    {
        $active = $this->asset(['asset_id' => 'AST-ACTIVE-1']);
        $retired = $this->asset(['asset_id' => 'AST-RETIRED-2', 'is_active' => false]);

        $this->actingAs($this->user)->get(route('assets.qr.sheet'))
            ->assertOk()
            ->assertSee($active->asset_id)
            ->assertDontSee($retired->asset_id);
    }

    public function test_a_filter_that_matches_nothing_renders_an_empty_sheet(): void
    {
        $this->asset(['asset_id' => 'AST-NOMATCH-BASE']);

        $this->actingAs($this->user)->get(route('assets.qr.sheet', ['search' => 'nothing-like-this']))
            ->assertOk()
            ->assertSee('No assets match this filter.')
            ->assertDontSee('AST-NOMATCH-BASE');
    }

    /**
     * "Print selected" prints the ticked ids and only those. The count in the
     * toolbar is the one part of the page derived from the selection rather than
     * from the filters, so it is what proves the fallback did not kick in.
     */
    public function test_the_bulk_print_page_counts_only_the_ticked_labels(): void
    {
        $first = $this->asset(['asset_id' => 'AST-TICK-1']);
        $second = $this->asset(['asset_id' => 'AST-UNTICK-2']);

        $this->actingAs($this->user)->get(route('assets.qr.print', ['ids' => [$first->id]]))
            ->assertOk()
            ->assertSee('1 label')
            ->assertSee($first->asset_id)
            ->assertDontSee($second->asset_id)
            ->assertDontSee('2 labels');
    }

    /** The sheet PDF is named after the release, so a folder of them stays readable. */
    public function test_the_bulk_pdf_is_named_after_the_release(): void
    {
        $this->asset();

        $response = $this->actingAs($this->user)->get(route('assets.qr.pdf'));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // DomPDF writes the filename unquoted, so the assertion is on the prefix
        // rather than on a header it formats itself.
        $this->assertStringStartsWith(
            'attachment; filename=asset-labels-',
            (string) $response->headers->get('Content-Disposition')
        );
    }
}
