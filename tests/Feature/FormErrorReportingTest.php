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
 * A rejected form must not look like a saved one.
 *
 * The layout rendered session flashes and nothing else, so a validation failure
 * came back as a page indistinguishable from success. The asset edit form went
 * further and read neither old() nor @error, which meant every field the user had
 * just filled in was replaced by the value already in the database. Filling in
 * twenty fields, tripping one rule and losing all twenty is the worst possible way
 * to be told about a typo.
 */
class FormErrorReportingTest extends TestCase
{
    use RefreshDatabase;

    private User $support;

    private Location $jakarta;

    private Location $bandung;

    protected function setUp(): void
    {
        parent::setUp();

        Company::create(['name' => 'Test Co', 'code' => 'TC', 'address' => '']);
        AssetCategory::create(['name' => 'Laptop']);
        $this->jakarta = Location::create(['name' => 'Jakarta', 'status' => 'active']);
        $this->bandung = Location::create(['name' => 'Bandung', 'status' => 'active']);
        AssetStatus::create(['name' => 'Active']);

        $this->support = User::factory()->create(['role' => 'support']);
    }

    /**
     * @return array<string, mixed>
     */
    private function assetPayload(array $overrides = []): array
    {
        return array_merge([
            'asset_id' => 'LAP-1',
            'brand' => 'Lenovo',
            'model' => 'X1',
            'serial_number' => 'SN-1',
            'hostname' => 'HOST-1',
            // Resolved rather than hardcoded to 1: see the note in AssetFactory.
            'company_id' => Company::firstOrFail()->id,
            'category_id' => AssetCategory::firstOrFail()->id,
            'location_id' => $this->jakarta->id,
            'asset_status_id' => AssetStatus::firstOrFail()->id,
        ], $overrides);
    }

    public function test_a_rejected_edit_keeps_what_was_typed_and_says_what_is_wrong(): void
    {
        $asset = Asset::factory()->create(['asset_id' => 'LAP-ORIGINAL', 'brand' => 'Lenovo']);
        Asset::factory()->create(['asset_id' => 'LAP-TAKEN']);

        // asset_id is taken, so the whole submission is rejected...
        $this->actingAs($this->support)
            ->from(route('assets.edit', $asset))
            ->put(route('assets.update', $asset), $this->assetPayload([
                'asset_id' => 'LAP-TAKEN',
                'brand' => 'HP',
                'model' => 'ProDesk',
                'hostname' => 'HOST-NEW',
                'location_id' => $this->bandung->id,
            ]))
            ->assertRedirect(route('assets.edit', $asset))
            ->assertSessionHasErrors('asset_id');

        // ...and what comes back has to carry the user's work and the reason.
        $this->followingRedirects()
            ->actingAs($this->support)
            ->from(route('assets.edit', $asset))
            ->put(route('assets.update', $asset), $this->assetPayload([
                'asset_id' => 'LAP-TAKEN',
                'brand' => 'HP',
                'model' => 'ProDesk',
                'hostname' => 'HOST-NEW',
                'location_id' => $this->bandung->id,
            ]))
            ->assertOk()
            ->assertSee('ProDesk')
            ->assertSee('HOST-NEW')
            ->assertSee('Bandung')
            ->assertSee('already been taken');

        // And nothing was written: a rejected form must not half-apply.
        $this->assertSame('Lenovo', $asset->fresh()->brand);
    }

    public function test_a_rejected_transfer_keeps_the_origin_the_user_chose(): void
    {
        $asset = Asset::factory()->create(['location_id' => $this->jakarta->id]);
        $target = User::factory()->create(['role' => 'viewer']);

        // to_location_id is missing, so the transfer is rejected...
        $this->actingAs($this->support)
            ->from(route('assets.transfer', $asset))
            ->post(route('assets.transfer.submit', $asset), [
                'transfer_date' => now()->toDateString(),
                'from_location_id' => $this->bandung->id,
                'to_user_id' => $target->id,
            ])
            ->assertSessionHasErrors('to_location_id');

        // ...and the origin the user picked must not quietly snap back to where
        // the asset happens to be. Two of the three selects on this form already
        // read old(); this one did not, so a rejected transfer would report a
        // journey from a place nobody chose.
        $this->followingRedirects()
            ->actingAs($this->support)
            ->from(route('assets.transfer', $asset))
            ->post(route('assets.transfer.submit', $asset), [
                'transfer_date' => now()->toDateString(),
                'from_location_id' => $this->bandung->id,
                'to_user_id' => $target->id,
            ])
            ->assertOk()
            ->assertSee('Bandung');
    }

    /**
     * The message has to be next to the control, not only in the layout banner.
     *
     * The two tests above assert `assertSessionHasErrors` and `old()`, and both
     * passed while no form rendered a single @error. The layout does print
     * `$errors->all()` in one block above the content, so a rejected submit was
     * never silent - but a person reading "The cost field must be a number"
     * still had to hunt for which Cost box that was. The banner says what is
     * wrong; the per-field message says where, and this asserts that.
     *
     * Matching the inline message by its own markup matters: asserting the bare
     * rule text would pass against the layout banner alone, which is precisely
     * the case this test exists to distinguish.
     */
    public function test_a_rejected_repair_points_at_the_field_that_failed(): void
    {
        $asset = Asset::factory()->create();

        $response = $this->followingRedirects()
            ->actingAs($this->support)
            ->from(route('assets.repair.create', $asset))
            ->post(route('assets.repair.store', $asset), [
                'date' => now()->toDateString(),
                'problem' => 'Screen flickers',
                'cost' => 'not-a-number', // rejected by the numeric rule
            ])
            ->assertOk();

        // The cost input itself must be marked red, and its error message
        // rendered next to it - not merely in the layout banner above.
        // Asserting the literal `@error(...)` directive is wrong: it is compiled
        // away, so the source never appears in the HTML. What matters is the
        // rendered result - a red border on the control and the message beside
        // it. The message alone would pass against the banner, so it is paired
        // with the border class on the control, which the banner cannot produce.
        $html = $response->assertSee('name="cost"', false)
            ->assertSee('The cost field must be a number')
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/name="cost"[^>]*border-red-400/',
            $html,
            'the cost input should be highlighted when its value is rejected'
        );
    }

    /**
     * Same for the edit form, whose asset_id is the field a user is most likely
     * to collide with: every asset carries one, so "already taken" there is a
     * routine mistake with no obvious cause.
     */
    public function test_a_rejected_edit_points_at_the_field_that_failed(): void
    {
        $asset = Asset::factory()->create(['asset_id' => 'LAP-ORIGINAL']);
        Asset::factory()->create(['asset_id' => 'LAP-TAKEN']);

        $response = $this->followingRedirects()
            ->actingAs($this->support)
            ->from(route('assets.edit', $asset))
            ->put(route('assets.update', $asset), $this->assetPayload([
                'asset_id' => 'LAP-TAKEN',
                'brand' => 'HP',
            ]))
            ->assertOk()
            ->assertSee('already been taken');

        // The taken asset_id - not some other field - carries the highlight.
        $this->assertMatchesRegularExpression(
            '/name="asset_id"[^>]*border-error-400/',
            $response->getContent(),
            'the asset_id input should be highlighted when the value is taken'
        );
    }

    /**
     * The transfer form marks all four fields submitTransfer requires.
     *
     * Four selects on one page, three of them looking alike, and a banner that
     * only says which field name failed. "The to user field is required" tells
     * a person nothing about which of To Location and To User was left blank.
     */
    public function test_a_rejected_transfer_marks_the_field_that_failed(): void
    {
        $asset = Asset::factory()->create(['location_id' => $this->jakarta->id]);
        $target = User::factory()->create(['role' => 'viewer']);

        $html = $this->followingRedirects()
            ->actingAs($this->support)
            ->from(route('assets.transfer', $asset))
            ->post(route('assets.transfer.submit', $asset), [
                'transfer_date' => now()->toDateString(),
                'from_location_id' => $this->bandung->id,
                'to_user_id' => $target->id,
                // to_location_id omitted -> rejected
            ])
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/name="to_location_id"[^>]*border-error-400/',
            $html,
            'the empty destination location should be the one highlighted'
        );

        // And its siblings stay unmarked: a red border on To User would point
        // the reader at the field that was filled in correctly.
        $this->assertDoesNotMatchRegularExpression(
            '/name="to_user_id"[^>]*border-error-400/',
            $html,
            'only the field that actually failed should be highlighted'
        );
    }

    /**
     * The JSON field on the template form, and the message the controller went
     * out of its way to write.
     *
     * TemplateController::jsonComplaint names the offending character, which is
     * the single most useful sentence this form can produce - and it was being
     * aimed at the layout banner while the one textarea it refers to showed no
     * error of its own. A user pasting a template schema and getting a stray
     * comma had no way to find out which one.
     */
    public function test_a_rejected_template_points_at_the_json_field(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $response = $this->followingRedirects()
            ->actingAs($support)
            ->from(route('templates.create'))
            ->post(route('templates.store'), [
                'name' => 'FAT Copy',
                'document_type' => 'FAT',
                'template_content' => '{"asset_id": {"label": "Asset ID",},}', // trailing comma
            ])
            ->assertOk();

        $this->assertMatchesRegularExpression(
            '/name="template_content"[^>]*border-error-400/',
            $response->getContent(),
            'the JSON textarea should be highlighted when its content will not parse'
        );

        // The typed JSON must still be there - the point of hand-writing a
        // schema is that it is worth keeping while the comma is fixed.
        // Escaped, because Blade's {{ }} turns every quote into &quot; before
        // it reaches the HTML; searching for a raw " would find nothing.
        $response->assertSee('&quot;asset_id&quot;', false)
            ->assertSee('&quot;Asset ID&quot;', false);
    }

    /**
     * The browser-collected audit dropped what the user typed.
     *
     * manual_serial, manual_hostname and manual_mac read no old() while the
     * Notes textarea below them did, so a rejected submit cleared three fields
     * and kept the fourth - a form that looked partly filled, with no way to
     * tell which half was real. All three are capped at 255 server-side, so a
     * long value pasted off a label really can be rejected.
     */
    public function test_a_rejected_device_audit_keeps_the_values_typed_from_the_label(): void
    {
        $asset = Asset::factory()->create();
        $technician = User::factory()->create(['role' => 'technician']);

        $longSerial = str_repeat('A', 256); // one character past the max:255 rule

        $html = $this->followingRedirects()
            ->actingAs($technician)
            ->from(route('assets.audit.device', $asset))
            ->post(route('assets.audit.device.submit', $asset), [
                'manual_serial' => $longSerial,
                'manual_hostname' => 'MITO-LAPTOP-14',
                'manual_mac' => 'AA:BB:CC:DD:EE:FF',
                'notes' => 'Taken from the label under the battery.',
            ])
            ->assertOk()
            ->getContent();

        // The serial is what failed, so it is the one marked...
        $this->assertMatchesRegularExpression(
            '/name="manual_serial"[^>]*border-red-400/',
            $html,
            'the over-length serial should be highlighted'
        );

        // ...and the fields that were fine keep their contents, so the user
        // only has to shorten one box rather than retype the form.
        $this->assertStringContainsString('MITO-LAPTOP-14', $html);
        $this->assertStringContainsString('AA:BB:CC:DD:EE:FF', $html);
        $this->assertStringContainsString('Taken from the label under the battery.', $html);
    }
}
