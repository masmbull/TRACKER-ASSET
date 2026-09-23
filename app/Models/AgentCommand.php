<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentCommand extends Model
{
    use HasFactory;

    protected $fillable = [
        'agent_token_id', 'command', 'payload', 'status', 'executed_at', 'result',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'executed_at' => 'datetime',
        ];
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(AgentApiToken::class, 'agent_token_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
