<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Command inbox for resident agents (PRD ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â§11).
 *
 * The agent polls GET /api/agent/inbox with its Bearer token, executes the
 * pending command, then acks it. The dashboard enqueues commands via the web
 * controllers (scan now / update all).
 */
class AgentCommandApiController extends Controller
{
    /**
     * Exact wording the agent sends in its uninstall ack. Only this string
     * revokes the token, so an agent too old to know the command (it acks
     * 'done' with no result at all) leaves the token alive for a real retry.
     */
    public const UNINSTALL_DONE_MARKER = 'Agent removed itself after receiving the uninstall command.';

    /**
     * The page and the inbox must agree on what "stuck" means.
     *
     * This used to be a second copy of the same number, kept in step by a
     * comment. Nothing tested that they matched, and a drift would have been
     * silent and lopsided: the page showing a command as stalled while the
     * server still considered it running, or the reverse. The value now lives on
     * the model, and the alias below exists only so the inbox query reads clearly.
     */
    public const IN_PROGRESS_GRACE_MINUTES = AgentCommand::STALL_GRACE_MINUTES;

    private const MAX_INBOX_WAIT_SECONDS = 55;

    public function inbox(Request $request)
    {
        $tokenId = (int) $request->attributes->get('agent_token_id');

        $commands = $this->pendingCommands($tokenId);

        // Optional long-poll: the agent asks to be parked here instead of asking
        // every ten seconds, and this waits for the first command to appear.
        //
        // Opt-in through a query parameter rather than the default, because it
        // costs a php-fpm worker for as long as it waits. An agent fleet that has
        // not been counted yet would otherwise park a worker per laptop on a pool
        // that used to hold five, and the site would go down instead of quiet.
        // Without ?wait= this returns exactly as before.
        //
        // 55 s matches what the agent asks for (AgentRunner.LongPollSeconds). The
        // cap used to sit at 5 s while the client requested 55, so every parked
        // request returned after five seconds and the long-poll degraded into a
        // five-second poll: twelve requests a minute per laptop instead of one.
        $wait = min((int) $request->integer('wait', 0), self::MAX_INBOX_WAIT_SECONDS);

        // The long-poll can wait up to 55 s, which exceeds the default 30 s
        // php-fpm limit. Extend it so the parked request does not time out.
        if ($wait > 0) {
            set_time_limit($wait + 10);
        }

        if ($commands->isEmpty() && $wait > 0) {
            $deadline = microtime(true) + $wait;

            do {
                sleep(1);

                $commands = $this->pendingCommands($tokenId);
            } while ($commands->isEmpty() && microtime(true) < $deadline);
        }

        return response()->json(['commands' => $commands]);
    }

    /**
     * Commands this device may run right now, including ones it stalled on.
     *
     * Split out of inbox() so the long-poll loop re-asks the identical question
     * rather than a second, subtly different copy of it - a variant that missed
     * the stall-recovery clause would hand the same dead command out forever.
     *
     * @return Collection<int, AgentCommand>
     */
    private function pendingCommands(int $tokenId)
    {
        $commands = AgentCommand::query()
            // A row the agent picked up and then died on would otherwise be lost
            // forever: it is no longer 'pending', so this query would never hand
            // it out again, and nothing else resets it. A command that has been
            // in_progress with no progress report for longer than the grace
            // window is therefore handed back out, which makes the failure self
            // -healing on the next poll rather than needing the scheduler.
            //
            // Two minutes is well clear of the slowest real step - the first
            // deep inventory runs a Windows Update search that took 30 s - so a
            // live agent is never interrupted and restarted mid-command.
            ->where(function ($query) {
                $query->where('status', 'pending')
                    ->orWhere(function ($query) {
                        $query->where('status', 'in_progress')
                            ->where(function ($query) {
                                $query->where('updated_at', '<=', now()->subMinutes(self::IN_PROGRESS_GRACE_MINUTES))
                                    ->orWhere(fn ($query) => $query->where('updated_at', null)->where('started_at', '<=', now()->subMinutes(self::IN_PROGRESS_GRACE_MINUTES)));
                            });
                    });
            })
            ->where(function ($query) use ($tokenId) {
                $query->whereNull('agent_token_id')
                    ->orWhere('agent_token_id', $tokenId);
            })
            // A targeted "remove this machine" command must never go out as a
            // broadcast. `agent_token_id` is nullOnDelete, so deleting a token
            // row silently rewrites its pending uninstall into a fleet-wide one
            // and every agent removes itself. Uninstall is only ever created
            // per device, so a null target is always corruption, not an intent.
            ->where(function ($query) {
                $query->where('command', '!=', 'uninstall')
                    ->orWhereNotNull('agent_token_id');
            })
            ->limit(10)
            ->get(['id', 'command', 'payload', 'agent_token_id']);

        // An update a device does not need is not free to skip: handing it out
        // makes the agent pull a ~160 MB binary and restart itself, so on a
        // company-wide click every laptop in the office would restart, including
        // the machines already carrying that exact build.
        //
        // Filtered here rather than by deleting rows, because a broadcast has no
        // target to edit - "cancel it for this device" is decided at the moment it
        // is handed over. A targeted update is left alone: an operator who
        // clicked Update on one machine meant that machine, and second-guessing
        // them would make the button unreliable.
        //
        // Only once: the version is read from the token rather than per command,
        // because isOutdated() compares against the release table and a fleet of
        // twenty would otherwise run that comparison twenty times per poll.
        $isCurrent = AgentApiToken::find($tokenId)?->isOutdated() === false;

        return $commands
            ->reject(fn ($command) => $command->command === 'update'
                && $command->agent_token_id === null
                && $isCurrent)
            ->values();
    }

    /**
     * Record where the agent has got to with a command it is running.
     *
     * The agent owns the vocabulary. It sends a short human sentence and a
     * percentage, and both are stored verbatim: a server-side list of permitted
     * stage names would have to be updated in lockstep with the agent, and the
     * failure mode of getting that wrong is a rejected progress report - which
     * looks exactly like a silent agent to the operator watching the page.
     *
     * Progress may arrive out of order or twice, so it is clamped rather than
     * trusted, and a settled command ignores further reports for the same reason
     * ack does: the recorded outcome is history and must not be rewritten by a
     * report that was already in flight.
     */
    public function progress(Request $request, AgentCommand $command)
    {
        $tokenId = (int) $request->attributes->get('agent_token_id');

        abort_unless($command->agent_token_id === null || $command->agent_token_id === $tokenId, 403);

        $validated = $request->validate([
            'stage' => 'nullable|string|max:255',
            'progress' => 'nullable|integer|min:0|max:100',
        ]);

        if ($command->status !== 'pending' && ! $command->isRunning()) {
            return response()->json([
                'ok' => true,
                'status' => $command->status,
                'message' => 'Command already settled; progress ignored.',
            ]);
        }

        $command->update([
            'status' => 'in_progress',
            'stage' => $validated['stage'] ?? $command->stage,
            'progress' => $validated['progress'] ?? $command->progress,
            // Set once, on the first report. Kept as the original start time
            // rather than refreshed, because "how long has this been stuck" is
            // measured from when the agent picked the work up, not from the last
            // time it happened to say something.
            'started_at' => $command->started_at ?? now(),
        ]);

        return response()->json(['ok' => true, 'status' => $command->status]);
    }

    /**
     * Report where an installation or update has got to.
     *
     * Install and update are not queued commands: the operator did not ask for
     * them from the page, the agent is performing them on a machine where nobody
     * is watching, and the only party that knows they happened is the agent
     * itself. They are still recorded as commands so the fleet page can render
     * every kind of device work the same way, and so a machine that dies
     * mid-install is visibly stuck rather than silently absent.
     *
     * One open row per device at a time. Re-running the installer has to adopt
     * the existing row instead of stacking a second one, otherwise the page
     * would show two half-finished installs and no way to tell which is real.
     */
    public function installProgress(Request $request)
    {
        $tokenId = (int) $request->attributes->get('agent_token_id');

        $validated = $request->validate([
            'command' => ['required', Rule::in(['install', 'update'])],
            'stage' => 'nullable|string|max:255',
            'progress' => 'nullable|integer|min:0|max:100',
            'status' => ['required', Rule::in(['in_progress', 'done', 'failed'])],
            'result' => 'nullable|array',
        ]);

        $command = AgentCommand::query()
            ->where('agent_token_id', $tokenId)
            ->whereIn('command', ['install', 'update'])
            ->whereIn('status', ['pending', 'in_progress'])
            ->orderByDesc('id')
            ->first();

        $attributes = [
            'status' => $validated['status'],
            'stage' => $validated['stage'] ?? null,
            'progress' => $validated['progress'] ?? null,
        ];

        if ($validated['status'] === 'in_progress') {
            $attributes['started_at'] = now();
        } else {
            // Settled: the progress numbers are noise from here on, and leaving
            // "Copying agent files (100%)" next to status done reads as a
            // command that never actually finished.
            $attributes['executed_at'] = now();
            $attributes['result'] = $validated['result'] ?? null;
            $attributes['progress'] = null;
        }

        if ($command) {
            $command->update($attributes);
        } else {
            // A terminal report for work that was never opened (the agent was
            // killed and restarted, or the install finished before its first
            // progress call) still gets a row, so "done" is not lost.
            $command = AgentCommand::create($attributes + [
                'agent_token_id' => $tokenId,
                'command' => $validated['command'],
            ]);
        }

        return response()->json(['ok' => true, 'id' => $command->id, 'status' => $command->status]);
    }

    public function ack(Request $request, AgentCommand $command)
    {
        $tokenId = (int) $request->attributes->get('agent_token_id');

        abort_unless($command->agent_token_id === null || $command->agent_token_id === $tokenId, 403);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['done', 'failed'])],
            'result' => 'nullable|array',
        ]);

        // A command is settled exactly once. Without this guard a late ack
        // silently overwrites the recorded outcome of a command that already
        // finished: a broadcast is acked by whichever agent answers first, so
        // a slow second agent reporting "failed" would replace a real "done".
        // The stale resolver can also flip a row to 'failed' while an agent is
        // mid-command, and that verdict must not be rewritten afterwards.
        //
        // 'in_progress' counts as unsettled. An agent that reports progress and
        // then acks is the normal path, and treating its own progress report as
        // a settlement would make every command with a progress bar fail to
        // close - the agent would report 100% and be told it had already
        // finished, so the row would sit at in_progress forever.
        if ($command->status !== 'pending' && ! $command->isRunning()) {
            return response()->json([
                'ok' => true,
                'status' => $command->status,
                'message' => 'Command already settled; ack ignored.',
            ]);
        }

        $command->update([
            'status' => $validated['status'],
            'result' => $validated['result'] ?? null,
            'executed_at' => now(),
            // Cleared on settle. "Copying agent files (100%)" sitting next to a
            // failed uninstall reads as a command that never actually finished,
            // and the stage text belongs to the run that just ended.
            'progress' => null,
            'stage' => null,
        ]);

        // Uninstall: burn the token, but only once the agent has actually
        // confirmed it removed itself. Revoking earlier (when the command is
        // queued) would break the very poll that has to deliver the command,
        // because AuthenticateAuditAgent rejects inactive tokens. The marker
        // keeps old agents - which ack 'done' without a result - from being
        // treated as a successful removal.
        if ($command->command === 'uninstall' && $tokenId !== 0) {
            $confirmed = ($validated['result']['message'] ?? null) === self::UNINSTALL_DONE_MARKER;

            if ($confirmed) {
                AgentApiToken::whereKey($tokenId)->update(['is_active' => false]);
            }
        }

        return response()->json(['ok' => true]);
    }
}
