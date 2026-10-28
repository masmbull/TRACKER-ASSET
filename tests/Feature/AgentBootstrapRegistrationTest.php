<?php

namespace Tests\Feature;

use App\Models\AgentApiToken;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentBootstrapRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AssetCategory $category;
    private Location $location;
    private AssetStatus $status;
    private User $holder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'MITO Company', 'code' => 'MSI']);
        $this->category = AssetCategory::create(['name' => 'Laptop']);
        $this->location = Location::create(['name' => 'Head Office', 'status' => 'active']);
        $this->status = AssetStatus::create(['name' => 'Active']);
        $this->holder = User::factory()->create(['name' => 'Laptop Holder']);
    }

    public function test_installer_lookup_endpoint_returns_all_ten_field_choices(): void
    {
        $this->getJson('/api/agent/register/options')
            ->assertOk()
            ->assertJsonPath('companies.0.code', 'MSI')
            ->assertJsonPath('categories.0.name', 'Laptop')
            ->assertJsonPath('locations.0.name', 'Head Office')
            ->assertJsonPath('statuses.0.name', 'Active')
            ->assertJsonPath('users.0.name', 'Laptop Holder');
    }

    public function test_unknown_device_registration_creates_asset_from_installer_fields(): void
    {
        $registration = $this->postJson('/api/agent/register', [
            'device_id' => 'first-install-hardware-id',
            'hardware_id' => 'first-install-hardware-id',
            'name' => 'MITO-LAPTOP-01',
            'platform' => 'Windows 11',
        ])->assertOk();
        $token = $registration->json('token');

        $response = $this->withToken($token)->postJson('/api/agent/bootstrap-asset', [
            'serial_number' => 'SERIAL-BOOTSTRAP-01',
            'hardware' => [
                'brand' => 'Dell', 'model' => 'Latitude', 'serial_number' => 'SERIAL-BOOTSTRAP-01',
                'hostname' => 'MITO-LAPTOP-01', 'processor' => 'Intel Core i7', 'storage' => '512 GB',
                'ram' => '16 GB', 'os_version' => 'Windows 11', 'mac_address' => '01:02:03:04:05:06',
                'static_ip' => '10.0.0.9',
            ],
            'audit_fields' => [
                'company_id' => $this->company->id,
                'category_id' => $this->category->id,
                'location_id' => $this->location->id,
                'asset_status_id' => $this->status->id,
                'current_user_id' => $this->holder->id,
                'admin_it_pic' => null,
                'purchase_year' => 2026,
                'vendor' => 'Dell',
                'purchase_price' => 1200,
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('asset_code', 'MSI-LAP-2026-0001');

        $asset = Asset::where('serial_number', 'SERIAL-BOOTSTRAP-01')->firstOrFail();
        $this->assertSame('MITO-LAPTOP-01', $asset->hostname);
        $this->assertSame('Dell', $asset->brand);
        $this->assertSame($this->holder->id, $asset->current_user_id);
        $this->assertSame(1, $asset->assignments()->count());
        $this->assertSame(1, AgentApiToken::count());
    }

    public function test_check_only_skips_fields_only_when_serial_is_in_the_asset_register(): void
    {
        Asset::create([
            'asset_id' => 'MSI-LAP-2026-0001',
            'company_id' => $this->company->id,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'asset_status_id' => $this->status->id,
            'brand' => 'Dell', 'model' => 'Latitude', 'serial_number' => 'KNOWN-SERIAL',
            'hostname' => 'KNOWN-LAPTOP', 'purchase_year' => 2026,
        ]);

        $this->postJson('/api/agent/register', [
            'device_id' => 'brand-new-agent-id',
            'hardware_id' => 'brand-new-agent-id',
            'serial_number' => 'KNOWN-SERIAL',
            'check_only' => true,
        ])->assertOk()
            ->assertJsonPath('known', true)
            ->assertJsonPath('skip_fields', true)
            ->assertJsonPath('asset_code', 'MSI-LAP-2026-0001');
    }

    public function test_existing_agent_without_an_asset_still_has_to_fill_the_asset_fields(): void
    {
        $this->postJson('/api/agent/register', [
            'device_id' => 'existing-but-unpromoted',
            'hardware_id' => 'existing-but-unpromoted',
        ])->assertOk();

        $this->postJson('/api/agent/register', [
            'device_id' => 'existing-but-unpromoted',
            'hardware_id' => 'existing-but-unpromoted',
            'serial_number' => 'SERIAL-NOT-IN-REGISTER',
            'check_only' => true,
        ])->assertOk()
            ->assertJsonPath('known', false)
            ->assertJsonPath('skip_fields', false);
    }

    public function test_bootstrap_asset_endpoint_requires_the_agent_token(): void
    {
        $this->postJson('/api/agent/bootstrap-asset', [])->assertUnauthorized();
    }
}
