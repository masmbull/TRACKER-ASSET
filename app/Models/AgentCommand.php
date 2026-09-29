<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentCommand extends Model
{
    use HasFactory;

    protected $fillable = [
        'agent_token_id', 'command', 'payload', 'status', 'progress', 'stage', 'started_at',
        'executed_at', 'result',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'progress' => 'integer',
            'started_at' => 'datetime',
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

    public function isRunning(): bool
    {
        return $this->status === 'in_progress';
    }

    /**
     * True when an operation claims to be running but has stopped reporting.
     *
     * The agent dies in the middle of things - a locked binary, a killed
     * process, a machine that simply went away - and without this the page keeps
     * rendering whatever percentage it last received, so a dead install looks
     * identical to a slow one. Two minutes is chosen against the inbox poll,
     * which defaults to 10 seconds: a step that has gone six minutes without a
     * word is not busy.
     *
     * Only applies while running. A settled command is a record, not a promise,
     * and a finished update from last week is not "stalled".
     */
    public function isStalled(int $graceMinutes = 2): bool
    {
        if (! $this->isRunning()) {
            return false;
        }

        $lastMovement = $this->updated_at ?? $this->started_at;

        return $lastMovement === null
            || $lastMovement->lt(now()->subMinutes($graceMinutes));
    }

    /**
     * A human sentence for the fleet table, or null when there is nothing to say.
     *
     * The stalled case is checked before the running one on purpose: "stuck at
     * 45% for 8 minutes" is the fact an operator needs, and burying it under a
     * cheerful progress bar is exactly the failure this feature exists to stop.
     */
    public function progressLabel(): ?string
    {
        if ($this->isStalled()) {
            return 'stalled at '.$this->progressLabelNoStall();
        }

        return $this->progressLabelNoStall();
    }

    private function progressLabelNoStall(): string
    {
        $percent = $this->progress !== null ? $this->progress.'%' : null;

        return match (true) {
            filled($this->stage) && $percent !== null => $this->stage.' ('.$percent.')',
            filled($this->stage) => $this->stage,
            $percent !== null => $percent,
            default => 'in progress',
        };
    }
}
