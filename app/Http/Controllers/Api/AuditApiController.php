<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AgentApiToken;
use App\Models\AgentTelemetry;
use App\Models\Asset;
use App\Models\AssetAudit;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Services\AuditComparisonService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
    private const FIND_FIELDS = ['hostname', 'serial_number', 'mac_address'];

    /**
     * Live Fleet health readings sent alongside the hardware audit (PRD §11).
     * All values arrive as strings from the agent collector, so numeric fields
     * are validated with `numeric` and silently dropped when unparsable.
     */
    private const TELEMETRY_NUMERIC_FIELDS = [
        'battery_percent', 'battery_health', 'battery_cycle_count',
        'disk_free_gb', 'disk_total_gb', 'wifi_signal', 'uptime_hours',
    ];

    public function submit(Request $request, AuditComparisonService $comparison)
    {
        $this->normaliseTelemetryInput($request);

        $payload = $request->validate([
            'asset_id' => 'nullable|string|max:255',
            'serial_number' => 'nullable|string|max:255',
            'hostname' => 'nullable|string|max:255',
            'mac_address' => 'nullable|string|max:255',
            'brand' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255',
            'processor' => 'nullable|string|max:255',
            'ram' => 'nullable|string|max:255',
            'storage' => 'nullable|string|max:255',
            'os' => 'nullable|string|max:255',
            'ip_address' => 'nullable|string|max:255',
            'device_id' => 'nullable|string|max:255',
            'logged_in_user' => 'nullable|string|max:255',
            'gpu' => 'nullable|string|max:255',
            'ram_config' => 'nullable|string|max:255',
            'wifi_ssid' => 'nullable|string|max:255',
            'battery_percent' => 'nullable|numeric|between:0,100',
            'battery_health' => 'nullable|numeric|between:0,100',
            'battery_cycle_count' => 'nullable|numeric|min:0',
            'disk_free_gb' => 'nullable|numeric|min:0',
            'disk_total_gb' => 'nullable|numeric|min:0',
            'wifi_signal' => 'nullable|numeric|between:0,100',
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
            'asset_id' => $asset->id,
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

        // Dual write (PRD §11): the audit feeds Master Assets, the telemetry
        // snapshot feeds the Live Fleet view.
        $this->recordTelemetry($asset, $payload);

        return response()->json([
            'asset' => $asset->asset_id,
            'result' => $result['result'],
            'mismatches' => $result['mismatches'],
            'message' => $this->resultMessage($asset, $result),
        ]);
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

        $asset = $this->createUnknownAsset($payload);

        $audit = AssetAudit::create([
            'asset_id' => $asset->id,
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
            'notes' => 'Auto-registered by audit agent ('.request()->attributes->get('agent_token_name').').',
        ]);

        ActivityLog::create([
            'actor_id' => null,
            'action' => 'AUDIT',
            'entity_type' => 'asset_audits',
            'entity_id' => $audit->id,
            'entity_identifier' => $asset->asset_id,
            'new_value' => json_encode($payload),
            'metadata' => ['result' => AuditComparisonService::NEW_DEVICE, 'via' => 'agent-api'],
            'source' => 'Audit Agent',
        ]);

        // Unknown devices land in Live Fleet too, so a fresh laptop is visible
        // the moment the zero-config installer finishes.
        $this->recordTelemetry($asset, $payload);

        return response()->json([
            'asset' => $asset->asset_id,
            'result' => AuditComparisonService::NEW_DEVICE,
            'mismatches' => [],
            'message' => "New device registered as {$asset->asset_id}. Nothing was overwritten.",
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
    private function recordTelemetry(Asset $asset, array $payload): void
    {
        $tokenId = request()->attributes->get('agent_token_id');
        $token = $tokenId ? AgentApiToken::find($tokenId) : null;

        $diskFree = $this->numericOrNull($payload['disk_free_gb'] ?? null);
        $diskTotal = $this->numericOrNull($payload['disk_total_gb'] ?? null);

        $attributes = [
            'asset_id' => $asset->id,
            'device_id' => $payload['device_id'] ?? $token?->device_id,
            'hostname' => $payload['hostname'] ?? null,
            'logged_in_user' => $payload['logged_in_user'] ?? null,
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

    private function createUnknownAsset(array $payload): Asset
    {
        $seed = $payload['serial_number'] ?: $payload['hostname'] ?: 'DEVICE';
        $base = 'AST-'.(Str::upper(Str::slug((string) $seed, '-')) ?: 'DEVICE');
        $candidate = $base;
        $suffix = 1;

        while (Asset::where('asset_id', $candidate)->exists()) {
            $candidate = $base.'-'.(++$suffix);
        }

        return Asset::create([
            'asset_id' => $candidate,
            'company_id' => Company::firstOrFail(['id'])->id,
            'category_id' => AssetCategory::firstOrFail(['id'])->id,
            'brand' => $payload['brand'] ?? 'N/A',
            'model' => $payload['model'] ?? 'N/A',
            'serial_number' => $payload['serial_number'] ?? 'N/A',
            'hostname' => $payload['hostname'] ?? 'N/A',
            'processor' => $payload['processor'] ?? null,
            'storage' => $payload['storage'] ?? null,
            'ram' => $payload['ram'] ?? null,
            'os_version' => $payload['os'] ?? null,
            'mac_address' => $payload['mac_address'] ?? null,
            'static_ip' => $payload['ip_address'] ?? null,
            'location_id' => Location::firstOrFail(['id'])->id,
            'asset_status_id' => AssetStatus::firstOrFail(['id'])->id,
        ]);
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
