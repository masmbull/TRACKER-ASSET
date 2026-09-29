<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Progress reporting for long-running agent operations: install, uninstall, update.
 *
 * Until now a queued command was a static badge. "uninstall queued" looks identical
 * whether the agent picked it up two seconds ago, is halfway through deleting
 * files, is wedged on a locked binary, or never came back online at all - and an
 * operator has no way to tell those apart. The question "is it actually working?"
 * is the only question that matters while a fleet is being changed underneath
 * people, so the agent now reports where it is and the page shows it.
 *
 * status gains 'in_progress'. It is a distinct state rather than progress > 0
 * because "running" and "finished" are different claims: a 100% row that is
 * still 'in_progress' means the agent is on its last step, whereas 100% with
 * status done means it actually finished.
 *
 * stage is a short human sentence ("Copying agent files...") rather than an
 * enumerated step name. It is written by the agent, so a fixed vocabulary would
 * either lag behind the code or be rejected by validation the moment a step is
 * renamed. The server stores what it is given and shows it verbatim.
 *
 * started_at records when the agent first picked the command up, which is what
 * makes a stalled operation visible: progress that has not moved and a
 * started_at far in the past means the agent died mid-command, and the page says
 * so instead of showing a confident 45% forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_commands', function (Blueprint $table) {
            $table->unsignedTinyInteger('progress')->nullable()->after('status');
            $table->string('stage')->nullable()->after('progress');
            $table->timestamp('started_at')->nullable()->after('stage');
        });
    }

    public function down(): void
    {
        Schema::table('agent_commands', function (Blueprint $table) {
            $table->dropColumn(['progress', 'stage', 'started_at']);
        });
    }
};
