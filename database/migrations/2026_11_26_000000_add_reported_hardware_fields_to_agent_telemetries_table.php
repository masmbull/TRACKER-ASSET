<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Readings the hardware collector already reports on every audit and every
     * heartbeat, but which the telemetry validation whitelist used to discard.
     *
     * They used to survive only inside the JSON payload, where nothing read
     * them: the accessory fields left the UI's Accessories section permanently
     * blank, and storage type/capacity never reached the fleet detail panel.
     */
    public function up(): void
    {
        Schema::table('agent_telemetries', function (Blueprint $table): void {
            $table->string('storage_type', 255)->nullable()->after('disk_free_percent');
            $table->string('storage_capacity', 255)->nullable()->after('storage_type');
            $table->string('battery_status', 32)->nullable()->after('battery_cycle_count');
            $table->string('monitor_sn', 255)->nullable()->after('ram_config');
            $table->string('keyboard_model', 500)->nullable()->after('monitor_sn');
            $table->string('mouse_model', 500)->nullable()->after('keyboard_model');
        });
    }

    public function down(): void
    {
        Schema::table('agent_telemetries', function (Blueprint $table): void {
            $table->dropColumn([
                'storage_type', 'storage_capacity', 'battery_status',
                'monitor_sn', 'keyboard_model', 'mouse_model',
            ]);
        });
    }
};
