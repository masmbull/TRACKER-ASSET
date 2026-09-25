<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentApiToken extends Model
{
    protected $fillable = [
        'name', 'token_hash', 'token_plain', 'abilities', 'last_used_at', 'expires_at', 'is_active',
        'device_id', 'platform',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
            'token_plain' => 'encrypted',
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
