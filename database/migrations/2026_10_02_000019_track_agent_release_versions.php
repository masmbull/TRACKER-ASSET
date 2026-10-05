<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track which agent build is released, and which build each device actually runs.
 *
 * Two separate questions were being answered with one number before this: what
 * the server is handing out, and what a given laptop is running. The fleet page
 * said nothing about either, so "Update All Agents" was a button with no
 * visible target - an operator could not tell whether half the fleet was on a
 * stale binary, or the current one, and could not tell whether an update had
 * landed before pushing it again.
 *
 * agent_releases is the server's side: one row per shipped build, exactly one
 * marked current. That is what the download button serves and what the "out of
 * date" badge compares against.
 *
 * agent_api_tokens.agent_version is the device's side, written by the agent
 * itself on every authenticated call. last_seen_at moves with it so "is this
 * device still answering" is one column rather than a second heartbeat table.
 *
 * Both are nullable throughout. Every device enrolled before this ran a binary
 * that never reported a version, and null means "unknown", not "outdated" -
 * the badge says unknown rather than inventing a version to compare.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_releases', function (Blueprint $table) {
            $table->id();
            $table->string('version')->unique();
            $table->string('notes')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamps();

            $table->index('is_current');
            $table->index('released_at');
        });

        Schema::table('agent_api_tokens', function (Blueprint $table) {
            $table->string('agent_version')->nullable()->after('platform');
            $table->timestamp('last_seen_at')->nullable()->after('agent_version');
            // The agent's own random id, kept so re-registration still matches
            // this row once device_id has been replaced by the server-assigned
            // sequential number. Without it, a reinstall would mint a second
            // device for the same laptop.
            $table->string('hardware_id')->nullable()->after('device_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('agent_api_tokens', function (Blueprint $table) {
            $table->dropColumn(['agent_version', 'last_seen_at', 'hardware_id']);
        });

        Schema::dropIfExists('agent_releases');
    }
};
