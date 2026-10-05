<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AgentApiToken extends Model
{
    protected $fillable = [
        'name', 'token_hash', 'token_plain', 'abilities', 'last_used_at', 'expires_at', 'is_active',
        'device_id', 'platform', 'agent_version', 'last_seen_at', 'hardware_id',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'token_plain' => 'encrypted',
        ];
    }

    /**
     * Is this device behind the build the download button serves?
     *
     * null means "it has never told us" - every device enrolled before agents
     * reported their version. That is not the same as out of date, and the
     * fleet page says so rather than guessing a number to compare.
     */
    public function isOutdated(): ?bool
    {
        return AgentRelease::isBehind($this->agent_version);
    }

    /** Short label for the fleet table: the version, or why we have none. */
    public function versionLabel(): string
    {
        return $this->agent_version ?: 'unknown';
    }

    public function isUsable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * Latest Live Fleet telemetry snapshot for this device (PRD §11).
     */
    public function telemetry(): HasOne
    {
        return $this->hasOne(AgentTelemetry::class, 'agent_token_id')->latestOfMany('reported_at');
    }

    /**
     * Latest deep-inventory snapshot (software, security posture, licensing).
     */
    public function fingerprint(): HasOne
    {
        return $this->hasOne(AgentFingerprint::class, 'agent_token_id')->latestOfMany('collected_at');
    }
}
