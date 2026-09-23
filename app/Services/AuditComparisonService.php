<?php

namespace App\Services;

use App\Models\Asset;

/**
 * Compares registered asset data against actual device data (PRD §28, §29).
 *
 * The result is computed server-side; auditors never pick it manually.
 * Comparison is case-insensitive and tolerant of whitespace and of the usual
 * MAC address separators ("AA:BB:CC" == "AA-BB-CC" == "aabbcc").
 */
class AuditComparisonService
{
    public const MATCH = 'MATCH';

    public const MISMATCH = 'MISMATCH';

    public const NEW_DEVICE = 'NEW DEVICE';

    public const UNKNOWN_DEVICE = 'UNKNOWN DEVICE';

    public const INCOMPLETE = 'INCOMPLETE';

    /**
     * Fields that are compared: [registered attribute => audit input field].
     */
    public const COMPARED_FIELDS = [
        'hostname' => 'device_hostname',
        'serial_number' => 'device_serial',
        'brand' => 'device_brand',
        'model' => 'device_model',
        'processor' => 'device_processor',
        'ram' => 'device_ram',
        'storage' => 'device_storage',
        'os_version' => 'device_os',
        'mac_address' => 'device_mac',
        'static_ip' => 'device_ip',
    ];

    /** Identity fields that must be collected to call a device "audited". */
    private const REQUIRED_ACTUAL = ['device_hostname', 'device_serial'];

    /**
     * @param  array<string, string|null>  $actual
     * @param  list<string>|null  $required  Fields that must be collected to count
     *                                       as audited; defaults to identity fields.
     * @return array{result:string, mismatches:array<string, array{registered:?string, actual:?string}>}
     */
    public function compare(Asset $asset, array $actual, ?array $required = null): array
    {
        foreach ($required ?? self::REQUIRED_ACTUAL as $field) {
            if ($this->clean($actual[$field] ?? null) === null) {
                return ['result' => self::INCOMPLETE, 'mismatches' => []];
            }
        }

        $mismatches = [];
        $comparedAny = false;

        foreach (self::COMPARED_FIELDS as $attribute => $input) {
            $registered = $this->clean($asset->{$attribute} ?? null);
            $observed = $this->clean($actual[$input] ?? null);

            if ($observed === null) {
                // Not collected — never counts as a mismatch.
                continue;
            }

            $comparedAny = true;

            if ($registered === null) {
                // Register has no value — nothing to mismatch against.
                continue;
            }

            if (! $this->valuesMatch($registered, $observed)) {
                $mismatches[$attribute] = [
                    'registered' => $registered,
                    'actual' => $observed,
                ];
            }
        }

        if (! $comparedAny) {
            return ['result' => self::INCOMPLETE, 'mismatches' => []];
        }

        return [
            'result' => $mismatches === [] ? self::MATCH : self::MISMATCH,
            'mismatches' => $mismatches,
        ];
    }

    private function clean(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        if ($clean === '' || in_array(mb_strtolower($clean), ['n/a', 'na', '-', 'unknown'], true)) {
            return null;
        }

        return $clean;
    }

    private function valuesMatch(string $registered, string $observed): bool
    {
        if (mb_strtolower($registered) === mb_strtolower($observed)) {
            return true;
        }

        // MAC addresses: tolerate ":", "-", "." separators and case.
        $stripMac = fn (string $v) => preg_replace('/[^a-f0-9]/i', '', $v);
        $a = $stripMac($registered);
        $b = $stripMac($observed);

        if (strlen($a) === 12 && strlen($b) === 12) {
            return strtolower($a) === strtolower($b);
        }

        return false;
    }
}
