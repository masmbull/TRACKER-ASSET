<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Printers installed on a device.
 *
 * A child table of the fingerprint for the same reason agent_software is: the
 * list is something an operator actually queries ("who still has the old
 * label printer?", "which machines point at a printer that no longer exists?"),
 * and counting/filtering that needs rows rather than a JSON blob.
 *
 * The whole list is replaced on every report, exactly like the software list.
 * A diff would need a stable per-entry key the print spooler does not give us,
 * and a stale row is worse than a missing one: it would report a printer that
 * has been removed from the machine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_printers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_fingerprint_id')->constrained('agent_fingerprints')->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->string('name');
            $table->string('driver_name')->nullable();
            $table->string('port_name')->nullable();
            $table->string('location')->nullable();
            // A network printer is one that lives on a print server rather than
            // on this machine, which is what an operator asks about when they
            // ask "can this laptop still reach the printer?".
            $table->boolean('is_network')->default(false);
            $table->boolean('is_default')->default(false);
            // Windows reports "offline" for a printer it cannot reach. That is
            // the single most useful thing to know about a shared printer and
            // it is worth its own column rather than being folded into a
            // free-text status.
            $table->boolean('is_offline')->default(false);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();

            $table->index(['agent_fingerprint_id', 'name']);
            $table->index(['is_offline']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_printers');
    }
};
