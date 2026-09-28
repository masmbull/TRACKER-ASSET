<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use App\Support\ScheduleHeartbeat;
use Illuminate\Console\Command;

/**
 * Scheduled audit sweep (PRD §11): enqueues a scan for every active agent so
 * the register is re-verified automatically, without anyone clicking.
 *
 * Safe to run repeatedly: agents that already have a pending scan are skipped,
 * so a sweep never queues duplicates.
 */
class AuditSweepCommand extends Command
{
    protected $signature = 'audit:sweep
        {--token= : Only sweep this token name}
        {--force : Ignore the per-agent cooldown (used by tests and manual runs)}';

    protected $description = 'Queue an audit scan for every active agent (scheduled sweep)';

    /** Minimum minutes between two sweeps for the same agent. */
    private const COOLDOWN_MINUTES = 30;

    public function handle(): int
    {
        ScheduleHeartbeat::record('audit:sweep');

        $tokens = AgentApiToken::query()
            ->where('is_active', true)
            // An expired token answers 401 just like a revoked one, so queuing a
            // scan for it produces a command that can never be delivered. Filter
            // on the same usability rule the middleware enforces rather than
            // trusting `is_active` alone.
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->when($this->option('token'), fn ($q, $name) => $q->where('name', $name))
            ->get();

        if ($tokens->isEmpty()) {
            $this->warn('No active agent tokens found.');

            return self::SUCCESS;
        }

        $queued = 0;
        $skipped = 0;

        foreach ($tokens as $token) {
            if (! $this->option('force') && $this->hasRecentScan($token)) {
                $skipped++;

                continue;
            }

            AgentCommand::create([
                'agent_token_id' => $token->id,
                'command' => 'scan',
                'status' => 'pending',
                'payload' => ['source' => 'scheduled-sweep'],
            ]);

            $queued++;
        }

        if ($queued > 0) {
            ActivityLog::create([
                'actor_id' => null,
                'action' => 'AUDIT_SWEEP',
                'entity_type' => 'agent_commands',
                'entity_identifier' => sprintf('%d agent(s)', $queued),
                'metadata' => ['queued' => $queued, 'skipped' => $skipped],
                'source' => 'Scheduler',
            ]);
        }

        $this->info(sprintf('Audit sweep: queued %d, skipped %d.', $queued, $skipped));

        return self::SUCCESS;
    }

    /**
     * Skip agents that already have work pending or were swept recently.
     */
    private function hasRecentScan(AgentApiToken $token): bool
    {
        $hasPending = AgentCommand::query()
            ->where('agent_token_id', $token->id)
            ->where('command', 'scan')
            ->where('status', 'pending')
            ->exists();

        if ($hasPending) {
            return true;
        }

        return AgentCommand::query()
            ->where('agent_token_id', $token->id)
            ->where('command', 'scan')
            ->where('created_at', '>=', now()->subMinutes(self::COOLDOWN_MINUTES))
            ->exists();
    }
}
