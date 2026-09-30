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
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The QR label (PRD §41).
 *
 * The logo and caption are the visible part, so they are asserted literally. The
 * parts worth guarding are the quiet ones: that a scan points somewhere reachable
 * and not at the machine that generated the label, and that the mark is small
 * enough to leave the code scannable.
 */
class AssetQrCodeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Company::create(['name' => 'Test Co', 'code' => 'TC', 'address' => '']);
        AssetCategory::create(['name' => 'Laptop']);
        Location::create(['name' => 'Jakarta', 'status' => 'active']);
        AssetStatus::create(['name' => 'Active']);

        $this->user = User::factory()->create([
            'role' => 'viewer',
            'password' => Hash::make('password'),
        ]);
    }

    private function labelFor(Asset $asset): string
    {
        return $this->actingAs($this->user)
            ->get("/assets/{$asset->id}/qr")
            ->getContent();
    }

    public function test_qr_endpoint_returns_svg(): void
    {
        $asset = Asset::factory()->create();

        $response = $this->actingAs($this->user)->get("/assets/{$asset->id}/qr");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/svg+xml');
    }

    /**
     * The regression this whole feature is built on: the raster back end needs
     * Imagick, which is not installed, so the old PNG route threw for every role.
     */
    public function test_qr_does_not_require_the_imagick_extension(): void
    {
        $asset = Asset::factory()->create();

        $this->assertFalse(extension_loaded('imagick'), 'Only meaningful while Imagick is absent.');

        $this->actingAs($this->user)->get("/assets/{$asset->id}/qr")->assertOk();
    }

    /**
     * A malformed document renders as a blank box in one browser and as a
     * download in another, so it is worth proving it parses.
     */
    public function test_qr_output_is_well_formed_xml(): void
    {
        $svg = $this->labelFor(Asset::factory()->create());

        $this->assertNotFalse(simplexml_load_string($svg));
    }

    public function test_qr_carries_the_mito_mark_and_caption(): void
    {
        $asset = Asset::factory()->create();

        $svg = $this->labelFor($asset);

        $this->assertStringContainsString('<image', $svg, 'The MITO mark should be embedded in the code.');
        $this->assertStringContainsString('MITO GROUP ASSETS', $svg, 'The caption belongs under the code.');
        $this->assertStringContainsString($asset->asset_id, $svg, 'A sticker that will not scan is still identifiable.');
    }

    /**
     * A logo in the middle of a QR code is an occluder: every module it covers is
     * a module a scanner cannot read. The mark is kept under a quarter of the
     * code and the error correction is raised to H to pay for what it covers.
     */
    public function test_logo_stays_small_enough_to_leave_the_code_scannable(): void
    {
        $document = simplexml_load_string($this->labelFor(Asset::factory()->create()));

        $boxes = $document->xpath('//*[local-name()="rect"]');

        // rect 0 is the label background, rect 1 the code's own background; the
        // plate under the mark is the next one.
        $plate = (int) $boxes[2]['width'];
        $code = (int) config('assets.qr.size');

        $this->assertLessThan(
            $code * 0.25,
            $plate,
            'The mark covers modules a scanner cannot read; guidance is under a quarter of the code.'
        );
        $this->assertSame('H', config('assets.qr.error_correction'), 'Only H recovers enough to afford a mark this size.');
    }

    public function test_the_mark_is_centred_on_the_code(): void
    {
        $document = simplexml_load_string($this->labelFor(Asset::factory()->create()));
        $image = $document->xpath('//*[local-name()="image"]')[0];

        $centre = (int) $image['x'] + ((int) $image['width'] / 2);

        $this->assertEqualsWithDelta(
            (int) config('assets.qr.size') / 2,
            $centre,
            1,
            'An off-centre mark reads as a printing fault rather than a design.'
        );
    }

    /**
     * The reason the target is built from config rather than from the request.
     */
    public function test_a_scan_points_at_the_configured_host_not_the_generating_request(): void
    {
        config(['assets.qr.base_url' => 'https://assets.mito.co.id']);

        $asset = Asset::factory()->create();

        $this->assertSame(
            "https://assets.mito.co.id/assets/{$asset->id}",
            AssetQrCode::fromConfig()->targetUrl($asset),
            'A label generated in development must not encode localhost.'
        );
    }

    /**
     * A blank ASSET_QR_BASE_URL= is the most natural typo there is, and it is
     * silent: no error, just labels nobody's phone can open. The fallback is
     * written as "?: not env(...)" precisely because env() only falls back when
     * a key is absent, so a present-but-empty value has to be covered here.
     */
    public function test_a_blank_base_url_falls_back_instead_of_producing_relative_labels(): void
    {
        $asset = Asset::factory()->create();

        $default = AssetQrCode::fromConfig()->targetUrl($asset);

        $this->assertStringStartsWith('http', $default, 'The target must be absolute out of the box.');

        // Re-evaluate the real config file with a present-but-empty variable,
        // which is what "ASSET_QR_BASE_URL=" in .env produces.
        $env = $_ENV;
        $_ENV['ASSET_QR_BASE_URL'] = '';
        $_SERVER['ASSET_QR_BASE_URL'] = '';

        try {
            $resolved = (require base_path('config/assets.php'));
        } finally {
            $_ENV = $env;
            unset($_SERVER['ASSET_QR_BASE_URL']);
        }

        $this->assertNotSame('', $resolved['qr']['base_url'], 'A blank value must not win and yield relative labels.');
        $this->assertStringStartsWith(
            'http',
            $resolved['qr']['base_url'],
            'The fallback has to be an absolute address a phone can reach.'
        );
    }

    /**
     * The reason the mark is inlined rather than referenced.
     *
     * An SVG loaded through an <img> element renders in secure static mode: the
     * browser will not fetch an external reference, so a logo referenced by URL
     * renders when the label is opened directly and is silently blank in the one
     * place it matters - the <img> on the label sheet. A test cannot open a
     * browser, but it can assert the property the restriction turns on: nothing
     * in the document points outside itself.
     */
    public function test_the_mark_survives_being_loaded_through_an_img_tag(): void
    {
        $svg = $this->labelFor(Asset::factory()->create());

        $this->assertStringContainsString(
            'data:image/png;base64,',
            $svg,
            'The mark has to be inlined; a URL reference is dropped when the SVG is used as an image.'
        );

        // No external reference of any kind: the only href allowed is the inline one.
        $document = simplexml_load_string($svg);
        $images = $document->xpath('//*[local-name()="image"]');

        $this->assertNotEmpty($images, 'The mark should be embedded in the code.');

        foreach ($images as $image) {
            $this->assertStringStartsWith('data:', (string) $image['href'], 'No external reference may remain.');
        }

        $this->assertStringNotContainsString('/images/mito-icon.png', $svg);
    }

    /** The mark must be real image data, not a truncated or empty encoding. */
    public function test_the_inlined_mark_is_a_decodable_png(): void
    {
        $svg = $this->labelFor(Asset::factory()->create());

        $this->assertSame(
            file_get_contents(public_path('images/mito-icon.png')),
            $this->decodedMark($svg),
            'The inlined bytes should be the logo file itself.'
        );
    }

    private function decodedMark(string $svg): ?string
    {
        $document = simplexml_load_string($svg);
        $image = $document->xpath('//*[local-name()="image"]')[0] ?? null;
        $href = $image === null ? '' : (string) $image['href'];

        if (! str_starts_with($href, 'data:')) {
            return null;
        }

        return base64_decode(substr($href, strpos($href, ',') + 1), true) ?: null;
    }

    /**
     * A missing mark should cost the logo, not the code. The plate is what buys
     * the error correction, so dropping it too would leave a scannability hole.
     */
    public function test_a_missing_mark_leaves_the_plate_rather_than_a_hole(): void
    {
        config(['assets.qr.logo' => '/images/not-a-real-file.png']);

        $document = simplexml_load_string($this->labelFor(Asset::factory()->create()));

        $this->assertEmpty(
            $document->xpath('//*[local-name()="image"]'),
            'A missing file should not emit a broken reference.'
        );

        $boxes = $document->xpath('//*[local-name()="rect"]');
        $this->assertGreaterThan(
            0,
            (int) $boxes[2]['width'],
            'The plate stays, so the code is still the size it was declared to be.'
        );
    }

    public function test_qr_sheet_lists_labels_for_existing_assets(): void
    {
        Asset::factory()->count(3)->create();

        $response = $this->actingAs($this->user)->get('/assets/qr');

        $response->assertOk();
        $response->assertSee('MITO GROUP ASSETS');
        $response->assertSee('/assets/'.Asset::first()->id.'/qr', false);
    }

    public function test_qr_sheet_can_be_filtered(): void
    {
        $wanted = Asset::factory()->create(['hostname' => 'lab-laptop-01']);
        Asset::factory()->create(['hostname' => 'other-machine']);

        $response = $this->actingAs($this->user)->get('/assets/qr?search=lab-laptop-01');

        $response->assertOk();
        $response->assertSee($wanted->asset_id);
        $response->assertDontSee('other-machine');
    }

    public function test_guest_cannot_reach_a_qr_label(): void
    {
        $asset = Asset::factory()->create();

        $this->get("/assets/{$asset->id}/qr")->assertRedirect('/login');
        $this->get('/assets/qr')->assertRedirect('/login');
    }
}
