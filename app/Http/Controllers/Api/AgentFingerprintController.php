<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentApiToken;
use App\Models\AgentFingerprint;
use App\Models\AgentSoftware;
use App\Models\AgentTelemetry;
use App\Models\Asset;
use Illuminate\Http\Request;

/**
 * Receiver for the agent's deep-inventory report (software, security posture,
 * licensing, storage health).
 *
 * Kept separate from AuditApiController::heartbeat because the two have very
 * different costs and cadences: the heartbeat runs every 60 seconds with cheap
 * readings, this runs once at install and then daily. Sending the software list
 * on the heartbeat would mean a registry walk per device per minute.
 */
class AgentFingerprintController extends Controller
{
    /**
     * Guard against an agent bug turning into an unbounded insert. A normal
     * Windows machine tops out near 400 entries; the cap leaves headroom for
     * heavily-loaded dev boxes without letting a single report blow up the
     * table.
     */
    private const MAX_SOFTWARE_ROWS = 2000;

    /**
     * Fields the agent sends as "true"/"false" strings.
     */
    private const BOOLEAN_FIELDS = [
        'antivirus_enabled', 'firewall_enabled', 'uac_enabled', 'secure_boot_enabled',
        'tpm_present', 'rdp_enabled', 'pending_reboot', 'smart_predicted_failure',
        'auto_logon_enabled', 'screen_lock_enabled', 'guest_account_enabled',
        'defender_realtime_enabled',
    ];

    /**
     * Convert the agent's stringly-typed booleans before validation.
     *
     * Laravel's `boolean` rule only accepts real booleans, 0/1 and "0"/"1", so a
     * payload of "true" would be rejected with 422 â€” taking the whole report
     * down with it, including several hundred software rows that were fine.
     *
     * An unrecognised value becomes null rather than an error, deliberately: a
     * single unreadable sensor must not destroy the rest of the inventory, and
     * null is distinguishable from a genuine "false" later on. This mirrors what
     * AuditApiController::normaliseTelemetryInput already does for telemetry.
     */
    private function normaliseBooleanInput(Request $request): void
    {
        $clean = [];

        foreach (self::BOOLEAN_FIELDS as $field) {
            if (! $request->has($field)) {
                continue;
            }

            $value = $request->input($field);

            if (is_bool($value)) {
                continue;
            }

            if (is_string($value)) {
                $value = strtolower(trim($value));

                $clean[$field] = match ($value) {
                    '1', 'true', 'yes', 'on' => true,
                    '0', 'false', 'no', 'off' => false,
                    default => null,
                };
            }
        }

        if ($clean !== []) {
            $request->merge($clean);
        }
    }

    public function store(Request $request)
    {
        $this->normaliseBooleanInput($request);

        $payload = $request->validate($this->rules());

        $token = AgentApiToken::find($request->attributes->get('agent_token_id'));

        if (! $token) {
            return response()->json(['ok' => false, 'message' => 'Unknown agent token.'], 422);
        }

        $fingerprint = AgentFingerprint::updateOrCreate(
            ['agent_token_id' => $token->id],
            [
                'asset_id' => $this->resolveAssetId($token, $payload),
                'device_id' => $payload['device_id'] ?? $token->device_id,
                ...$this->attributes($payload),
                'payload' => $payload,
                'collected_at' => now(),
            ]
        );

        $software = $this->replaceSoftware($fingerprint, $payload['software'] ?? []);

        return response()->json([
            'ok' => true,
            'software' => $software,
            'collected_at' => $fingerprint->collected_at?->toIso8601String(),
        ]);
    }

    /**
     * Replace the software list wholesale.
     *
     * A diff would be cheaper but needs a stable per-entry key the registry does
     * not provide, and a stale entry is worse than a re-scan: uninstalling a
     * program and keeping its row would make the fleet report software that is
     * no longer on the machine.
     */
    private function replaceSoftware(AgentFingerprint $fingerprint, array $software): int
    {
        $fingerprint->software()->delete();

        $rows = [];

        foreach (array_slice($software, 0, self::MAX_SOFTWARE_ROWS) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $name = trim((string) ($entry['name'] ?? ''));

            // Registry keys exist with an empty DisplayName (broken installs and
            // some update stubs), which would otherwise become nameless rows.
            if ($name === '') {
                continue;
            }

            $rows[] = [
                'agent_fingerprint_id' => $fingerprint->id,
                'asset_id' => $fingerprint->asset_id,
                'name' => $name,
                'version' => $this->clean($entry['version'] ?? null),
                'publisher' => $this->clean($entry['publisher'] ?? null),
                'installed_at' => $this->dateOrNull($entry['installed_at'] ?? null),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Chunked so a large inventory cannot blow past the placeholder limit on
        // drivers that cap a single multi-row statement.
        foreach (array_chunk($rows, 200) as $chunk) {
            AgentSoftware::insert($chunk);
        }

        return count($rows);
    }

    private function attributes(array $payload): array
    {
        return [
            'antivirus_product' => $this->clean($payload['antivirus_product'] ?? null),
            'antivirus_enabled' => $this->boolOrNull($payload['antivirus_enabled'] ?? null),
            'antivirus_signature_age_days' => $this->intOrNull($payload['antivirus_signature_age_days'] ?? null),
            'firewall_enabled' => $this->boolOrNull($payload['firewall_enabled'] ?? null),
            'firewall_profiles_off' => $this->clean($payload['firewall_profiles_off'] ?? null),
            'uac_enabled' => $this->boolOrNull($payload['uac_enabled'] ?? null),
            'secure_boot_enabled' => $this->boolOrNull($payload['secure_boot_enabled'] ?? null),
            'tpm_present' => $this->boolOrNull($payload['tpm_present'] ?? null),
            'bitlocker_status' => $this->clean($payload['bitlocker_status'] ?? null),
            'bitlocker_method' => $this->clean($payload['bitlocker_method'] ?? null),
            'rdp_enabled' => $this->boolOrNull($payload['rdp_enabled'] ?? null),
            'local_admin_count' => $this->intOrNull($payload['local_admin_count'] ?? null),
            'windows_edition' => $this->clean($payload['windows_edition'] ?? null),
            'license_channel' => $this->clean($payload['license_channel'] ?? null),
            'activation_status' => $this->clean($payload['activation_status'] ?? null),
            'os_build' => $this->clean($payload['os_build'] ?? null),
            'os_installed_at' => $this->dateOrNull($payload['os_installed_at'] ?? null),
            'last_boot_at' => $this->dateOrNull($payload['last_boot_at'] ?? null),
            'pending_reboot' => $this->boolOrNull($payload['pending_reboot'] ?? null),
            'domain' => $this->clean($payload['domain'] ?? null),
            'timezone' => $this->clean($payload['timezone'] ?? null),
            'disk_type' => $this->clean($payload['disk_type'] ?? null),
            'smart_health' => $this->clean($payload['smart_health'] ?? null),
            'smart_predicted_failure' => $this->boolOrNull($payload['smart_predicted_failure'] ?? null),
            'cpu_cores' => $this->intOrNull($payload['cpu_cores'] ?? null),
            'cpu_physical_cores' => $this->intOrNull($payload['cpu_physical_cores'] ?? null),
            // Zero means "the firmware did not tell us", not "this machine has no
            // memory". An agent old enough to send the field without a real count
            // would otherwise store 0 and the page would show a board with no
            // slots at all, which reads as a hardware fault rather than a gap in
            // the data. Defensive on the server too, since the agent already
            // omits the key when its reading is 0.
            'ram_slots' => $this->positiveIntOrNull($payload['ram_slots'] ?? null),
            'ram_modules' => $this->positiveIntOrNull($payload['ram_modules'] ?? null),
            'monitor' => $this->clean($payload['monitor'] ?? null),
            'bios_version' => $this->clean($payload['bios_version'] ?? null),
            'bios_date' => $this->dateOrNull($payload['bios_date'] ?? null),
            'chassis_type' => $this->clean($payload['chassis_type'] ?? null),
            'device_model' => $this->clean($payload['device_model'] ?? null),
            'screen_resolution' => $this->clean($payload['screen_resolution'] ?? null),
            'cpu_architecture' => $this->clean($payload['cpu_architecture'] ?? null),

            'auto_logon_enabled' => $this->boolOrNull($payload['auto_logon_enabled'] ?? null),
            'screen_lock_enabled' => $this->boolOrNull($payload['screen_lock_enabled'] ?? null),
            // 0 is deliberately kept: it means "locks immediately", which is the
            // strongest possible setting. A positive-int helper here would
            // silently turn the best-configured machine in the fleet into a
            // blank, which is the exact inversion of what it should do.
            'lock_timeout_minutes' => $this->boundedIntOrNull($payload['lock_timeout_minutes'] ?? null, 1440),
            'guest_account_enabled' => $this->boolOrNull($payload['guest_account_enabled'] ?? null),

            'defender_realtime_enabled' => $this->boolOrNull($payload['defender_realtime_enabled'] ?? null),
            'defender_last_scan_days' => $this->boundedIntOrNull($payload['defender_last_scan_days'] ?? null, 3650),
            'hotfix_count' => $this->boundedIntOrNull($payload['hotfix_count'] ?? null, 10000),
            'last_hotfix_installed_at' => $this->dateOrNull($payload['last_hotfix_installed_at'] ?? null),

            // Windows Update. A pending count of 0 is meaningful and kept; a
            // count above a few hundred is a machine that has been offline for
            // months, and storing it as-is would produce a number no operator
            // can act on. The ceiling is only there to keep the column honest.
            'windows_updates_pending' => $this->boundedIntOrNull($payload['windows_updates_pending'] ?? null, 500),
            'security_updates_pending' => $this->boundedIntOrNull($payload['security_updates_pending'] ?? null, 500),
            'updates_succeeded' => $this->boundedIntOrNull($payload['updates_succeeded'] ?? null, 10000),
            'updates_failed' => $this->boundedIntOrNull($payload['updates_failed'] ?? null, 10000),
            'security_updates_installed' => $this->boundedIntOrNull($payload['security_updates_installed'] ?? null, 10000),
            'last_security_update_at' => $this->dateOrNull($payload['last_security_update_at'] ?? null),
            'software_count' => $this->intOrNull($payload['software_count'] ?? null),
            'startup_count' => $this->intOrNull($payload['startup_count'] ?? null),
        ];
    }

    /**
     * Attach the report to an asset.
     * </summary>
     *
     * <remarks>
     * The column names are the ones AuditApiController::resolveAsset already
     * uses: assets.serial_number / hostname / mac_address. There is no
     * device_ prefix on this table.
     *
     * An earlier version of this method looked for device_serial and
     * device_hostname, which do not exist. SQLite quietly returned nothing for
     * the unknown column, so the broken branch hid behind the telemetry
     * fallback and the feature tests still passed, while on MySQL the same
     * query would have thrown a 500 on every single fingerprint report.
     * FingerprintTest asserts the column list for exactly that reason.
     *
     * The telemetry fallback is kept as a safety net for a device whose asset
     * has since been renamed, but it is no longer the main path.
     * </remarks>
     */
    private function resolveAssetId(AgentApiToken $token, array $payload): ?int
    {
        $columns = [
            'asset_id' => 'asset_id',
            'serial_number' => 'serial_number',
            'hostname' => 'hostname',
            'mac_address' => 'mac_address',
        ];

        foreach ($columns as $key => $column) {
            $value = $this->clean($payload[$key] ?? null);

            // MACs are compared in a separator-free form on *both* sides, because
            // the register stores dashes, WMI reports colons, and an operator
            // may type either. Only one side normalised still leaves a mismatch,
            // and a mismatch here is silent: the report just falls through to
            // the telemetry fallback.
            if ($key === 'mac_address') {
                $value = $this->normaliseMac($value);
            }

            if ($value === null) {
                continue;
            }

            $asset = $key === 'mac_address'
                ? Asset::whereRaw(
                    "UPPER(REPLACE(REPLACE(REPLACE(mac_address, '-', ''), ':', ''), ' ', '')) = ?",
                    [$value]
                )->first()
                : Asset::where($column, $value)->first();

            if ($asset) {
                return $asset->id;
            }
        }

        return AgentTelemetry::where('agent_token_id', $token->id)
            ->whereNotNull('asset_id')
            ->orderByDesc('reported_at')
            ->value('asset_id');
    }

    private function rules(): array
    {
        $bool = 'nullable|boolean';
        $int = 'nullable|integer|min:0';
        $text = 'nullable|string|max:255';

        return [
            'device_id' => $text,
            // Identity fields, used to attach this report to the asset it describes.
            // `mac_address` has to be declared here: validate() returns only the
            // keys that have a rule, so an unlisted field is dropped from the
            // payload before resolveAssetId ever sees it. The agent does send it
            // (FingerprintCollector reads Win32_NetworkAdapter), so omitting this
            // silently disabled MAC-based asset matching while the code below
            // still appeared to support it.
            'asset_id' => $text,
            'serial_number' => $text,
            'hostname' => $text,
            'mac_address' => $text,

            'antivirus_product' => $text,
            'antivirus_enabled' => $bool,
            'antivirus_signature_age_days' => $int,
            'firewall_enabled' => $bool,
            'firewall_profiles_off' => $text,
            'uac_enabled' => $bool,
            'secure_boot_enabled' => $bool,
            'tpm_present' => $bool,
            'bitlocker_status' => $text,
            'bitlocker_method' => $text,
            'rdp_enabled' => $bool,
            'local_admin_count' => $int,

            'windows_edition' => $text,
            'license_channel' => $text,
            'activation_status' => $text,

            'os_build' => $text,
            'os_installed_at' => 'nullable|date',
            'last_boot_at' => 'nullable|date',
            'pending_reboot' => $bool,
            'domain' => $text,
            'timezone' => $text,

            // Windows Update state. The pending counts come from a search the
            // agent gives a hard 25-second budget, so they are null on any
            // machine that could not answer in time - a different fact from zero,
            // and the distinction the UI depends on.
            'windows_updates_pending' => $int,
            'security_updates_pending' => $int,
            'updates_succeeded' => $int,
            'updates_failed' => $int,
            'security_updates_installed' => $int,
            'last_hotfix_installed_at' => 'nullable|date',
            'last_security_update_at' => 'nullable|date',

            'disk_type' => $text,
            'smart_health' => $text,
            'smart_predicted_failure' => $bool,
            'cpu_cores' => $int,
            'cpu_physical_cores' => $int,
            'cpu_architecture' => $text,
            // Slot counts, so the register can answer "can this take more RAM?".
            // capped at 16 because no consumer board has more, and it keeps a
            // bad reading from becoming a silly number in the UI.
            'ram_modules' => $int.'|max:16',
            'ram_slots' => $int.'|max:16',
            'monitor' => $text,
            'bios_version' => $text,
            'bios_date' => 'nullable|date',
            'chassis_type' => $text,
            'device_model' => $text,
            'screen_resolution' => $text,

            // Sign-in and lock posture. A zero lock timeout is legal and means
            // "lock immediately", so the minimum is 0 rather than 1 - rejecting
            // it would throw away the best possible setting on the machine.
            //
            // The upper bounds are enforced by clamping in attributes() rather
            // than by validation. A `max:` rule here answers 422 and discards the
            // entire report - several hundred software rows included - because
            // one idle-timeout reading came back as 999999. A report is the only
            // inventory this machine will ever send, so one impossible sensor
            // reading must not cost the rest of it.
            'auto_logon_enabled' => $bool,
            'screen_lock_enabled' => $bool,
            'lock_timeout_minutes' => 'nullable|integer|min:0',
            'guest_account_enabled' => $bool,

            // Defender detail, and patch recency.
            'defender_realtime_enabled' => $bool,
            'defender_last_scan_days' => $int,
            'hotfix_count' => $int,

            'software_count' => $int,
            'startup_count' => $int,

            'software' => 'nullable|array|max:'.self::MAX_SOFTWARE_ROWS,
            'software.*.name' => 'required|string|max:255',
            'software.*.version' => 'nullable|string|max:255',
            'software.*.publisher' => 'nullable|string|max:255',
            'software.*.installed_at' => 'nullable|date',
        ];
    }

    /**
     * An integer that is discarded entirely when it exceeds a plausible ceiling.
     *
     * The alternative - clamping to the ceiling - was tried first and is wrong:
     * an agent reporting a 999999-minute idle timeout would have been stored as
     * 1440, which reads in the register as "locks after a day", a confident and
     * completely fabricated security setting. A null is honest: nobody has a
     * reading for this machine.
     *
     * Zero is preserved. For lock_timeout_minutes it means "lock immediately",
     * the strongest possible setting, and treating it as a failed read would
     * invert the meaning of the best-configured machines in the fleet.
     */
    private function boundedIntOrNull(mixed $value, int $ceiling): ?int
    {
        $number = $this->intOrNull($value);

        if ($number === null) {
            return null;
        }

        return $number > $ceiling ? null : $number;
    }

    private function clean(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * The agent sends booleans as "true"/"false" strings because its telemetry
     * dictionary is string-typed. Anything unparsable is dropped rather than
     * guessed, so an unreadable sensor is never recorded as a passing check.
     */
    private function boolOrNull(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));

            if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    /**
     * A count that must be at least 1, or null.
     *
     * Zero is the sentinel firmware uses for "I could not read this", so storing
     * it verbatim would report a machine with zero of something. Anything
     * non-positive becomes null, which the views render as a dash.
     */
    private function positiveIntOrNull(mixed $value): ?int
    {
        $int = $this->intOrNull($value);

        return $int !== null && $int > 0 ? $int : null;
    }

    /**
     * Reduce a MAC address to bare uppercase hex, or null if it is not one.
     *
     * Windows reports colons (34:5A:...), the asset register stores dashes
     * (34-5A-...), and operators type either or nothing at all. Comparing the
     * bare form makes the lookup immune to which convention the source used
     * instead of silently matching nothing on every device in the fleet.
     */
    private function normaliseMac(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $hex = strtoupper((string) preg_replace('/[^0-9A-Fa-f]/', '', $value));

        // A MAC is exactly 6 octets; anything else is a typo or a placeholder
        // like "N/A" and must not reach the query.
        return strlen($hex) === 12 ? $hex : null;
    }

    private function dateOrNull(mixed $value): ?string
    {
        $value = $this->clean($value);

        // WMI hands back 0000-00-00 style sentinels for "never"; storing those
        // would make the column unreadable instead of simply empty.
        if ($value === null || ! preg_match('/\d{4}-\d{2}-\d{2}/', $value)) {
            return null;
        }

        return $value;
    }
}
