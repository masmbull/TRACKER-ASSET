<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Activity log page (PRD §40, §46) — immutable trail, filterable.
 */
class ActivityLogTest extends TestCase
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

    public function test_support_can_view_filtered_activity(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $asset = Asset::factory()->create();

        ActivityLog::create([
            'actor_id' => $support->id,
            'action' => 'CREATE',
            'entity_type' => 'assets',
            'entity_id' => $asset->id,
            'entity_identifier' => $asset->asset_id,
            'source' => 'Web UI',
        ]);

        ActivityLog::create([
            'actor_id' => $support->id,
            'action' => 'TRANSFER',
            'entity_type' => 'assets',
            'entity_id' => $asset->id,
            'entity_identifier' => $asset->asset_id,
            'source' => 'Transfer',
        ]);

        $page = $this->actingAs($support)->get(route('activity.index'));
        $page->assertOk()->assertSee('TRANSFER');

        $filtered = $this->actingAs($support)->get(route('activity.index', ['action' => 'TRANSFER']));
        $filtered->assertOk()->assertSee('TRANSFER');

        $search = $this->actingAs($support)->get(route('activity.index', ['search' => $asset->asset_id]));
        $search->assertOk();
    }

    public function test_viewer_cannot_view_activity(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->get(route('activity.index'))->assertForbidden();
    }

    public function test_request_context_is_captured_without_touching_each_call_site(): void
    {
        $request = Request::create('/assets', 'POST', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.42',
            'HTTP_HOST' => 'tracker.test',
            'HTTP_USER_AGENT' => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36\r\nInjected: header",
            'HTTP_REFERER' => 'http://tracker.test/assets/create',
            'HTTP_X_REQUEST_ID' => 'req-abc123',
        ]);
        $this->app->instance('request', $request);

        $log = ActivityLog::create([
            'actor_id' => User::factory()->create(['role' => 'support'])->id,
            'action' => 'CREATE',
            'entity_type' => 'assets',
            'source' => 'Web UI',
        ]);

        $this->assertSame('203.0.113.42', $log->ip_address);
        $this->assertSame('POST', $log->request_method);
        $this->assertSame('tracker.test', $log->server_host);
        $this->assertStringContainsString('tracker.test/assets', $log->request_url);
        $this->assertSame('http://tracker.test/assets/create', $log->referer);
        $this->assertSame('req-abc123', $log->request_id);
        $this->assertStringNotContainsString("\r", (string) $log->user_agent);
        $this->assertStringNotContainsString("\n", (string) $log->user_agent);
        $this->assertSame('Chrome', $log->browserName());
        $this->assertSame('Windows 10 / 11', $log->platformName());
        $this->assertSame('Desktop', $log->deviceType());
    }

    public function test_detail_shows_ip_request_context_changes_and_mismatches(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $asset = Asset::factory()->create();

        $log = ActivityLog::create([
            'actor_id' => $support->id,
            'action' => 'UPDATE',
            'entity_type' => 'assets',
            'entity_id' => $asset->id,
            'entity_identifier' => $asset->asset_id,
            'field_changed' => json_encode(['hostname', 'location_id']),
            'old_value' => json_encode(['hostname' => 'OLD-PC', 'location_id' => 1]),
            'new_value' => json_encode(['hostname' => 'NEW-PC', 'location_id' => 2]),
            'source' => 'Web UI',
            'ip_address' => '203.0.113.42',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36',
            'request_method' => 'PUT',
            'request_url' => 'http://tracker.test/assets/'.$asset->id,
            'request_id' => 'req-abc123',
            'metadata' => ['mismatches' => ['Serial Number' => ['registered' => 'AAA', 'actual' => 'BBB']]],
        ]);

        $page = $this->actingAs($support)->get(route('activity.index'));

        $page->assertOk()
            ->assertSee('203.0.113.42')
            ->assertSee('req-abc123')
            ->assertSee('Chrome')
            ->assertSee('Windows 10 / 11')
            ->assertSee('PUT')
            ->assertSee('OLD-PC')
            ->assertSee('NEW-PC')
            ->assertSee('hostname')
            ->assertSee('Mismatches');
    }
}
