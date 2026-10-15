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

    /**
     * Minutes a command may sit in_progress with no progress report before it
     * counts as stalled.
     *
     * This is the single definition of "stuck". The page renders from
     * isStalled() and the inbox redelivers on the same number, and that is the
     * point: two copies of this figure would let a command be shown to the
     * operator as stalled while the server still considered it healthy, or the
     * reverse, and nothing would fail loudly. The inbox reads it from here.
     *
     * Two minutes is chosen against the inbox poll, which defaults to 10
     * seconds: a step that has gone six minutes without a word is not busy.
     */
    public const STALL_GRACE_MINUTES = 2;

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isRunning(): bool
    {
        return $this->status === 'in_progress';
    }

    /**
     * True when an operation is running (or waiting to be picked up) but has
     * stopped making progress.
     *
     * The agent dies in the middle of things - a locked binary, a killed
     * process, a machine that simply went away - and without this the page keeps
     * rendering whatever percentage it last received, so a dead install looks
     * identical to a slow one.
     *
     * Pending counts too, once it is older than the grace window. A command the
     * agent never came back for is the same failure seen from the other side: the
     * machine is gone, and the row would otherwise sit "queued" forever. This is
     * what lets a fleet-wide action finish and report "2 of 3, 1 not responding"
     * instead of a dialog pinned at "0 of 3" for a laptop that is switched off.
     *
     * A settled command is a record, not a promise: last week's finished update
     * is not "stalled".
     *
     * @param  int  $graceMinutes  Defaults to STALL_GRACE_MINUTES so the page and
     *                             the inbox cannot drift apart.
     */
    public function isStalled(int $graceMinutes = self::STALL_GRACE_MINUTES): bool
    {
        if (! $this->isRunning() && ! $this->isPending()) {
            return false;
        }

        // A pending command has no started_at; the moment it was queued is the
        // only clock it has, so fall back to created_at.
        $lastMovement = $this->updated_at
            ?? $this->started_at
            ?? $this->created_at;

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
            // A pending command never reported progress, so "stalled at in
            // progress" would be nonsense. Say what is actually true: nobody
            // picked it up.
            return $this->isPending()
                ? 'waiting for the agent to respond'
                : 'stalled at '.$this->progressLabelNoStall();
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
