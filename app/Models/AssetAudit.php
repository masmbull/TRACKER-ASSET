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
        'asset_id', 'audit_date', 'auditor_id', 'device_hostname', 'device_serial',
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

    public function activities(): HasMany
    {
        return $this->hasMany(ActivityLog::class, 'entity_id')->where('entity_type', 'asset_audits');
    }
}
