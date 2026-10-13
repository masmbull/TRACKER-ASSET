<?php

namespace App\Models;

use Database\Factories\CustomQrHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomQrHistory extends Model
{
    /** @use HasFactory<CustomQrHistoryFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'content'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
