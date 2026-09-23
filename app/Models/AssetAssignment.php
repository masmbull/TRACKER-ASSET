<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'asset_id', 'user_id', 'assigned_date', 'returned_date',
        'from_location_id', 'to_location_id', 'transfer_id', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'assigned_date' => 'date',
            'returned_date' => 'date',
        ];
    }

    /**
     * An assignment is open (current holder) while it has no return date.
     */
    public function isOpen(): bool
    {
        return $this->returned_date === null;
    }

    /**
     * Close an assignment at the given date. A closed assignment can never
     * be reopened — ownership moves forward only.
     */
    public function close(\DateTimeInterface|string $returnedDate): static
    {
        $this->update(['returned_date' => $returnedDate]);

        return $this;
    }

    /**
     * Days between assignment and return / today. Pure helper, no queries.
     */
    public function heldDays(?\DateTimeInterface $asOf = null): int
    {
        $end = $this->returned_date ?? ($asOf ? Carbon::instance($asOf)->startOfDay() : now()->startOfDay());

        return max(0, (int) $this->assigned_date->diffInDays($end, false)) + 1;
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(AssetTransfer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ActivityLog::class, 'entity_id')->where('entity_type', 'asset_assignments');
    }
}
