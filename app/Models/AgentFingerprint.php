<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Deep inventory snapshot of one device (software, security posture, licensing).
 *
 * Separate from AgentTelemetry on purpose: telemetry is rewritten every 60
 * seconds by the heartbeat and holds only cheap readings, while this is walked
 * once at install and then at most daily because the software registry scan
 * costs seconds of WMI/registry work per device.
 */
class AgentFingerprint extends Model
{
    protected $fillable = [
        'agent_token_id', 'asset_id', 'device_id',
        'antivirus_product', 'antivirus_enabled', 'antivirus_signature_age_days',
        'firewall_enabled', 'firewall_profiles_off', 'uac_enabled', 'secure_boot_enabled',
        'tpm_present', 'bitlocker_status', 'bitlocker_method', 'rdp_enabled', 'local_admin_count',
        'auto_logon_enabled', 'screen_lock_enabled', 'lock_timeout_minutes', 'guest_account_enabled',
        'defender_realtime_enabled', 'defender_last_scan_days', 'hotfix_count',
        'windows_edition', 'license_channel', 'activation_status',
        'os_build', 'os_installed_at', 'last_boot_at', 'pending_reboot', 'domain', 'timezone',
        'last_hotfix_installed_at', 'windows_updates_pending', 'security_updates_pending',
        'updates_succeeded', 'updates_failed', 'security_updates_installed',
        'last_security_update_at',
        'disk_type', 'smart_health', 'smart_predicted_failure',
        'cpu_cores', 'cpu_physical_cores', 'cpu_architecture',
        'ram_modules', 'ram_slots', 'monitor', 'bios_version', 'bios_date',
        'chassis_type', 'device_model', 'screen_resolution',
        'software_count', 'startup_count',
        'payload', 'collected_at',
        // Accessories
        'monitor_sn', 'keyboard_model', 'mouse_model',
        'headset_model', 'webcam_model', 'other_accessories',
    ];

    protected function casts(): array
    {
        return [
            'antivirus_enabled' => 'boolean',
            'firewall_enabled' => 'boolean',
            'uac_enabled' => 'boolean',
            'secure_boot_enabled' => 'boolean',
            'tpm_present' => 'boolean',
            'rdp_enabled' => 'boolean',
            'pending_reboot' => 'boolean',
            'auto_logon_enabled' => 'boolean',
            'screen_lock_enabled' => 'boolean',
            'guest_account_enabled' => 'boolean',
            'defender_realtime_enabled' => 'boolean',
            'smart_predicted_failure' => 'boolean',
            'lock_timeout_minutes' => 'integer',
            'defender_last_scan_days' => 'integer',
            'hotfix_count' => 'integer',
            'windows_updates_pending' => 'integer',
            'security_updates_pending' => 'integer',
            'updates_succeeded' => 'integer',
            'updates_failed' => 'integer',
            'security_updates_installed' => 'integer',
            'antivirus_signature_age_days' => 'integer',
            'local_admin_count' => 'integer',
            'cpu_cores' => 'integer',
            'cpu_physical_cores' => 'integer',
            'ram_modules' => 'integer',
            'ram_slots' => 'integer',
            'software_count' => 'integer',
            'startup_count' => 'integer',
            'os_installed_at' => 'date',
            'last_boot_at' => 'datetime',
            'bios_date' => 'date',
            'last_hotfix_installed_at' => 'date',
            'last_security_update_at' => 'date',
            'payload' => 'array',
            'collected_at' => 'datetime',
        ];
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(AgentApiToken::class, 'agent_token_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function software(): HasMany
    {
        return $this->hasMany(AgentSoftware::class);
    }

    /**
     * Printers installed on the machine this report describes.
     */
    public function printers(): HasMany
    {
        return $this->hasMany(AgentPrinter::class);
    }

    /**
     * Findings an operator must act on, as short codes the fleet table can badge.
     *
     * A device that is simply offline is not listed here: that is a liveness
     * question answered by AgentTelemetry::isOnline, and mixing the two would
     * make "no findings" ambiguous between "healthy" and "never reported".
     */
    public function findings(): array
    {
        $findings = [];

        if ($this->antivirus_enabled === false) {
            $findings[] = ['code' => 'av_off', 'label' => 'Antivirus off', 'severity' => 'danger'];
        }

        // Signatures older than a week mean the machine is not actually being
        // updated, which is the failure mode that matters even when AV is "on".
        if ($this->antivirus_signature_age_days !== null && $this->antivirus_signature_age_days > 7) {
            $findings[] = ['code' => 'av_stale', 'label' => 'AV signatures '.$this->antivirus_signature_age_days.'d', 'severity' => 'warning'];
        }

        if ($this->firewall_enabled === false) {
            $findings[] = ['code' => 'fw_off', 'label' => 'Firewall off', 'severity' => 'danger'];
        }

        if ($this->bitlocker_status !== null && $this->bitlocker_status !== 'Enabled') {
            $findings[] = ['code' => 'bitlocker_off', 'label' => 'BitLocker off', 'severity' => 'danger'];
        }

        if ($this->smart_predicted_failure === true) {
            $findings[] = ['code' => 'smart_fail', 'label' => 'Disk failing', 'severity' => 'danger'];
        }

        if ($this->activation_status !== null && $this->activation_status !== 'Licensed') {
            $findings[] = ['code' => 'unlicensed', 'label' => 'Windows unlicensed', 'severity' => 'danger'];
        }

        if ($this->pending_reboot === true) {
            $findings[] = ['code' => 'pending_reboot', 'label' => 'Pending reboot', 'severity' => 'warning'];
        }

        if ($this->rdp_enabled === true) {
            $findings[] = ['code' => 'rdp_on', 'label' => 'RDP enabled', 'severity' => 'warning'];
        }

        return $findings;
    }
}
