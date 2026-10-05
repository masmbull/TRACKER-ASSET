<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetAudit extends Model
{
    use HasFactory;

    protected $fillable = [
        'asset_id', 'agent_token_id', 'audit_date', 'auditor_id', 'device_hostname', 'device_serial',
        'device_brand', 'device_model', 'device_processor', 'device_ram', 'device_storage',
        'device_os', 'device_mac', 'device_ip', 'result', 'mismatches', 'notes',
        'photo_path', 'status',
    ];

    protected function casts(): array
    {
        return [
            'audit_date' => 'datetime',
            'mismatches' => 'array',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function auditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auditor_id');
    }

    /**
     * The device that reported this scan.
     *
     * Set even when asset_id is null: a scan from a laptop that is not on the
     * register yet is only findable through the token that sent it. Deleting the
     * token leaves the scan behind, because the hardware readings in the row are
     * the only record that device ever existed.
     */
    public function agentToken(): BelongsTo
    {
        return $this->belongsTo(AgentApiToken::class, 'agent_token_id');
    }

    /**
     * True while the scan has not been promoted to a registered asset.
     */
    public function isUnmatched(): bool
    {
        return $this->asset_id === null;
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ActivityLog::class, 'entity_id')->where('entity_type', 'asset_audits');
    }
}
