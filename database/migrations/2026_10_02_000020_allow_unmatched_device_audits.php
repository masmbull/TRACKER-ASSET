<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a scan belong to a device that is not an asset yet.
 *
 * An audit used to be forced to name an asset, and the only way to satisfy that
 * was to invent one: any laptop whose serial was not on file became an Asset
 * the moment it scanned. The register - the thing the whole product is a record
 * of - filled with rows nobody entered, carrying guesses for company, category
 * and location, and no sign that said so.
 *
 * asset_id becomes nullable and agent_token_id is added, so a scan can say
 * "this is MITO-0007 and here is what it is" without becoming an asset. The row
 * waits on the fleet page, holding its hardware readings, until somebody decides
 * it is real - which is the only person who can supply the fields a scan cannot
 * know: who uses it, what it is for, where it lives.
 *
 * Existing rows are untouched: every audit already has an asset, and the agent
 * that writes one for an unmatched device records the token instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_audits', function (Blueprint $table) {
            $table->foreignId('asset_id')
                ->nullable()
                ->change();

            // Which device reported it. Without this a scan with no asset could
            // only be found again by guessing at a serial number, and the fleet
            // page needs to list the ones still waiting to be promoted.
            $table->foreignId('agent_token_id')
                ->nullable()
                ->after('asset_id')
                ->constrained('agent_api_tokens')
                ->nullOnDelete();

            $table->index(['agent_token_id', 'audit_date']);
        });
    }

    public function down(): void
    {
        Schema::table('asset_audits', function (Blueprint $table) {
            // Drop the orphans first: restoring the NOT NULL would fail on any
            // row still waiting to be promoted, and the migration should say so
            // rather than the next deploy.
            DB::table('asset_audits')->whereNull('asset_id')->delete();

            $table->dropIndex(['agent_token_id', 'audit_date']);
            $table->dropConstrainedForeignId('agent_token_id');
            $table->foreignId('asset_id')->nullable(false)->change();
        });
    }
};
