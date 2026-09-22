<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IkeBastInspectionLineItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id', 'seq', 'item_desc', 'item_code', 'barcode', 'serial_number',
        'unit', 'qty_planned', 'qty_received', 'physical_condition',
        'test_result', 'remarks', 'is_defective',
    ];

    protected function casts(): array
    {
        return [
            'qty_planned' => 'decimal:4',
            'qty_received' => 'decimal:4',
            'is_defective' => 'boolean',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(IkeBastInspectionSession::class, 'session_id');
    }
}
