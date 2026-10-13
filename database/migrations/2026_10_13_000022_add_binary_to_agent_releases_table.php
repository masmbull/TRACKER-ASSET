<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give each release its own binary, instead of one mutable file on disk.
 *
 * The releases table recorded *which version* was current but never held the
 * bytes: every download served the single storage/app/agent/TrackerAgent.exe,
 * overwritten in place by the last build. That made "activate an older release"
 * impossible (there was nothing to activate), made a rollback a manual file
 * copy, and left no way to tell a shipped binary from a half-written one - the
 * download route served whatever happened to be at that path.
 *
 * These columns are nullable because every release row that predates this
 * migration has no file. A row without a file keeps working the old way: the
 * download route falls back to that legacy path, so nothing already in
 * production breaks the day this runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_releases', function (Blueprint $table) {
            // Path relative to storage/app, e.g. agent/releases/1.3.2-9f1c2b.exe.
            $table->string('file_path')->nullable()->after('notes');
            $table->unsignedBigInteger('file_size')->nullable()->after('file_path');
            // Hex SHA-256 of the exact bytes served. Recorded for integrity and
            // display; a future agent build can verify it after download.
            $table->string('sha256', 64)->nullable()->after('file_size');
        });
    }

    public function down(): void
    {
        Schema::table('agent_releases', function (Blueprint $table) {
            $table->dropColumn(['file_path', 'file_size', 'sha256']);
        });
    }
};
