<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The device's own MAC address, so the server can wake it over the network.
 *
 * Kept on the token rather than only on the telemetry row because a wake-on-LAN
 * packet is addressed to the NIC, not to a session: the machine has to be
 * reachable even when nothing about it has been reported for days. The
 * telemetry row is rewritten on every heartbeat and is therefore only as
 * current as the last one that landed, while the token row is the device's
 * standing identity and survives an agent that has been off for a week.
 *
 * Null means "never reported": an agent old enough not to send this, or one
 * whose network adapter would not answer the query. It is not the same as an
 * empty string and is not stored as one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_api_tokens', function (Blueprint $table) {
            $table->string('mac_address')->nullable()->after('hardware_id');
        });
    }

    public function down(): void
    {
        Schema::table('agent_api_tokens', function (Blueprint $table) {
            $table->dropColumn('mac_address');
        });
    }
};
