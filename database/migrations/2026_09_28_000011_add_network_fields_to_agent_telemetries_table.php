<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ethernet was never reported.
 *
 * The agent only read `netsh wlan show interfaces`, which only ever describes
 * wireless. A laptop on a wired connection produced no SSID, so the fleet page
 * fell through to "no adapter data" and the operator could not tell whether the
 * machine was offline or simply not on Wi-Fi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_telemetries', function (Blueprint $table) {
            // 'wifi' | 'ethernet' | 'virtual' | null when nothing is up.
            $table->string('network_type')->nullable()->after('wifi_signal');
            // The adapter actually carrying traffic, e.g. "Realtek PCIe GbE".
            $table->string('network_adapter')->nullable()->after('network_type');
            $table->string('network_speed')->nullable()->after('network_adapter');
        });
    }

    public function down(): void
    {
        Schema::table('agent_telemetries', function (Blueprint $table) {
            $table->dropColumn(['network_type', 'network_adapter', 'network_speed']);
        });
    }
};
