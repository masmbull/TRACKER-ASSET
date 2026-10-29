<?php

namespace App\Models;

use App\Services\WakeOnLanService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AgentApiToken extends Model
{
    protected $fillable = [
        'name', 'token_hash', 'token_plain', 'abilities', 'last_used_at', 'expires_at', 'is_active',
        'device_id', 'platform', 'agent_version', 'last_seen_at', 'hardware_id', 'mac_address',
        'rustdesk_id', 'rustdesk_password', 'rustdesk_reported_at',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'rustdesk_reported_at' => 'datetime',
            'token_plain' => 'encrypted',
            // Encrypted at rest with APP_KEY, not hashed: unlike a login password
            // this one has to be handed back to an operator for RustDesk to use
            // it. Hashes are one-way and would make remote access impossible.
            'rustdesk_password' => 'encrypted',
        ];
    }

    /**
     * RustDesk settings the device should apply, or null when remote is not set up.
     */
    public function rustDeskConfig(): ?array
    {
        if (blank($this->rustdesk_id) || blank($this->rustdesk_password)) {
            return null;
        }

        return [
            'server' => config('services.rustdesk.rendezvous'),
            'key' => config('services.rustdesk.public_key'),
            'password' => $this->rustdesk_password,
            'id' => $this->rustdesk_id,
        ];
    }

    /**
     * A password that is long, random and safe to put inside a RustDesk.toml
     * single-quoted value and a URL.
     *
     * No quotes, backslashes or whitespace: the config writer escapes quotes but a
     * password that needs escaping is a password that can break the file, and this
     * value only ever has to survive a keyboard-free copy-paste.
     */
    public static function generateRustDeskPassword(): string
    {
        return \Illuminate\Support\Str::random(24);
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

    /**
     * Commands queued for this device.
     */
    public function commands(): HasMany
    {
        return $this->hasMany(AgentCommand::class, 'agent_token_id');
    }

    /**
     * Can this device be woken over the network?
     *
     * Wake on LAN needs the NIC's hardware address, and only the agent can
     * report it. A device that has never checked in therefore has no MAC and
     * cannot be woken - which is worth saying plainly, because "Wake" silently
     * doing nothing on a machine the operator cannot reach is exactly the kind
     * of feature that gets distrusted after one use.
     */
    public function canBeWoken(): bool
    {
        return WakeOnLanService::normaliseMac($this->mac_address) !== null;
    }

    /**
     * The address to send a magic packet to, or null when it is not known.
     */
    public function wakeMacAddress(): ?string
    {
        return WakeOnLanService::normaliseMac($this->mac_address);
    }
}
