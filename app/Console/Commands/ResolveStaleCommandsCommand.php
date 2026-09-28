<?php

namespace App\Console\Commands;

use App\Models\AgentCommand;
use App\Support\ScheduleHeartbeat;
use Illuminate\Console\Command;

/**
 * Fail agent commands that can never be delivered (PRD §11).
 *
 * A pending command aimed at a revoked or expired token is dead on arrival: the
 * agent middleware answers 401 before the inbox is ever read, so no agent will
 * ever fetch it. Left alone it stays `pending` forever, which is what pinned
 * the fleet page to a permanent "Uninstall queued" badge on a machine nobody
 * can reach.
 *
 * Deliberately narrow. An offline laptop is a *normal* state and its queued
 * uninstall is still expected to run when it comes back, so nothing is failed
 * for being late — only for being undeliverable by construction.
 */
class ResolveStaleCommandsCommand extends Command
{
    protected $signature = 'agent:resolve-stale-commands';

    protected $description = 'Fail pending agent commands that can never be delivered (revoked or expired token)';

    /**
     * How old a pending command must be before we give up on it.
     *
     * Only a safety margin: an uninstall queued moments before someone hits
     * Revoke would otherwise be reported as a failure the instant it is queued.
     */
    private const GRACE_MINUTES = 10;

    public function handle(): int
    {
        // First thing, before any work: prove the scheduler is alive. The
        // services page reads this to tell "the schedule is configured" apart
        // from "something is actually firing it", which are very different
        // states and look identical everywhere else.
        ScheduleHeartbeat::record('agent:resolve-stale-commands');

        // Broadcasts (agent_token_id = null) are claimed by any live agent, so
        // they stay deliverable no matter how many tokens are revoked.
        //
        // "Not usable" has to be spelled out in SQL rather than delegated to
        // AgentApiToken::isUsable(), because that is the single definition the
        // middleware enforces: revoked *or* expired. Matching only `is_active`
        // left every command aimed at an expired token stuck pending forever —
        // the middleware rejects it 401 and the sweep kept re-creating scans for
        // it, so the row never drained and the badge never cleared.
        $stale = AgentCommand::query()
            ->where('status', 'pending')
            ->whereNotNull('agent_token_id')
            ->where('created_at', '<=', now()->subMinutes(self::GRACE_MINUTES))
            ->whereHas('token', fn ($q) => $q->where(function ($q) {
                $q->where('is_active', false)
                    ->orWhere(fn ($q) => $q->whereNotNull('expires_at')->where('expires_at', '<=', now()));
            }))
            ->orderBy('id')
            ->get();

        foreach ($stale as $command) {
            $command->update([
                'status' => 'failed',
                'executed_at' => now(),
                'result' => [
                    'message' => 'Not delivered: the agent token was revoked or had expired before it could poll '.
                        'this command. Remove the program from that machine manually.',
                ],
            ]);
        }

        if ($stale->isEmpty()) {
            $this->info('No undeliverable commands found.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Resolved %d undeliverable command(s): #%s',
            $stale->count(),
            $stale->pluck('id')->implode(', #')
        ));

        return self::SUCCESS;
    }
}
