<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AgentApiToken;
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
    private const FIND_FIELDS = ['hostname', 'serial_number', 'mac_address'];

    public function submit(Request $request, AuditComparisonService $comparison)
    {
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
            'notes' => 'Submitted by audit agent (' . $request->attributes->get('agent_token_name') . ').',
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

        ActivityLog::create([
            'actor_id' => null,
            'action' => 'AUDIT',
            'entity_type' => 'asset_audits',
            'entity_identifier' => ($payload['serial_number'] ?? null) ?: ($payload['hostname'] ?? 'unknown-device'),
            'new_value' => json_encode($payload),
            'metadata' => ['result' => AuditComparisonService::UNKNOWN_DEVICE, 'via' => 'agent-api'],
            'source' => 'Audit Agent',
        ]);

        return response()->json([
            'asset' => null,
            'result' => AuditComparisonService::UNKNOWN_DEVICE,
            'mismatches' => [],
            'message' => 'Device is not registered. Open a transfer/import flow or register it first — nothing was overwritten.',
        ], 202);
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
