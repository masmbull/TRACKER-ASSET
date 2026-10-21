<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One printer installed on a device, as reported by the agent.
 *
 * The list hangs off the fingerprint rather than the token because that is
 * where the agent sends it: the printer walk is part of the deep inventory
 * that runs at install and then daily, not something the 60-second heartbeat
 * should be spending time on.
 */
class AgentPrinter extends Model
{
    use HasFactory;

    protected $fillable = [
        'agent_fingerprint_id', 'asset_id', 'name', 'driver_name', 'port_name',
        'location', 'is_network', 'is_default', 'is_offline', 'status_code', 'status',
    ];

    protected function casts(): array
    {
        return [
            'is_network' => 'boolean',
            'is_default' => 'boolean',
            'is_offline' => 'boolean',
            'status_code' => 'integer',
        ];
    }

    public function fingerprint(): BelongsTo
    {
        return $this->belongsTo(AgentFingerprint::class, 'agent_fingerprint_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * The one-line summary an operator reads on the fleet page.
     *
     * "HP LaserJet (offline)" is the fact; the port and driver are detail
     * that belongs in the inventory view, not in a row an operator scans.
     */
    public function label(): string
    {
        $label = $this->name;

        if ($this->is_offline) {
            $label .= ' (offline)';
        } elseif ($this->is_default) {
            $label .= ' (default)';
        }

        return $label;
    }
}
