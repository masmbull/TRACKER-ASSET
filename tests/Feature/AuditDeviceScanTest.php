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
 * Browser device scan — web replacement of the legacy EXE Scan button (PRD §10/§11).
 */
class AuditDeviceScanTest extends TestCase
{
    use RefreshDatabase;

    private Asset $asset;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        Company::create(['name' => 'Test Co', 'code' => 'TC', 'address' => '']);
        AssetCategory::create(['name' => 'Laptop']);
        Location::create(['name' => 'Jakarta', 'status' => 'active']);
        AssetStatus::create(['name' => 'Active']);

        $this->asset = Asset::factory()->create([
            'ram' => '16 GB',
            'os_version' => 'Windows 11',
            'static_ip' => '10.0.0.5',
        ]);

        $this->technician = User::factory()->create(['role' => 'technician']);
    }

    public function test_device_scan_page_renders_with_collector(): void
    {
        $this->actingAs($this->technician)
            ->get(route('assets.audit.device', $this->asset))
            ->assertOk()
            ->assertSee('Scan Device')
            ->assertSee('browser_os');
    }

    public function test_browser_scan_submits_audit_with_comparison(): void
    {
        $response = $this->actingAs($this->technician)->post(route('assets.audit.device.submit', $this->asset), [
            'browser_os' => 'Windows 11',
            'browser_ram' => '8 GB',
            'browser_processor' => '8 threads',
            'browser_storage' => '512 GB (approx)',
            'browser_gpu' => 'NVIDIA GeForce RTX 3060',
            'manual_serial' => $this->asset->serial_number,
            'manual_hostname' => $this->asset->hostname,
            'notes' => 'On-site scan',
        ]);

        $response->assertRedirect(route('assets.audit', $this->asset));

        $audit = $this->asset->audits()->latest('id')->firstOrFail();

        // OS matches, RAM differs → MISMATCH with per-field detail.
        $this->assertSame('MISMATCH', $audit->result);
        $this->assertSame('8 GB', $audit->mismatches['ram']['actual']);
        $this->assertSame('16 GB', $audit->mismatches['ram']['registered']);
        // Identity from label matches.
        $this->assertArrayNotHasKey('serial_number', $audit->mismatches);
        $this->assertSame($this->technician->id, $audit->auditor_id);
        $this->assertStringContainsString('Browser scan', (string) $audit->notes);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'AUDIT',
            'entity_identifier' => $this->asset->asset_id,
        ]);
    }

    public function test_browser_scan_without_identity_is_still_valid(): void
    {
        // Browser sandbox cannot read serial/MAC — audit must not be INCOMPLETE.
        $this->actingAs($this->technician)->post(route('assets.audit.device.submit', $this->asset), [
            'browser_os' => 'Windows 11',
            'browser_ram' => '16 GB',
            'browser_processor' => '8 threads',
            'browser_gpu' => 'AMD Radeon',
        ])->assertRedirect(route('assets.audit', $this->asset));

        $audit = $this->asset->audits()->latest('id')->firstOrFail();

        $this->assertNotSame('INCOMPLETE', $audit->result);
        $this->assertSame('MISMATCH', $audit->result); // IP differs (127.0.0.1 vs 10.0.0.5)
        $this->assertArrayHasKey('static_ip', $audit->mismatches);
    }

    public function test_viewer_cannot_scan_device(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->get(route('assets.audit.device', $this->asset))->assertForbidden();
        $this->actingAs($viewer)->post(route('assets.audit.device.submit', $this->asset), [
            'browser_os' => 'Windows 11',
        ])->assertForbidden();
    }
}
