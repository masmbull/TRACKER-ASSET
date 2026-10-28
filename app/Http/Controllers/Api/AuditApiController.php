<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AgentApiToken;
use App\Models\AgentTelemetry;
use App\Models\Asset;
use App\Models\AssetAudit;
use App\Services\AuditComparisonService;
use Illuminate\Http\Request;

/**
 * API for the lightweight audit agent (PRD §11 — preferred audit architecture).
 *
 * The agent only collects hardware data and posts JSON here. All comparison,
 * decision-making and storage logic lives in Laravel, so the API result
 * matches the browser audit flow exactly.
 *
 * Authentication: Bearer token issued with `php artisan audit:token`.
 */
class AuditApiController extends Controller
{
    /**
     * Live Fleet health readings sent alongside the hardware audit (PRD §11).
     * All values arrive as strings from the agent collector, so numeric fields
     * are validated with `numeric` and silently dropped when unparsable.
     */
    private const TELEMETRY_NUMERIC_FIELDS = [
        'battery_percent', 'battery_health', 'battery_cycle_count',
        'disk_free_gb', 'disk_total_gb', 'wifi_signal', 'uptime_hours',
    ];

    /**
     * Telemetry heartbeat (PRD §11). Deliberately narrow: the daemon sends this
     * every minute whether or not anyone clicks "Scan Now", so it must not
     * create an asset_audits row. At one row a minute a single laptop would
     * bury months of real audit history.
     */
    public function heartbeat(Request $request)
    {
        $this->normaliseTelemetryInput($request);

        $payload = $request->validate($this->telemetryValidationRules());

        $telemetry = AgentTelemetry::where('agent_token_id', $request->attributes->get('agent_token_id'))->first();

        if (! $telemetry) {
            // The device has never completed a scan, so there is no asset to
            // attach readings to yet. Tell the agent to run a full scan instead
            // of silently inventing a fleet row.
            return response()->json([
                'ok' => false,
                'message' => 'No telemetry snapshot yet. Run a full scan to register this device.',
            ], 202);
        }

        $telemetry->fill($this->telemetryDelta($request, $payload, $telemetry));
        $telemetry->save();

        // The device's MAC, kept on the token rather than only in the telemetry
        // row.
        //
        // Wake on LAN addresses the NIC, and a NIC is asleep exactly when the
        // telemetry row is stale - so the reading that makes a machine
        // wakeable has to outlive the heartbeat that carried it. The agent
        // already sends its MAC on every heartbeat (the collector reads it for
        // the audit), but the field had no validation rule, so Laravel dropped
        // it from the payload and it never reached the database at all.
        $this->rememberMacAddress($request, $payload);
        $this->rememberRustdeskId($request, $payload);

        return response()->json([
            'ok' => true,
            'reported_at' => $telemetry->reported_at?->toIso8601String(),
        ]);
    }

    /**
     * Store the MAC the agent reported against the device's token.
     *
     * Only written when it is absent or different, so the common case - the
     * same laptop reporting the same address every minute - does not turn into
     * a database write on every single heartbeat.
     */
    private function rememberMacAddress(Request $request, array $payload): void
    {
        $reported = $payload['mac_address'] ?? null;

        if (! is_string($reported) || trim($reported) === '') {
            return;
        }

        $token = AgentApiToken::find($request->attributes->get('agent_token_id'));

        if (! $token || $token->mac_address === $reported) {
            return;
        }

        $token->mac_address = $reported;
        $token->save();
    }

    private function rememberRustdeskId(Request $request, array $payload): void
    {
        $reported = trim((string) ($payload['rustdesk_id'] ?? ''));
        if ($reported === '') {
            return;
        }

        $token = AgentApiToken::find($request->attributes->get('agent_token_id'));
        if (! $token || $token->rustdesk_id === $reported) {
            return;
        }

        $token->rustdesk_id = $reported;
        $token->rustdesk_reported_at = now();
        $token->save();
    }

    /**
     * Build the partial update for a heartbeat. Only fields the agent actually
     * sent are touched, so a reader that fails to report one value (a laptop
     * with no battery, a flaky Wi-Fi probe) keeps its last known good reading
     * instead of having it blanked out every minute.
     */
    private function telemetryDelta(Request $request, array $payload, AgentTelemetry $current): array
    {
        $columns = [
            'hostname', 'logged_in_user', 'is_elevated', 'os', 'ip_address', 'gpu', 'ram_config',
            'wifi_ssid', 'battery_percent', 'battery_health', 'battery_cycle_count',
            'wifi_signal', 'network_type', 'network_adapter', 'network_speed',
            'uptime_hours',
        ];

        $updates = [];

        foreach ($columns as $column) {
            // Both checks, deliberately. `$request->has()` alone was the trap:
            // a key can be present in the request and still be missing from the
            // validated payload, because Laravel drops every field that has no
            // validation rule. Reading it anyway threw a 500 on every real
            // heartbeat. `array_key_exists` makes the builder depend only on
            // what actually survived validation.
            if ($request->has($column) && array_key_exists($column, $payload)) {
                $updates[$column] = $payload[$column];
            }
        }

        // The agent's own mode is a reading like any other: a heartbeat that
        // does not carry it - an agent predating the field, or one that sent
        // only the readings it had - must not downgrade a known tray/SYSTEM
        // report to "unknown". Partial updates keep what they were not told
        // (PRD §11), and this is the one field the fleet's status column is
        // built from.
        $currentPayload = $current->payload ?? [];

        $updates['payload'] = [
            ...$currentPayload,
            'agent_mode' => $payload['agent_mode'] ?? ($currentPayload['agent_mode'] ?? 'unknown'),
        ];

        // Disk free is a percentage derived from both halves, so a heartbeat
        // that only sends one of them would otherwise recompute a wrong ratio.
        if ($request->has('disk_free_gb') || $request->has('disk_total_gb')) {
            $free = $request->has('disk_free_gb')
                ? $this->numericOrNull($payload['disk_free_gb'])
                : $current->disk_free_gb;
            $total = $request->has('disk_total_gb')
                ? $this->numericOrNull($payload['disk_total_gb'])
                : $current->disk_total_gb;

            $updates['disk_free_gb'] = $free;
            $updates['disk_total_gb'] = $total;
            $updates['disk_free_percent'] = ($free !== null && $total)
                ? round($free / $total * 100, 2)
                : null;
        }

        return [...$updates, 'reported_at' => now()];
    }

    /**
     * The subset of readings the heartbeat endpoint accepts. Kept separate from
     * the audit's identity fields so a ping can never create or resolve an asset.
     */
    private function telemetryValidationRules(): array
    {
        return [
            'hostname' => 'nullable|string|max:255',
            // `os` must be listed here as well as in telemetryDelta(): the
            // collector always sends it, and a validated payload only carries
            // keys that have a rule. Without this the delta builder read
            // $payload['os'] on a key Laravel had already discarded, which made
            // every real heartbeat die with a 500 while the unit tests — which
            // only ever sent rule-listed fields — stayed green.
            'os' => 'nullable|string|max:255',
            'ip_address' => 'nullable|string|max:255',
            // The device's own MAC. Read by the collector on every heartbeat,
            // and the only thing that makes a sleeping laptop wakeable over
            // the network - see rememberMacAddress().
            'mac_address' => 'nullable|string|max:255',
            'rustdesk_id' => 'nullable|string|max:64',
            'logged_in_user' => 'nullable|string|max:255',
            'is_elevated' => 'nullable|boolean',
            'agent_mode' => 'nullable|string|in:tray,service,unknown',
            'gpu' => 'nullable|string|max:255',
            'ram_config' => 'nullable|string|max:255',
            'wifi_ssid' => 'nullable|string|max:255',
            'battery_percent' => 'nullable|numeric|between:0,100',
            'battery_health' => 'nullable|numeric|between:0,100',
            'battery_cycle_count' => 'nullable|numeric|min:0',
            'disk_free_gb' => 'nullable|numeric|min:0',
            'disk_total_gb' => 'nullable|numeric|min:0',
            'wifi_signal' => 'nullable|numeric|between:0,100',
            'network_type' => 'nullable|string|max:32',
            'network_adapter' => 'nullable|string|max:255',
            'network_speed' => 'nullable|string|max:64',
            'uptime_hours' => 'nullable|numeric|min:0',
        ];
    }

    public function submit(Request $request, AuditComparisonService $comparison)
    {
        $this->normaliseTelemetryInput($request);

        $payload = $request->validate([
            'asset_id' => 'nullable|string|max:255',
            'serial_number' => 'nullable|string|max:255',
            'hostname' => 'nullable|string|max:255',
            'mac_address' => 'nullable|string|max:255',
            'rustdesk_id' => 'nullable|string|max:64',
            'brand' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255',
            'processor' => 'nullable|string|max:255',
            'ram' => 'nullable|string|max:255',
            'storage' => 'nullable|string|max:255',
            'os' => 'nullable|string|max:255',
            'ip_address' => 'nullable|string|max:255',
            'device_id' => 'nullable|string|max:255',
            'logged_in_user' => 'nullable|string|max:255',
            'is_elevated' => 'nullable|boolean',
            'agent_mode' => 'nullable|string|in:scan,tray,service,unknown',
            'gpu' => 'nullable|string|max:255',
            'ram_config' => 'nullable|string|max:255',
            'wifi_ssid' => 'nullable|string|max:255',
            'battery_percent' => 'nullable|numeric|between:0,100',
            'battery_health' => 'nullable|numeric|between:0,100',
            'battery_cycle_count' => 'nullable|numeric|min:0',
            'disk_free_gb' => 'nullable|numeric|min:0',
            'disk_total_gb' => 'nullable|numeric|min:0',
            'wifi_signal' => 'nullable|numeric|between:0,100',
            'network_type' => 'nullable|string|max:32',
            'network_adapter' => 'nullable|string|max:255',
            'network_speed' => 'nullable|string|max:64',
            'uptime_hours' => 'nullable|numeric|min:0',
        ]);

        $asset = $this->resolveAsset($payload);

        if (! $asset) {
            return $this->unknownDevice($payload);
        }

        $actual = [
            'device_hostname' => $payload['hostname'] ?? null,
            'device_serial' => $payload['serial_number'] ?? null,
            'device_brand' => $payload['brand'] ?? null,
            'device_model' => $payload['model'] ?? null,
            'device_processor' => $payload['processor'] ?? null,
            'device_ram' => $payload['ram'] ?? null,
            'device_storage' => $payload['storage'] ?? null,
            'device_os' => $payload['os'] ?? null,
            'device_mac' => $payload['mac_address'] ?? null,
            'device_ip' => $payload['ip_address'] ?? null,
        ];

        $result = $comparison->compare($asset, $actual);

        $audit = AssetAudit::create([
            'asset_id' => $asset?->id,
            'audit_date' => now(),
            'auditor_id' => null,
            ...$actual,
            'result' => $result['result'],
            'mismatches' => $result['mismatches'],
            'status' => 'submitted',
            'notes' => 'Submitted by audit agent ('.$request->attributes->get('agent_token_name').').',
        ]);

        ActivityLog::create([
            'actor_id' => null,
            'action' => 'AUDIT',
            'entity_type' => 'asset_audits',
            'entity_id' => $audit->id,
            'entity_identifier' => $asset->asset_id,
            'new_value' => json_encode($actual),
            'metadata' => ['result' => $result['result'], 'mismatches' => $result['mismatches'], 'via' => 'agent-api'],
            'source' => 'Audit Agent',
        ]);

        $updatedFields = $this->syncRegisteredHardware($asset, $payload, $actual, $audit, $comparison);

        // Dual write (PRD §11): the audit feeds Master Assets, the telemetry
        // snapshot feeds the Live Fleet view.
        $this->recordTelemetry($asset, $payload);

        return response()->json([
            'asset' => $asset->asset_id,
            'result' => $result['result'],
            'mismatches' => $result['mismatches'],
            'updated_fields' => $updatedFields,
            'message' => $this->resultMessage($asset, $result),
        ]);
    }

    /**
     * Refresh hardware values only when one exact serial uniquely identifies the
     * registered asset. Unknown devices and ambiguous duplicate serials remain
     * reports for an operator to review rather than writes to the register.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string|null>  $actual
     * @return list<string>
     */
    private function syncRegisteredHardware(
        Asset $asset,
        array $payload,
        array $actual,
        AssetAudit $audit,
        AuditComparisonService $comparison,
    ): array {
        $serial = trim((string) ($payload['serial_number'] ?? ''));

        if ($serial === '' || $asset->serial_number !== $serial) {
            return [];
        }

        if (Asset::query()->where('serial_number', $serial)->count() !== 1) {
            return [];
        }

        $previous = array_intersect_key(
            $asset->getAttributes(),
            array_flip(array_keys(AuditComparisonService::COMPARED_FIELDS)),
        );
        $changed = $comparison->applyToAsset($asset, $actual);

        if ($changed === []) {
            return [];
        }

        ActivityLog::create([
            'actor_id' => null,
            'action' => 'UPDATE',
            'entity_type' => 'assets',
            'entity_id' => $asset->id,
            'entity_identifier' => $asset->asset_id,
            'old_value' => json_encode(array_intersect_key($previous, array_flip($changed))),
            'new_value' => json_encode(array_intersect_key($asset->getAttributes(), array_flip($changed))),
            'source' => 'Audit Agent',
            'metadata' => ['automatic_scan_sync' => true, 'audit_id' => $audit->id],
        ]);

        return $changed;
    }

    /**
     * Resolve the asset by business identifiers, most stable first.
     */
    private function resolveAsset(array $payload): ?Asset
    {
        foreach (['asset_id', 'serial_number', 'hostname', 'mac_address'] as $key) {
            $value = $payload[$key] ?? null;

            if ($value === null || trim((string) $value) === '') {
                continue;
            }

            $column = $key === 'asset_id' ? 'asset_id' : match ($key) {
                'serial_number' => 'serial_number',
                'hostname' => 'hostname',
                'mac_address' => 'mac_address',
            };

            $asset = Asset::where($column, $value)->first();

            if ($asset) {
                return $asset;
            }
        }

        return null;
    }

    private function unknownDevice(array $payload)
    {
        $token = AgentApiToken::find(request()->attributes->get('agent_token_id'));

        if ($token) {
            $token->touch();
        }

        // No asset is created here.
        //
        // This used to invent one: any laptop whose serial was not on file
        // became an Asset with guessed company, category and location, the
        // moment it scanned. The register - the record the whole product exists
        // to keep - filled with rows nobody entered and nothing on screen said
        // they were guesses.
        //
        // So a scan from an unknown device is stored as a scan, and waits on the
        // fleet page holding its hardware readings until somebody promotes it.
        // The agent's answer changes accordingly: there is nothing to report as
        // an asset id, and telling it "registered as AST-XXXX" would make a
        // decision look like it had been made.
        $audit = AssetAudit::create([
            'asset_id' => null,
            'agent_token_id' => request()->attributes->get('agent_token_id'),
            'audit_date' => now(),
            'auditor_id' => null,
            'device_hostname' => $payload['hostname'] ?? null,
            'device_serial' => $payload['serial_number'] ?? null,
            'device_brand' => $payload['brand'] ?? null,
            'device_model' => $payload['model'] ?? null,
            'device_processor' => $payload['processor'] ?? null,
            'device_ram' => $payload['ram'] ?? null,
            'device_storage' => $payload['storage'] ?? null,
            'device_os' => $payload['os'] ?? null,
            'device_mac' => $payload['mac_address'] ?? null,
            'device_ip' => $payload['ip_address'] ?? null,
            'result' => AuditComparisonService::NEW_DEVICE,
            'mismatches' => [],
            'status' => 'submitted',
            'notes' => 'Waiting on the fleet page — no asset created automatically.',
        ]);

        ActivityLog::create([
            'actor_id' => null,
            'action' => 'AUDIT',
            'entity_type' => 'asset_audits',
            'entity_id' => $audit->id,
            // No asset id to name yet: naming the serial keeps the trail
            // searchable from the activity log without pretending the row is a
            // registered asset.
            'entity_identifier' => $payload['serial_number']
                ?? $payload['hostname']
                ?? 'unmatched device',
            'new_value' => json_encode($payload),
            'metadata' => ['result' => AuditComparisonService::NEW_DEVICE, 'via' => 'agent-api'],
            'source' => 'Audit Agent',
        ]);

        // Telemetry still goes in, with no asset attached. This is what keeps
        // the laptop visible on the fleet page in the meantime, and what the
        // promote form is prefilled from.
        $this->recordTelemetry(null, $payload);

        return response()->json([
            'result' => AuditComparisonService::NEW_DEVICE,
            'mismatches' => [],
            'message' => 'Device seen for the first time. It is on the fleet page waiting to be added to the register — nothing was overwritten.',
        ], 201);
    }

    /**
     * Older/limited agents send "" (or "N/A") for readings they could not
     * collect. Blank them out before validation so one unreadable sensor never
     * rejects an otherwise valid audit.
     */
    private function normaliseTelemetryInput(Request $request): void
    {
        $numeric = self::TELEMETRY_NUMERIC_FIELDS;
        $text = ['device_id', 'logged_in_user', 'gpu', 'ram_config', 'wifi_ssid'];

        $clean = [];

        foreach ([...$numeric, ...$text] as $field) {
            if (! $request->has($field)) {
                continue;
            }

            $value = $request->input($field);

            if (is_string($value) && trim($value) === '') {
                $clean[$field] = null;
            } elseif (in_array($field, $numeric, true) && ! is_numeric($value)) {
                $clean[$field] = null;
            }
        }

        if ($clean !== []) {
            $request->merge($clean);
        }
    }

    /**
     * Persist the Live Fleet snapshot for this device (PRD §11). One row per
     * agent token is refreshed in place so the fleet view always shows the
     * latest battery/disk/RAM/Wi-Fi reading; history stays in asset_audits.
     */
    private function recordTelemetry(?Asset $asset, array $payload): void
    {
        $tokenId = request()->attributes->get('agent_token_id');
        $token = $tokenId ? AgentApiToken::find($tokenId) : null;

        $diskFree = $this->numericOrNull($payload['disk_free_gb'] ?? null);
        $diskTotal = $this->numericOrNull($payload['disk_total_gb'] ?? null);

        $attributes = [
            'asset_id' => $asset?->id,
            'device_id' => $payload['device_id'] ?? $token?->device_id,
            'hostname' => $payload['hostname'] ?? null,
            'logged_in_user' => $payload['logged_in_user'] ?? null,
            'is_elevated' => array_key_exists('is_elevated', $payload) ? (bool) $payload['is_elevated'] : null,
            'os' => $payload['os'] ?? null,
            'ip_address' => $payload['ip_address'] ?? null,
            'battery_percent' => $this->intOrNull($payload['battery_percent'] ?? null),
            'battery_health' => $this->intOrNull($payload['battery_health'] ?? null),
            'battery_cycle_count' => $this->intOrNull($payload['battery_cycle_count'] ?? null),
            'disk_free_gb' => $diskFree,
            'disk_total_gb' => $diskTotal,
            'disk_free_percent' => ($diskFree !== null && $diskTotal) ? round($diskFree / $diskTotal * 100, 2) : null,
            'ram_config' => $payload['ram_config'] ?? null,
            'gpu' => $payload['gpu'] ?? null,
            'wifi_ssid' => $payload['wifi_ssid'] ?? null,
            'wifi_signal' => $this->intOrNull($payload['wifi_signal'] ?? null),
            'network_type' => $payload['network_type'] ?? null,
            'network_adapter' => $payload['network_adapter'] ?? null,
            'network_speed' => $payload['network_speed'] ?? null,
            'uptime_hours' => $this->numericOrNull($payload['uptime_hours'] ?? null),
            'payload' => $payload,
            'reported_at' => now(),
        ];

        $key = $token
            ? ['agent_token_id' => $token->id]
            : ['device_id' => $payload['device_id'] ?? $payload['hostname'] ?? 'unknown'];

        AgentTelemetry::updateOrCreate($key, $attributes);
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    private function resultMessage(Asset $asset, array $result): string
    {
        if ($result['result'] === AuditComparisonService::MATCH) {
            return "MATCH: device matches asset {$asset->asset_id}. Nothing needs updating.";
        }

        if ($result['mismatches'] === []) {
            return "{$result['result']}: device {$asset->asset_id}. See dashboard for details.";
        }

        $details = implode('; ', array_map(
            fn (string $field, array $values) => "{$field}: registered \"{$values['registered']}\" vs actual \"{$values['actual']}\"",
            array_keys($result['mismatches']),
            $result['mismatches']
        ));

        return "MISMATCH on asset {$asset->asset_id} — {$details}. Registered data was NOT overwritten.";
    }
}
