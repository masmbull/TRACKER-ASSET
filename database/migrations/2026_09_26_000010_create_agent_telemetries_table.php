<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Live Fleet telemetry (PRD §11): one snapshot row per agent/device,
        // refreshed on every scan, so the fleet view can show battery health,
        // disk space, RAM layout, Wi-Fi and the logged-in user without having
        // to re-read the (append-only) audit history.
        Schema::create('agent_telemetries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_token_id')->nullable()->constrained('agent_api_tokens')->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->string('device_id')->nullable()->index();
            $table->string('hostname')->nullable();
            $table->string('logged_in_user')->nullable();
            $table->string('os')->nullable();
            $table->string('ip_address')->nullable();

            $table->unsignedTinyInteger('battery_percent')->nullable();
            $table->unsignedTinyInteger('battery_health')->nullable();
            $table->unsignedInteger('battery_cycle_count')->nullable();

            $table->decimal('disk_free_gb', 10, 2)->nullable();
            $table->decimal('disk_total_gb', 10, 2)->nullable();
            $table->decimal('disk_free_percent', 5, 2)->nullable();

            $table->string('ram_config')->nullable();
            $table->string('gpu')->nullable();
            $table->string('wifi_ssid')->nullable();
            $table->unsignedTinyInteger('wifi_signal')->nullable();
            $table->decimal('uptime_hours', 10, 2)->nullable();

            $table->json('payload')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();

            $table->index(['agent_token_id', 'reported_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_telemetries');
    }
};
