<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the agent on this device runs with administrator rights.
 *
 * A machine-level script (USB disable, reg add HKLM) can only run from an agent
 * that is elevated or running as SYSTEM. Without this the fleet had no way to
 * tell a laptop that can do that from one that cannot - the operator found out
 * only after pushing a script and reading the failure. Nullable because older
 * agents report nothing: null means "unknown", not "not elevated".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_telemetries', function (Blueprint $table) {
            $table->boolean('is_elevated')->nullable()->after('logged_in_user');
        });
    }

    public function down(): void
    {
        Schema::table('agent_telemetries', function (Blueprint $table) {
            $table->dropColumn('is_elevated');
        });
    }
};
