<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Live Fleet telemetry snapshot (PRD §11) — the latest health reading of one
 * agent device: battery wear/cycles, C: disk space, RAM layout, Wi-Fi and the
 * Windows user that is currently logged in. One row per device is refreshed by
 * AuditApiController on every scan; the audit history stays in asset_audits.
 */
class AgentTelemetry extends Model
{
    protected $fillable = [
        'agent_token_id', 'asset_id', 'device_id', 'hostname', 'logged_in_user', 'os', 'ip_address',
        'is_elevated',
        'battery_percent', 'battery_health', 'battery_cycle_count',
        'disk_free_gb', 'disk_total_gb', 'disk_free_percent',
        'ram_config', 'gpu', 'wifi_ssid', 'wifi_signal',
        'network_type', 'network_adapter', 'network_speed',
        'uptime_hours', 'payload', 'reported_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'reported_at' => 'datetime',
            'is_elevated' => 'boolean',
            'battery_percent' => 'integer',
            'battery_health' => 'integer',
            'battery_cycle_count' => 'integer',
            'disk_free_gb' => 'float',
            'disk_total_gb' => 'float',
            'disk_free_percent' => 'float',
            'wifi_signal' => 'integer',
            'uptime_hours' => 'float',
        ];
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(AgentApiToken::class, 'agent_token_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * Telemetry is considered live when the device reported within the window.
     *
     * The window is deliberately twice the daemon's 60-second heartbeat: that
     * gives one heartbeat of slack for a request that was slow or dropped
     * before a machine is called offline. The previous 5 minutes was tuned
     * against a poll-only agent and made "online" meaningless — a laptop that
     * had been shut down for an hour still showed a green dot.
     */
    public function isOnline(int $minutes = 2): bool
    {
        return $this->reported_at !== null && $this->reported_at->gt(now()->subMinutes($minutes));
    }

    public function hasLowBattery(): bool
    {
        return $this->battery_percent !== null && $this->battery_percent <= 20;
    }

    /**
     * Battery wear alarm: the cells no longer hold most of their design charge.
     */
    public function hasWeakBattery(): bool
    {
        return $this->battery_health !== null && $this->battery_health < 60;
    }

    public function hasLowDisk(int $threshold = 15): bool
    {
        return $this->disk_free_percent !== null && $this->disk_free_percent < $threshold;
    }
}
