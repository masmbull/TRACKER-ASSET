<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RAM slot counts, so a register can answer "can this laptop take more memory?".
 *
 * The capacity figures already collected cannot answer it: two 8GB sticks and
 * one 16GB stick are indistinguishable in every total, yet the first has no
 * free slot and the second does. That question comes up constantly when
 * planning an upgrade, and without these columns the only way to find out is
 * to physically open the machine.
 *
 * ram_modules is the populated count (what is in it today), ram_slots the
 * board capacity (how many it could hold). Both nullable because firmware that
 * reports MemoryDevices = 0 gives us neither, and a null reads as "not asked"
 * rather than "zero".
 *
 * A separate migration rather than an edit to the create-table one: that has
 * already run on deployed databases, and changing a migration that has shipped
 * silently desynchronises any environment that ran the original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_fingerprints', function (Blueprint $table) {
            $table->unsignedTinyInteger('ram_modules')->nullable()->after('cpu_physical_cores');
            $table->unsignedTinyInteger('ram_slots')->nullable()->after('ram_modules');
        });
    }

    public function down(): void
    {
        Schema::table('agent_fingerprints', function (Blueprint $table) {
            $table->dropColumn(['ram_modules', 'ram_slots']);
        });
    }
};
