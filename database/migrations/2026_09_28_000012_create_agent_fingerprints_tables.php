<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deep inventory, split away from agent_telemetries on purpose.
 *
 * agent_telemetries is refreshed every 60 seconds by the daemon heartbeat and
 * holds only cheap readings. Everything collected here costs seconds of WMI
 * and registry walking: the installed-software list alone is hundreds of
 * entries. Shipping that every minute would slow the heartbeat across the whole
 * fleet and bloat one of the busiest tables in the database, so the agent posts
 * it once at install and then at most once a day.
 *
 * agent_software is a child table rather than a JSON column because the list is
 * the data an operator actually queries ("which laptops still have Adobe?",
 * "who is on the old VPN client?"), and counting/filtering needs rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_fingerprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_token_id')->nullable()->constrained('agent_api_tokens')->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->string('device_id')->nullable()->index();

            // --- Security posture -------------------------------------------------
            $table->string('antivirus_product')->nullable();
            $table->boolean('antivirus_enabled')->nullable();
            $table->unsignedSmallInteger('antivirus_signature_age_days')->nullable();
            $table->boolean('firewall_enabled')->nullable();
            $table->string('firewall_profiles_off')->nullable();
            $table->boolean('uac_enabled')->nullable();
            $table->boolean('secure_boot_enabled')->nullable();
            $table->boolean('tpm_present')->nullable();
            $table->string('bitlocker_status')->nullable();
            $table->string('bitlocker_method')->nullable();
            $table->boolean('rdp_enabled')->nullable();
            $table->unsignedSmallInteger('local_admin_count')->nullable();

            // --- Licensing -------------------------------------------------------
            $table->string('windows_edition')->nullable();
            $table->string('license_channel')->nullable();
            $table->string('activation_status')->nullable();

            // --- OS state --------------------------------------------------------
            $table->string('os_build')->nullable();
            $table->date('os_installed_at')->nullable();
            $table->timestamp('last_boot_at')->nullable();
            $table->boolean('pending_reboot')->nullable();
            $table->string('domain')->nullable();
            $table->string('timezone')->nullable();

            // --- Storage / capacity ---------------------------------------------
            $table->string('disk_type')->nullable();
            $table->string('smart_health')->nullable();
            $table->boolean('smart_predicted_failure')->nullable();
            $table->unsignedSmallInteger('cpu_cores')->nullable();
            $table->unsignedSmallInteger('cpu_physical_cores')->nullable();
            $table->string('monitor')->nullable();

            // --- Inventory counts ------------------------------------------------
            $table->unsignedInteger('software_count')->nullable();
            $table->unsignedInteger('startup_count')->nullable();

            $table->json('payload')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->timestamps();

            $table->index(['agent_token_id', 'collected_at']);
        });

        Schema::create('agent_software', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_fingerprint_id')->constrained('agent_fingerprints')->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->string('name');
            $table->string('version')->nullable();
            $table->string('publisher')->nullable();
            $table->date('installed_at')->nullable();
            $table->timestamps();

            $table->index(['name']);
            $table->index(['agent_fingerprint_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_software');
        Schema::dropIfExists('agent_fingerprints');
    }
};
