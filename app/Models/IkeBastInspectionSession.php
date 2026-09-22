<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IkeBastInspectionSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'asset_id', 'transfer_id', 'created_by', 'session_number',
        'inspection_date', 'kind', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'inspection_date' => 'date',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(AssetTransfer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(IkeBastInspectionLineItem::class, 'session_id')->orderBy('seq');
    }
}
