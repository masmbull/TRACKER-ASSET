<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentApiToken extends Model
{
    protected $fillable = [
        'name', 'token_hash', 'abilities', 'last_used_at', 'expires_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function isUsable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
