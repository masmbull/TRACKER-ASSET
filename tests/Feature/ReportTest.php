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
 * Reports page + CSV export (PRD §43, §46).
 */
class ReportTest extends TestCase
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

    public function test_support_can_view_report_with_breakdowns(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        Asset::factory()->count(3)->create(['purchase_price' => 1000000]);

        $response = $this->actingAs($support)->get(route('reports.index'));

        $response->assertOk();
        $response->assertViewHas('totalAssets', 3);
        $response->assertViewHas('totalValue', 3000000.0);
        $response->assertSee('Assets by Category');
        $response->assertSee('Laptop');
        $response->assertSee('Audit Coverage');
    }

    public function test_report_export_streams_csv(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        Asset::factory()->count(2)->create(['purchase_price' => 500000]);

        $response = $this->actingAs($support)->get(route('reports.export'));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $content = $response->streamedContent();
        $this->assertStringContainsString('BY CATEGORY', $content);
        $this->assertStringContainsString('Laptop', $content);
        $this->assertStringContainsString('TOTAL', $content);
        $this->assertStringContainsString('1000000', $content);
    }

    public function test_viewer_cannot_access_reports(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->get(route('reports.index'))->assertForbidden();
        $this->actingAs($viewer)->get(route('reports.export'))->assertForbidden();
    }
}
