<?php

namespace Tests\Feature;

use App\Models\AgentApiToken;
use App\Models\AgentTelemetry;
use App\Models\Asset;
use App\Models\AssetAudit;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Promoting a device from the fleet into the register (PRD Ã‚Â§11).
 *
 * A laptop whose serial is not on file used to become an asset on its first scan,
 * with company, category and location taken from whichever row was first in the
 * table. These tests pin the replacement: the scan waits, and a person decides.
 */
class AgentDevicePromotionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AgentApiToken $token;

    protected function setUp(): void
    {
        parent::setUp();

        Company::create(['name' => 'Mito Group', 'code' => 'MITO', 'address' => '']);
        AssetCategory::create(['name' => 'Laptop']);
        Location::create(['name' => 'Jakarta', 'status' => 'active']);
        AssetStatus::create(['name' => 'Active']);

        $this->admin = User::factory()->create(['role' => 'admin']);

        $this->token = AgentApiToken::create([
            'name' => 'MITO-0001',
            'token_hash' => hash('sha256', 'tat_'.Str::random(48)),
            'device_id' => 'MITO-0001',
            'is_active' => true,
        ]);
    }

    /**
     * A scan that found nothing on file, plus the telemetry that keeps the device
     * visible on the fleet page while it waits.
     */
    private function deviceHasScanned(?string $serial = 'SN-WAITING-01'): AssetAudit
    {
        AgentTelemetry::create([
            'agent_token_id' => $this->token->id,
            'hostname' => 'MITO-LAPTOP-7',
            'ip_address' => '10.0.0.7',
            'ram_config' => '16 GB',
            'reported_at' => now(),
        ]);

        return AssetAudit::create([
            'asset_id' => null,
            'agent_token_id' => $this->token->id,
            'audit_date' => now(),
            'auditor_id' => null,
            'device_hostname' => 'MITO-LAPTOP-7',
            'device_serial' => $serial,
            'device_brand' => 'Dell Inc.',
            'device_model' => 'Latitude 5440',
            'device_processor' => 'Intel Core i5-1345U',
            'device_ram' => '16 GB',
            'device_storage' => '512 GB NVMe',
            'device_os' => 'Windows 11 Pro',
            'device_mac' => 'AA:BB:CC:DD:EE:07',
            'device_ip' => '10.0.0.7',
            'result' => 'NEW DEVICE',
            'status' => 'submitted',
        ]);
    }

    private function promotionPayload(array $overrides = []): array
    {
        return array_merge([
            'asset_id' => 'LAPTOP-014',
            'company_id' => Company::first()->id,
            'category_id' => AssetCategory::first()->id,
            'location_id' => Location::first()->id,
            'asset_status_id' => AssetStatus::first()->id,
        ], $overrides);
    }

    public function test_promotion_creates_the_asset_and_claims_the_waiting_scan(): void
    {
        $scan = $this->deviceHasScanned();

        $response = $this->actingAs($this->admin)
            ->post(route('agent.promote.store', $this->token), $this->promotionPayload());

        $response->assertRedirect(route('assets.show', Asset::where('asset_id', 'LAPTOP-014')->firstOrFail()));

        $asset = Asset::where('asset_id', 'LAPTOP-014')->firstOrFail();

        // Hardware came from the scan. This is the whole value of the form: nobody
        // retypes a serial off a label.
        $this->assertSame('SN-WAITING-01', $asset->serial_number);
        $this->assertSame('Dell Inc.', $asset->brand);
        $this->assertSame('Latitude 5440', $asset->model);
        $this->assertSame('MITO-LAPTOP-7', $asset->hostname);
        $this->assertSame('10.0.0.7', $asset->static_ip, 'IP comes from telemetry, which the scan also carries.');

        // The scan and the telemetry must both point at it now. If either were
        // left behind, the fleet page would still show the device as unregistered
        // while the register claimed it - two truths, one machine.
        $this->assertSame($asset->id, $scan->fresh()->asset_id);
        $this->assertFalse($scan->fresh()->isUnmatched());
        $this->assertSame($asset->id, $this->token->telemetry()->firstOrFail()->asset_id);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'CREATE',
            'entity_type' => 'assets',
            'entity_identifier' => 'LAPTOP-014',
        ]);
    }

    /**
     * The fields a sensor cannot know must never be invented.
     *
     * The old auto-created rows took them from the first row in each table, which
     * is how a laptop ended up filed under a company nobody chose. Asserting null
     * rather than "not empty": a wrong value is the failure this is preventing.
     */
    public function test_unknown_fields_are_left_blank_not_guessed(): void
    {
        $this->deviceHasScanned('SN-BLANKS-01');

        $this->actingAs($this->admin)->post(
            route('agent.promote.store', $this->token),
            $this->promotionPayload()
        );

        $asset = Asset::where('asset_id', 'LAPTOP-014')->firstOrFail();

        foreach (['current_user_id', 'admin_it_pic', 'purchase_year', 'vendor', 'purchase_price'] as $field) {
            $this->assertNull($asset->{$field}, "{$field} must stay empty rather than be guessed.");
        }

        // "N/A" is the other way this used to go, and it is worse than blank: a
        // register full of N/A looks complete and cannot be told from real data.
        $this->assertNotSame('N/A', $asset->brand);
    }

    /**
     * Naming a holder must open the assignment history at that person.
     *
     * The FAT/BAST documents read the first party from this history, so an asset
     * registered with a holder but no assignment would generate a transfer form
     * with nobody to transfer from.
     */
    public function test_naming_a_holder_opens_the_assignment_history(): void
    {
        $holder = User::factory()->create(['role' => 'support', 'name' => 'Budi Santoso']);

        $this->deviceHasScanned('SN-HOLDER-01');

        $this->actingAs($this->admin)->post(
            route('agent.promote.store', $this->token),
            $this->promotionPayload(['current_user_id' => $holder->id])
        );

        $asset = Asset::where('asset_id', 'LAPTOP-014')->firstOrFail();

        $this->assertSame($holder->id, $asset->current_user_id);
        $this->assertDatabaseHas('asset_assignments', [
            'asset_id' => $asset->id,
            'user_id' => $holder->id,
        ]);
    }

    public function test_promotion_without_a_holder_creates_no_assignment(): void
    {
        $this->deviceHasScanned('SN-NO-HOLDER-01');

        $this->actingAs($this->admin)->post(
            route('agent.promote.store', $this->token),
            $this->promotionPayload()
        );

        $asset = Asset::where('asset_id', 'LAPTOP-014')->firstOrFail();

        // An asset in a cupboard has no first party yet. Inventing one would put
        // a name on a document that nobody agreed to.
        $this->assertDatabaseMissing('asset_assignments', ['asset_id' => $asset->id]);
    }

    /**
     * Only the newest waiting scan is claimed.
     *
     * A device that scanned twice while waiting has two rows; promoting it must
     * attach the one whose readings were shown on the form, not leave an orphan
     * pointing at a device that is now on the register.
     */
    public function test_only_the_newest_waiting_scan_is_claimed(): void
    {
        $this->deviceHasScanned('SN-FIRST-01');
        $this->deviceHasScanned('SN-SECOND-01');

        $this->actingAs($this->admin)->post(
            route('agent.promote.store', $this->token),
            $this->promotionPayload()
        );

        $newest = AssetAudit::where('device_serial', 'SN-SECOND-01')->firstOrFail();
        $oldest = AssetAudit::where('device_serial', 'SN-FIRST-01')->firstOrFail();

        $this->assertNotNull($newest->fresh()->asset_id);
        $this->assertNull($oldest->fresh()->asset_id, 'Only the scan shown on the form is claimed.');
    }

    public function test_the_form_is_shown_prefilled_with_the_scan(): void
    {
        $this->deviceHasScanned('SN-PREFILL-01');

        $response = $this->actingAs($this->admin)->get(route('agent.promote', $this->token));

        $response->assertOk();
        $response->assertSee('SN-PREFILL-01');
        $response->assertSee('Latitude 5440');
        $response->assertSee('MITO-LAPTOP-7');
        // And says why it is on this page at all.
        $response->assertSee('no asset was created');
    }

    /**
     * A rejected submission must keep what the operator typed.
     *
     * Without old() the page rebuilds from telemetry and the four dropdowns they
     * just chose are gone, so fixing one bad field costs redoing the rest.
     */
    public function test_invalid_submission_returns_with_typed_values_intact(): void
    {
        $this->deviceHasScanned('SN-KEEPT-01');

        $response = $this->actingAs($this->admin)->post(
            route('agent.promote.store', $this->token),
            $this->promotionPayload([
                'vendor' => 'PT Maju Mandiri',
                'purchase_year' => '2024',
                'category_id' => '',
            ])
        );

        $response->assertSessionHasErrors('category_id');
        $response->assertSessionHasInput('vendor', 'PT Maju Mandiri');
        $response->assertSessionHasInput('asset_id', 'LAPTOP-014');
        $response->assertSessionHasInput('purchase_year', '2024');

        $this->assertDatabaseMissing('assets', ['asset_id' => 'LAPTOP-014']);
    }

    public function test_asset_id_must_not_collide_with_an_existing_asset(): void
    {
        Asset::factory()->create(['asset_id' => 'LAPTOP-014']);

        $this->deviceHasScanned('SN-DUPE-01');

        $this->actingAs($this->admin)->post(
            route('agent.promote.store', $this->token),
            $this->promotionPayload()
        );

        // Two rows sharing one asset id would break every document that names it.
        $this->assertSame(1, Asset::where('asset_id', 'LAPTOP-014')->count());
        $this->assertDatabaseMissing('assets', ['serial_number' => 'SN-DUPE-01']);
    }

    /**
     * Promoting writes an asset, so it needs both permissions, not either.
     */
    public function test_promotion_requires_the_permission(): void
    {
        $this->deviceHasScanned('SN-GATE-01');

        $this->actingAs(User::factory()->create(['role' => 'support']))
            ->get(route('agent.promote', $this->token))
            ->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'viewer']))
            ->post(route('agent.promote.store', $this->token), $this->promotionPayload())
            ->assertForbidden();

        $this->assertDatabaseMissing('assets', ['asset_id' => 'LAPTOP-014']);
    }

    /**
     * Accepting a scan copies the readings onto the asset.
     *
     * The agent never writes to the register - it reports, and a mismatch stops at a
     * report. This is the second half, and it is what "match / no match" was missing:
     * without it, the fleet page could say a laptop disagrees with the register and
     * offer nothing but a manual edit.
     */
    public function test_applying_a_scan_updates_the_asset_from_the_mismatch(): void
    {
        $asset = Asset::factory()->create([
            'asset_id' => 'LAPTOP-500',
            'ram' => '8 GB',
            'storage' => '256 GB SSD',
        ]);

        $audit = $this->mismatchedAudit($asset, ['ram' => '32 GB', 'storage' => '1 TB NVMe']);

        $this->actingAs($this->admin)
            ->from(route('agent.fleet'))
            ->post(route('audit.apply-scan', $audit))
            ->assertRedirect(route('agent.fleet'))
            ->assertSessionHas('success');

        $asset->refresh();

        $this->assertSame('32 GB', $asset->ram);
        $this->assertSame('1 TB NVMe', $asset->storage);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'UPDATE',
            'entity_type' => 'assets',
            'entity_identifier' => 'LAPTOP-500',
        ]);
    }

    /**
     * A scan that found a difference against this asset.
     *
     * Built here because five tests need the same shape, and a shared builder is
     * the only way the five cannot drift apart in what they consider a mismatch.
     */
    private function mismatchedAudit(Asset $asset, array $actual): AssetAudit
    {
        $mismatches = [];
        foreach ($actual as $field => $value) {
            $mismatches[$field] = ['registered' => 'OLD', 'actual' => $value];
        }

        return AssetAudit::create([
            'asset_id' => $asset->id,
            'audit_date' => now(),
            'auditor_id' => null,
            'device_hostname' => $asset->hostname,
            'device_serial' => $asset->serial_number,
            'device_ram' => $actual['ram'] ?? $asset->ram,
            'device_storage' => $actual['storage'] ?? $asset->storage,
            'device_os' => $actual['os_version'] ?? null,
            'device_processor' => $actual['processor'] ?? null,
            'result' => 'MISMATCH',
            'mismatches' => $mismatches,
            'status' => 'submitted',
        ]);
    }

    /**
     * A sensor that could not answer must not erase a correct value.
     *
     * This is the failure mode that makes "apply the scan" dangerous: an audit
     * carries null for anything the agent could not read, and writing that null
     * would turn a failed WMI call into an empty column on the register.
     */
    public function test_applying_a_scan_never_erases_a_value_the_scan_did_not_report(): void
    {
        $asset = Asset::factory()->create([
            'asset_id' => 'LAPTOP-501',
            'processor' => 'Intel Core i5-1345U',
            'os_version' => 'Windows 11 Pro',
            'ram' => '16 GB',
        ]);

        // Only ram is reported. processor and os are absent, and must stay put.
        $audit = $this->mismatchedAudit($asset, ['ram' => '16 GB']);

        $this->actingAs($this->admin)->post(route('audit.apply-scan', $audit));

        $asset->refresh();

        $this->assertSame('Intel Core i5-1345U', $asset->processor, 'An unreported field must not be nulled.');
        $this->assertSame('Windows 11 Pro', $asset->os_version);
        $this->assertSame('16 GB', $asset->ram);
    }

    public function test_guests_cannot_reach_the_promotion_form(): void
    {
        $this->get(route('agent.promote', $this->token))->assertRedirect('/login');
        $this->post(route('agent.promote.store', $this->token), $this->promotionPayload())
            ->assertRedirect('/login');
    }

    /**
     * A blank register value is filled rather than treated as a conflict.
     */
    public function test_applying_a_scan_fills_a_blank_field(): void
    {
        $asset = Asset::factory()->create(['asset_id' => 'LAPTOP-502', 'mac_address' => null]);

        $audit = $this->mismatchedAudit($asset, []);
        $audit->update(['device_mac' => 'AA:BB:CC:DD:EE:99']);

        $this->actingAs($this->admin)->post(route('audit.apply-scan', $audit));

        $this->assertSame('AA:BB:CC:DD:EE:99', $asset->fresh()->mac_address);
    }

    /**
     * Applying the same scan twice must be harmless and must say so.
     *
     * The fleet page reloads after the POST, and an operator who clicks again on
     * a page that looks unchanged should get "nothing to change", not a second
     * write or an error.
     */
    public function test_applying_a_scan_twice_changes_nothing_the_second_time(): void
    {
        $asset = Asset::factory()->create(['asset_id' => 'LAPTOP-503', 'ram' => '8 GB']);

        $audit = $this->mismatchedAudit($asset, ['ram' => '32 GB']);

        $this->actingAs($this->admin)->post(route('audit.apply-scan', $audit));
        $this->assertSame('32 GB', $asset->fresh()->ram);

        $this->actingAs($this->admin)
            ->post(route('audit.apply-scan', $audit))
            ->assertSessionHas('success');

        $this->assertSame('32 GB', $asset->fresh()->ram);
        $this->assertStringContainsString('Nothing to change', (string) session('success'));
    }

    /**
     * A scan with no asset cannot be applied - the device has to be registered
     * first. Otherwise "apply" would create the asset, which is the thing the
     * promotion form is for.
     */
    public function test_applying_a_scan_of_an_unregistered_device_is_refused(): void
    {
        $scan = $this->deviceHasScanned('SN-NO-ASSET-YET');

        $this->actingAs($this->admin)
            ->post(route('audit.apply-scan', $scan))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('assets', ['serial_number' => 'SN-NO-ASSET-YET']);
    }

    /**
     * Technicians and viewers do not edit the register, so they get no route.
     */
    public function test_applying_a_scan_requires_update_permission(): void
    {
        $asset = Asset::factory()->create(['asset_id' => 'LAPTOP-504', 'ram' => '8 GB']);
        $audit = $this->mismatchedAudit($asset, ['ram' => '32 GB']);

        $this->actingAs(User::factory()->create(['role' => 'technician']))
            ->post(route('audit.apply-scan', $audit))
            ->assertForbidden();

        $this->assertSame('8 GB', $asset->fresh()->ram, 'A forbidden request must not write.');
    }

    /**
     * Support is allowed to edit assets, so the route has to reach them too.
     *
     * It sits outside the manage-agents group for exactly this reason: put back
     * inside it, support would lose a job their policy allows.
     */
    public function test_support_can_apply_a_scan(): void
    {
        $asset = Asset::factory()->create(['asset_id' => 'LAPTOP-505', 'ram' => '8 GB']);
        $audit = $this->mismatchedAudit($asset, ['ram' => '32 GB']);

        $this->actingAs(User::factory()->create(['role' => 'support']))
            ->post(route('audit.apply-scan', $audit))
            ->assertSessionHasNoErrors();

        $this->assertSame('32 GB', $asset->fresh()->ram);
    }
}
