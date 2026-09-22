<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
    use HasFactory;

    protected $fillable = [
        'asset_id', 'transfer_id', 'document_type', 'document_number', 'title',
        'template_id', 'payload', 'file_path', 'file_type', 'generated_date',
        'generated_by', 'status',
    ];

    protected function casts(): array
    {
        return [
            'generated_date' => 'date',
            'payload' => 'array',
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

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
