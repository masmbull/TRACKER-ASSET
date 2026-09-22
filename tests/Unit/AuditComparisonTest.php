<?php

namespace Tests\Unit;

use App\Models\Asset;
use App\Services\AuditComparisonService;
use PHPUnit\Framework\TestCase;

/**
 * Server-side audit comparison (PRD §28, §29, §46).
 */
class AuditComparisonTest extends TestCase
{
    private AuditComparisonService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AuditComparisonService;
    }

    private function asset(array $overrides = []): Asset
    {
        return new Asset(array_merge([
            'hostname' => 'PC-001',
            'serial_number' => 'SN001',
            'brand' => 'Dell',
            'model' => 'Latitude 5520',
            'processor' => 'i5-1135G7',
            'ram' => '8 GB',
            'storage' => '256GB NVMe',
            'os_version' => 'Windows 11 Pro',
            'mac_address' => 'AA:BB:CC:DD:EE:01',
            'static_ip' => '10.0.0.10',
        ], $overrides));
    }

    private function actual(array $overrides = []): array
    {
        return array_merge([
            'device_hostname' => 'PC-001',
            'device_serial' => 'SN001',
            'device_brand' => 'Dell',
            'device_model' => 'Latitude 5520',
            'device_processor' => 'i5-1135G7',
            'device_ram' => '8 GB',
            'device_storage' => '256GB NVMe',
            'device_os' => 'Windows 11 Pro',
            'device_mac' => 'AA:BB:CC:DD:EE:01',
            'device_ip' => '10.0.0.10',
        ], $overrides);
    }

    public function test_all_fields_match(): void
    {
        $comparison = $this->service->compare($this->asset(), $this->actual());

        $this->assertSame(AuditComparisonService::MATCH, $comparison['result']);
        $this->assertSame([], $comparison['mismatches']);
    }

    public function test_mismatch_is_reported_per_field(): void
    {
        $comparison = $this->service->compare($this->asset(), $this->actual([
            'device_ram' => '16 GB',
            'device_mac' => 'AA:BB:CC:DD:EE:02',
        ]));

        $this->assertSame(AuditComparisonService::MISMATCH, $comparison['result']);
        $this->assertSame(['registered' => '8 GB', 'actual' => '16 GB'], $comparison['mismatches']['ram']);
        $this->assertSame('AA:BB:CC:DD:EE:02', $comparison['mismatches']['mac_address']['actual']);
        $this->assertCount(2, $comparison['mismatches']);
    }

    public function test_mac_address_separator_and_case_tolerance(): void
    {
        $comparison = $this->service->compare($this->asset(), $this->actual([
            'device_mac' => 'aa-bb-cc-dd-ee-01',
        ]));

        $this->assertSame(AuditComparisonService::MATCH, $comparison['result']);
    }

    public function test_missing_actual_identity_is_incomplete(): void
    {
        $comparison = $this->service->compare($this->asset(), $this->actual(['device_serial' => '']));

        $this->assertSame(AuditComparisonService::INCOMPLETE, $comparison['result']);
    }

    public function test_empty_registered_value_is_not_a_mismatch(): void
    {
        $comparison = $this->service->compare(
            $this->asset(['ram' => null]),
            $this->actual(['device_ram' => '16 GB'])
        );

        $this->assertSame(AuditComparisonService::MATCH, $comparison['result']);
        $this->assertArrayNotHasKey('ram', $comparison['mismatches']);
    }

    public function test_uncollected_actual_fields_are_skipped(): void
    {
        $comparison = $this->service->compare($this->asset(), [
            'device_hostname' => 'PC-001',
            'device_serial' => 'SN001',
        ]);

        $this->assertSame(AuditComparisonService::MATCH, $comparison['result']);
    }
}
