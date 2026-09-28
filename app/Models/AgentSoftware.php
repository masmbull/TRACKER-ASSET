<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One installed program on a fingerprinted device.
 *
 * Read from the registry Uninstall keys, never from Win32_Product: querying
 * Win32_Product makes Windows run a consistency check that can trigger an MSI
 * repair of every product on the machine, which is a genuine risk to leave
 * running unattended across a laptop fleet.
 */
class AgentSoftware extends Model
{
    protected $fillable = [
        'agent_fingerprint_id', 'asset_id', 'name', 'version', 'publisher', 'installed_at',
    ];

    protected function casts(): array
    {
        return [
            'installed_at' => 'date',
        ];
    }

    public function fingerprint(): BelongsTo
    {
        return $this->belongsTo(AgentFingerprint::class, 'agent_fingerprint_id');
    }
}
