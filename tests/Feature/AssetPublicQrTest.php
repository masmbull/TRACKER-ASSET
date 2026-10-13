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
 * The public scan target behind an asset QR (PRD §41).
 *
 * The whole point of the label is that it is scanned by someone standing at the
 * machine - an auditor, a courier, a new hire - who has no account here. The
 * properties that matter are therefore: the page is reachable without a login,
 * it is reachable only through an unguessable token (not the sequential id), it
 * shows the asset's detail, and it offers the document as a PDF.
 */
class AssetPublicQrTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Company::create(['name' => 'Test Co', 'code' => 'TC', 'address' => '']);
        AssetCategory::create(['name' => 'Laptop']);
        Location::create(['name' => 'Jakarta', 'status' => 'active']);
        AssetStatus::create(['name' => 'Active']);
    }

    private function manager(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Issuing a QR mints a token and the label now encodes the public URL.
     */
    public function test_generating_a_qr_mints_a_token_and_points_the_label_public(): void
    {
        $asset = Asset::factory()->create();
        $this->assertFalse($asset->hasQr(), 'A fresh asset should have no scan target until asked.');

        $this->actingAs($this->manager())
            ->post(route('assets.qr.generate', $asset))
            ->assertRedirect();

        $asset->refresh();

        $this->assertTrue($asset->hasQr());
        $this->assertNotNull($asset->qr_generated_at);
        $this->assertSame(
            rtrim((string) config('assets.qr.base_url'), '/').'/a/'.$asset->qr_token,
            AssetQrCode::fromConfig()->targetUrl($asset),
            'Once a label exists its code must open the public page, not the login-gated one.'
        );
    }

    /**
     * The page a scan opens requires no session.
     *
     * This is the regression: the QR used to encode /assets/{id}, which is behind
     * the auth middleware, so the one person the label is for - whoever is holding
     * the machine - hit a login wall.
     */
    public function test_a_scanned_page_opens_without_a_login(): void
    {
        $asset = Asset::factory()->create(['hostname' => 'lab-laptop-01']);
        $asset->mintQrToken();

        $response = $this->get(route('assets.public', $asset->qr_token));

        $response->assertOk();
        $response->assertSee($asset->asset_id);
        $response->assertSee('lab-laptop-01');
    }

    /**
     * The public page is resolved by token, never by the numeric id, so knowing
     * one label does not let someone enumerate the whole register.
     */
    public function test_the_public_page_cannot_be_reached_by_asset_id(): void
    {
        $asset = Asset::factory()->create();
        $asset->mintQrToken();

        $this->get('/a/'.$asset->id)->assertNotFound();
    }

    public function test_an_unknown_token_is_not_found(): void
    {
        $this->get('/a/not-a-real-token')->assertNotFound();
    }

    /** The scanned page offers the asset document as a PDF. */
    public function test_the_public_page_offers_a_downloadable_pdf(): void
    {
        $asset = Asset::factory()->create(['hostname' => 'lab-laptop-01']);
        $asset->mintQrToken();

        $response = $this->get(route('assets.public.pdf', $asset->qr_token));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_a_pdf_cannot_be_fetched_with_an_unknown_token(): void
    {
        $this->get('/a/not-a-real-token/pdf')->assertNotFound();
    }

    /**
     * Re-creating rotates the token, which is the entire point of offering it:
     * a printed label that has leaked must stop working.
     */
    public function test_re_creating_a_qr_rotates_the_token_and_retires_the_old_label(): void
    {
        $asset = Asset::factory()->create();
        $this->actingAs($this->manager())->post(route('assets.qr.generate', $asset));
        $old = $asset->refresh()->qr_token;

        $this->actingAs($this->manager())
            ->post(route('assets.qr.generate', $asset), ['regenerate' => 1]);

        $new = $asset->refresh()->qr_token;

        $this->assertNotSame($old, $new);
        $this->get(route('assets.public', $old))->assertNotFound();
        $this->get(route('assets.public', $new))->assertOk();
    }

    /** Creating when a label already exists must not silently rotate it. */
    public function test_a_plain_create_does_not_rotate_an_existing_token(): void
    {
        $asset = Asset::factory()->create();
        $this->actingAs($this->manager())->post(route('assets.qr.generate', $asset));
        $token = $asset->refresh()->qr_token;

        $this->actingAs($this->manager())->post(route('assets.qr.generate', $asset));

        $this->assertSame($token, $asset->refresh()->qr_token, 'A double submit must not invalidate a printed sticker.');
    }

    /** Issuing a label requires the ability to change the asset. */
    public function test_a_viewer_cannot_issue_a_qr(): void
    {
        $asset = Asset::factory()->create();
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->post(route('assets.qr.generate', $asset))->assertForbidden();
        $this->assertFalse($asset->refresh()->hasQr());
    }

    public function test_a_guest_cannot_issue_a_qr(): void
    {
        $asset = Asset::factory()->create();

        $this->post(route('assets.qr.generate', $asset))->assertRedirect('/login');
    }
}
