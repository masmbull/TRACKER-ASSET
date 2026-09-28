<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns the agent never had a collector for, plus the security readings that
 * decide whether a laptop is a lost-device risk.
 *
 * Two separate problems are fixed by this one migration.
 *
 * First: secure_boot_enabled and bitlocker_method already existed as columns and
 * were already read by the detail page, but no collector anywhere in the agent
 * produced either value. They could only ever be NULL. A security panel showing
 * a permanent blank for Secure Boot is worse than one that omits it, because a
 * blank reads as "unknown" when the truth is "never asked" - and for a stolen
 * laptop that distinction is the whole point of the field. Collectors for both
 * now exist; this migration only has to make room for the values that need new
 * columns.
 *
 * Second: the genuinely new readings. Each one answers a question an operator
 * actually asks and that nothing collected so far could answer.
 *
 *  - auto_logon_enabled / passwordless accounts: a machine that signs in by
 *    itself holds an unlocked disk on every reboot.
 *  - screen_lock_enabled / lock_timeout_minutes: a stolen laptop that never locks
 *    is the classic fleet loss, and "BitLocker on" does not help while somebody
 *    is logged in.
 *  - guest_account_enabled: Guest is off by default in Pro and on in Home, so
 *    this is the machine where anyone can browse without a password.
 *  - defender_realtime_enabled / defender_last_scan_days: antivirus_enabled
 *    already exists but covers the whole product; real-time protection is the
 *    switch that can be on while the engine is effectively inert, and a machine
 *    that has never run a scan is a different risk from one that scans daily.
 *  - windows_updates_pending / last_hotfix_installed_at: patch state was
 *    previously inferable only from a pending-reboot flag, which says nothing
 *    about how far behind the machine is.
 *  - bios_version / bios_date: firmware age decides whether a machine is
 *    eligible for modern security features at all, and is needed before any
 *    Secure Boot reading can be interpreted.
 *  - chassis_type / device_model: register data that has to be typed by hand
 *    today; reading it removes a transcription step.
 *  - screen_resolution: fleet sizing and remote-support triage.
 *  - cpu_architecture: 32-bit builds cannot run the modern security features, so
 *    the reading is what qualifies a machine for remediation.
 *  - office_licensing: an unlicensed Office on a licensed Windows is the single
 *    most common licensing problem in a business fleet.
 *
 * Everything is nullable. A null means "this agent did not report it", which is
 * a different fact from "reported as no" - the distinction is what makes it safe
 * to roll this out to a fleet whose agents are a mix of versions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_fingerprints', function (Blueprint $table) {
            // --- Sign-in and lock posture ------------------------------------
            $table->boolean('auto_logon_enabled')->nullable()->after('rdp_enabled');
            $table->boolean('screen_lock_enabled')->nullable()->after('auto_logon_enabled');
            $table->unsignedSmallInteger('lock_timeout_minutes')->nullable()->after('screen_lock_enabled');
            $table->boolean('guest_account_enabled')->nullable()->after('lock_timeout_minutes');

            // --- Defender detail, beyond the single antivirus_enabled flag ----
            $table->boolean('defender_realtime_enabled')->nullable()->after('antivirus_signature_age_days');
            $table->unsignedSmallInteger('defender_last_scan_days')->nullable()->after('defender_realtime_enabled');

            // --- Patch posture -----------------------------------------------
            $table->unsignedSmallInteger('windows_updates_pending')->nullable()->after('pending_reboot');
            $table->date('last_hotfix_installed_at')->nullable()->after('windows_updates_pending');

            // --- Firmware / chassis ------------------------------------------
            $table->string('bios_version')->nullable()->after('monitor');
            $table->date('bios_date')->nullable()->after('bios_version');
            $table->string('chassis_type')->nullable()->after('bios_date');
            $table->string('device_model')->nullable()->after('chassis_type');
            $table->string('screen_resolution')->nullable()->after('device_model');
            $table->string('cpu_architecture')->nullable()->after('cpu_physical_cores');

            // Hotfix count, the applied side of patch state. The agent collects
            // it next to last_hotfix_installed_at and the two only make sense
            // together: the count says how much has been applied, the date says
            // how recently the last of it landed.
            $table->unsignedSmallInteger('hotfix_count')->nullable()->after('last_hotfix_installed_at');
        });
    }

    public function down(): void
    {
        Schema::table('agent_fingerprints', function (Blueprint $table) {
            $table->dropColumn([
                'auto_logon_enabled', 'screen_lock_enabled', 'lock_timeout_minutes',
                'guest_account_enabled', 'defender_realtime_enabled', 'defender_last_scan_days',
                'windows_updates_pending', 'last_hotfix_installed_at', 'hotfix_count',
                'bios_version', 'bios_date', 'chassis_type', 'device_model',
                'screen_resolution', 'cpu_architecture',
            ]);
        });
    }
};
