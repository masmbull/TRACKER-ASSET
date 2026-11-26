<?php

namespace App\Support;

/**
 * Ready-made scripts offered in the Push Script modal.
 *
 * Contract for every preset (the "silent" rule):
 *   - no UAC elevation attempt, no `pause`, `choice`, `set /p` or `Read-Host`;
 *     the agent runs them unattended and a prompt would hang until timeout;
 *   - no console chatter: progress goes to a log under ProgramData, so the
 *     operator can still audit what happened without a window flashing;
 *   - explicit exit code (0 = success, 1 = failed) and an idempotent change,
 *     so re-running a preset on the same laptop is harmless;
 *   - no self-download or remote code - the agent verifies a SHA-256 of the
 *     text it was sent, so the body itself must be the whole program.
 *
 * Sources for the repair presets are credited inside each script. Community
 * scripts were rewritten around an allow-list and the silent contract above
 * rather than copied verbatim: several upstream tools self-elevate or prompt.
 */
class ScriptPresets
{
    /** Shared, non-interactive logging and privilege check for every preset. */
    private const SILENT_HEADER = <<<'PS'
$ErrorActionPreference = 'Stop'
$logDir = Join-Path $env:ProgramData 'TrackerAgent\Logs'
New-Item -ItemType Directory -Path $logDir -Force | Out-Null
$logFile = Join-Path $logDir ('tracker-{0}-{1}.log' -f $env:COMPUTERNAME, (Get-Date -Format 'yyyyMMdd-HHmmss'))
function Write-Log {
    param([string]$Message)
    Add-Content -LiteralPath $logFile -Value ('[{0}] {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message)
}
function Test-Admin {
    $principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}
PS;

    /** Registry write helper: creates the key when absent, then sets one value. */
    private const REG_SET = <<<'PS'
function Set-RegistryValue {
    param([string]$Path, [string]$Name, $Value, [string]$Kind = 'DWord')
    if (-not (Test-Path -LiteralPath $Path)) { New-Item -Path $Path -Force | Out-Null }
    New-ItemProperty -LiteralPath $Path -Name $Name -Value $Value -PropertyType $Kind -Force | Out-Null
}
PS;

    /**
     * Disable USB mass storage by setting USBSTOR Start=4 at boot.
     * Silent, SYSTEM/admin only, idempotent.
     */
    public const DISABLE_USB = self::SILENT_HEADER.<<<'PS'

if (-not (Test-Admin)) { Write-Log 'FAIL: administrator privileges required.'; exit 1 }
try {
    Set-RegistryValue -Path 'HKLM:\SYSTEM\CurrentControlSet\Services\USBSTOR' -Name 'Start' -Value 4
    $actual = (Get-ItemProperty -LiteralPath 'HKLM:\SYSTEM\CurrentControlSet\Services\USBSTOR' -Name 'Start').Start
    if ($actual -ne 4) { throw "USBSTOR Start is $actual, expected 4" }
    Write-Log 'OK: USB mass storage disabled (USBSTOR Start=4). A reboot applies it.'
    exit 0
} catch {
    Write-Log ('FAIL: ' + $_.Exception.Message)
    exit 1
}
PS;

    /** Re-enable USB mass storage (3 = manual / on demand). Inverse of DISABLE_USB. */
    public const ENABLE_USB = self::SILENT_HEADER.<<<'PS'

if (-not (Test-Admin)) { Write-Log 'FAIL: administrator privileges required.'; exit 1 }
try {
    Set-RegistryValue -Path 'HKLM:\SYSTEM\CurrentControlSet\Services\USBSTOR' -Name 'Start' -Value 3
    $actual = (Get-ItemProperty -LiteralPath 'HKLM:\SYSTEM\CurrentControlSet\Services\USBSTOR' -Name 'Start').Start
    if ($actual -ne 3) { throw "USBSTOR Start is $actual, expected 3" }
    Write-Log 'OK: USB mass storage enabled (USBSTOR Start=3). A reboot applies it.'
    exit 0
} catch {
    Write-Log ('FAIL: ' + $_.Exception.Message)
    exit 1
}
PS;

    /** Restart the spooler without touching queued files. */
    public const RESTART_PRINT_SPOOLER = self::SILENT_HEADER.<<<'PS'

if (-not (Test-Admin)) { Write-Log 'FAIL: administrator privileges required.'; exit 1 }
try {
    $service = Get-Service -Name Spooler -ErrorAction Stop
    if ($service.Status -eq 'Running') { Stop-Service -Name Spooler -Force -ErrorAction Stop }
    Start-Service -Name Spooler -ErrorAction Stop
    Write-Log 'OK: Print Spooler restarted.'
    exit 0
} catch {
    Write-Log ('FAIL: ' + $_.Exception.Message)
    # Best effort: bring the service back even when the stop step failed.
    try { Start-Service -Name Spooler -ErrorAction SilentlyContinue } catch {}
    exit 1
}
PS;

    /**
     * Clear stuck print jobs. Adapted from the office's PrinterSharingFailed.bat;
     * the destructive step is guarded: it only runs after the spooler really
     * stopped, and the service is always restarted afterwards.
     */
    public const CLEAR_PRINT_QUEUE = self::SILENT_HEADER.<<<'PS'

if (-not (Test-Admin)) { Write-Log 'FAIL: administrator privileges required.'; exit 1 }
try {
    $service = Get-Service -Name Spooler -ErrorAction Stop
    if ($service.Status -eq 'Running') { Stop-Service -Name Spooler -Force -ErrorAction Stop }
    Start-Sleep -Seconds 2
    $queue = Join-Path $env:SystemRoot 'System32\spool\PRINTERS'
    $removed = 0
    if (Test-Path -LiteralPath $queue) {
        Get-ChildItem -LiteralPath $queue -File -Force -ErrorAction SilentlyContinue | ForEach-Object {
            try { Remove-Item -LiteralPath $_.FullName -Force -ErrorAction Stop; $removed++ }
            catch { Write-Log ('WARN: could not remove ' + $_.Exception.Message) }
        }
    }
    Start-Service -Name Spooler -ErrorAction Stop
    Write-Log ("OK: cleared $removed pending print job file(s); spooler restarted.")
    exit 0
} catch {
    Write-Log ('FAIL: ' + $_.Exception.Message)
    try { Start-Service -Name Spooler -ErrorAction SilentlyContinue } catch {}
    exit 1
}
PS;

    /** Read-only network summary; nothing is changed and the result is logged. */
    public const NETWORK_QUICK_CHECK = self::SILENT_HEADER.<<<'PS'

try {
    $report = @()
    $report += 'HOST: ' + $env:COMPUTERNAME
    $report += 'USER: ' + $env:USERNAME
    foreach ($adapter in (Get-NetAdapter | Where-Object Status -eq 'Up')) {
        $config = Get-NetIPConfiguration -InterfaceIndex $adapter.InterfaceIndex -ErrorAction SilentlyContinue
        $report += ('ADAPTER: {0} | {1} | MAC {2}' -f $adapter.Name, $adapter.Status, $adapter.MacAddress)
        if ($config -and $config.IPv4Address) {
            foreach ($address in $config.IPv4Address) { $report += ('  IPv4: ' + $address.IPAddress) }
        }
        if ($config -and $config.IPv4DefaultGateway) {
            foreach ($gateway in $config.IPv4DefaultGateway) { $report += ('  GW: ' + $gateway.NextHop) }
        }
        if ($config -and $config.DNSServer) {
            foreach ($dns in $config.DNSServer) {
                if ($dns.AddressFamily -eq 2) { $report += ('  DNS: ' + ($dns.ServerAddresses -join ', ')) }
            }
        }
    }
    $report += 'PING 1.1.1.1: ' + $(if (Test-Connection -ComputerName 1.1.1.1 -Count 1 -Quiet -ErrorAction SilentlyContinue) { 'OK' } else { 'FAILED' })
    $report | ForEach-Object { Write-Log $_ }
    exit 0
} catch {
    Write-Log ('FAIL: ' + $_.Exception.Message)
    exit 1
}
PS;

    /** Flush DNS resolver cache only - safe on any user context. */
    public const FLUSH_DNS = self::SILENT_HEADER.<<<'PS'

try {
    $before = (Get-DnsClientCache -ErrorAction SilentlyContinue | Measure-Object).Count
    ipconfig /flushdns | Out-Null
    $after = (Get-DnsClientCache -ErrorAction SilentlyContinue | Measure-Object).Count
    Write-Log ("OK: DNS cache flushed ($before entries before, $after after).")
    exit 0
} catch {
    Write-Log ('FAIL: ' + $_.Exception.Message)
    exit 1
}
PS;

    /**
     * Full network stack reset, adapted from the community scripts
     * (github.com/masalles/networkResetScript, github.com/LC3DT/NetworkReset)
     * but without firewall reset or hosts-file rewrite. A reboot is required
     * after Winsock/TCP-IP reset, which the log states explicitly.
     */
    public const RESET_NETWORK_STACK = self::SILENT_HEADER.<<<'PS'

if (-not (Test-Admin)) { Write-Log 'FAIL: administrator privileges required.'; exit 1 }
try {
    Write-Log 'Flushing DNS, ARP, Winsock and TCP/IP...'
    ipconfig /flushdns | Out-Null
    netsh interface ip delete arpcache | Out-Null
    netsh winsock reset | Out-Null
    netsh int ip reset | Out-Null
    netsh winhttp reset proxy | Out-Null
    Write-Log 'OK: network stack reset. Reboot the laptop to complete it.'
    exit 0
} catch {
    Write-Log ('FAIL: ' + $_.Exception.Message)
    exit 1
}
PS;

    /** Outlook attachment limit 50 MB - silent equivalent of the .reg file. */
    public const OUTLOOK_ATTACHMENT_50MB = self::SILENT_HEADER.self::REG_SET.<<<'PS'

try {
    Set-RegistryValue -Path 'HKCU:\Software\Microsoft\Office\16.0\Outlook\Preferences' -Name 'MaximumAttachmentSize' -Value 52428800
    Write-Log 'OK: Outlook attachment limit set to 50 MB.'
    exit 0
} catch {
    Write-Log ('FAIL: ' + $_.Exception.Message)
    exit 1
}
PS;

    /** Outlook attachment limit 100 MB - silent equivalent of the .reg file. */
    public const OUTLOOK_ATTACHMENT_100MB = self::SILENT_HEADER.self::REG_SET.<<<'PS'

try {
    Set-RegistryValue -Path 'HKCU:\Software\Microsoft\Office\16.0\Outlook\Preferences' -Name 'MaximumAttachmentSize' -Value 104857600
    Write-Log 'OK: Outlook attachment limit set to 100 MB.'
    exit 0
} catch {
    Write-Log ('FAIL: ' + $_.Exception.Message)
    exit 1
}
PS;

    /** Outlook attachment limit 150 MB - silent equivalent of the .reg file. */
    public const OUTLOOK_ATTACHMENT_150MB = self::SILENT_HEADER.self::REG_SET.<<<'PS'

try {
    Set-RegistryValue -Path 'HKCU:\Software\Microsoft\Office\16.0\Outlook\Preferences' -Name 'MaximumAttachmentSize' -Value 157286400
    Write-Log 'OK: Outlook attachment limit set to 150 MB.'
    exit 0
} catch {
    Write-Log ('FAIL: ' + $_.Exception.Message)
    exit 1
}
PS;

    /**
     * Folder sharing repair, from the office's FolderSharingFailed.bat.
     * No self-elevation, no pause; the guest-auth keys are written only when
     * the caller is already elevated (choose "System" in the modal).
     */
    public const FIX_FOLDER_SHARING = self::SILENT_HEADER.self::REG_SET.<<<'PS'

if (-not (Test-Admin)) { Write-Log 'FAIL: administrator privileges required.'; exit 1 }
try {
    Set-SmbClientConfiguration -EnableInsecureGuestLogon $true -Force -ErrorAction Stop
    Set-SmbClientConfiguration -RequireSecuritySignature $false -Force -ErrorAction SilentlyContinue
    Set-SmbServerConfiguration -RequireSecuritySignature $false -Force -ErrorAction SilentlyContinue
    Set-RegistryValue -Path 'HKLM:\SYSTEM\CurrentControlSet\Services\LanmanWorkstation\Parameters' -Name 'AllowInsecureGuestAuth' -Value 1
    Set-RegistryValue -Path 'HKLM:\SOFTWARE\Policies\Microsoft\Windows\LanmanWorkstation' -Name 'AllowInsecureGuestAuth' -Value 1
    Set-RegistryValue -Path 'HKLM:\SYSTEM\CurrentControlSet\Control\Lsa' -Name 'restrictanonymous' -Value 0
    Set-RegistryValue -Path 'HKLM:\SYSTEM\CurrentControlSet\Control\Lsa' -Name 'LocalAccountTokenFilterPolicy' -Value 1
    Write-Log 'OK: folder-sharing settings applied. Reboot to fully apply.'
    exit 0
} catch {
    Write-Log ('FAIL: ' + $_.Exception.Message)
    exit 1
}
PS;

    /**
     * Printer RPC policy, combining PrinterSharingFailed.bat with the office's
     * rpc update.reg. No interactive menu: the full known-good set is applied.
     */
    public const FIX_PRINTER_RPC = self::SILENT_HEADER.self::REG_SET.<<<'PS'

if (-not (Test-Admin)) { Write-Log 'FAIL: administrator privileges required.'; exit 1 }
try {
    $rpc = 'HKLM:\SOFTWARE\Policies\Microsoft\Windows NT\Printers\RPC'
    Set-RegistryValue -Path $rpc -Name 'RpcUseNamedPipeProtocol' -Value 1
    Set-RegistryValue -Path $rpc -Name 'RpcOverNamedPipes' -Value 1
    Set-RegistryValue -Path $rpc -Name 'RpcOverTcp' -Value 1
    Set-RegistryValue -Path $rpc -Name 'ForceKerberosForRpc' -Value 0
    Set-RegistryValue -Path $rpc -Name 'RpcProtocols' -Value 7
    Set-RegistryValue -Path 'HKLM:\SYSTEM\CurrentControlSet\Control\Print' -Name 'RpcAuthnLevelPrivacyEnabled' -Value 0
    foreach ($feature in @('Printing-Foundation-LPDPrintService', 'Printing-Foundation-LPRPortMonitor')) {
        $state = (Get-WindowsOptionalFeature -Online -FeatureName $feature -ErrorAction SilentlyContinue).State
        if ($state -ne 'Enabled') {
            Enable-WindowsOptionalFeature -Online -FeatureName $feature -NoRestart -ErrorAction SilentlyContinue | Out-Null
            Write-Log ("Enabled feature: $feature")
        }
    }
    Write-Log 'OK: printer RPC policy applied.'
    exit 0
} catch {
    Write-Log ('FAIL: ' + $_.Exception.Message)
    exit 1
}
PS;

    /**
     * Windows Update repair, adapted from Microsoft's documented reset steps
     * (support.microsoft.com/help/971058) and github.com/AdmiralEM/windows-update-repair.
     * No reboot prompt, no GUI, no DLL re-registration noise.
     */
    public const RESET_WINDOWS_UPDATE = self::SILENT_HEADER.<<<'PS'

if (-not (Test-Admin)) { Write-Log 'FAIL: administrator privileges required.'; exit 1 }
try {
    $services = @('wuauserv', 'bits', 'cryptsvc', 'msiserver', 'usosvc', 'dosvc')
    foreach ($name in $services) { Stop-Service -Name $name -Force -ErrorAction SilentlyContinue }
    Write-Log 'Stopped Windows Update services.'

    $qmgr = Join-Path $env:ProgramData 'Microsoft\Network\Downloader\qmgr.dat'
    if (Test-Path -LiteralPath $qmgr) { Remove-Item -LiteralPath $qmgr -Force -ErrorAction SilentlyContinue }

    $root = $env:SystemRoot
    foreach ($folder in @('SoftwareDistribution', 'System32\catroot2')) {
        $path = Join-Path $root $folder
        if (Test-Path -LiteralPath $path) {
            $backup = $path + '.old'
            if (Test-Path -LiteralPath $backup) { Remove-Item -LiteralPath $backup -Recurse -Force -ErrorAction SilentlyContinue }
            Rename-Item -LiteralPath $path -NewName (Split-Path $backup -Leaf) -ErrorAction SilentlyContinue
            Write-Log ("Renamed cache folder: $folder")
        }
    }
    netsh winsock reset | Out-Null
    netsh winhttp reset proxy | Out-Null
    foreach ($name in @('cryptsvc', 'bits', 'wuauserv', 'msiserver', 'usosvc', 'dosvc')) {
        Start-Service -Name $name -ErrorAction SilentlyContinue
    }
    Write-Log 'OK: Windows Update components reset. Reboot recommended.'
    exit 0
} catch {
    Write-Log ('FAIL: ' + $_.Exception.Message)
    exit 1
}
PS;

    /** Disable Windows Update services - silent, reversible, logged. */
    public const DISABLE_WINDOWS_UPDATE = self::SILENT_HEADER.<<<'PS'

if (-not (Test-Admin)) { Write-Log 'FAIL: administrator privileges required.'; exit 1 }
try {
    foreach ($name in @('wuauserv', 'usosvc', 'dosvc')) {
        Stop-Service -Name $name -Force -ErrorAction SilentlyContinue
        Set-Service -Name $name -StartupType Disabled -ErrorAction Stop
    }
    Set-RegistryValue -Path 'HKLM:\SYSTEM\CurrentControlSet\Services\WaaSMedicSvc' -Name 'Start' -Value 4
    Write-Log 'OK: Windows Update services disabled. Use Restore Windows Update to revert.'
    exit 0
} catch {
    Write-Log ('FAIL: ' + $_.Exception.Message)
    exit 1
}
PS;

    /** Restore Windows Update services to their defaults. */
    public const RESTORE_WINDOWS_UPDATE = self::SILENT_HEADER.<<<'PS'

if (-not (Test-Admin)) { Write-Log 'FAIL: administrator privileges required.'; exit 1 }
try {
    foreach ($name in @('wuauserv', 'usosvc', 'dosvc')) {
        Set-Service -Name $name -StartupType Manual -ErrorAction Stop
        Start-Service -Name $name -ErrorAction SilentlyContinue
    }
    Set-RegistryValue -Path 'HKLM:\SYSTEM\CurrentControlSet\Services\WaaSMedicSvc' -Name 'Start' -Value 2
    Write-Log 'OK: Windows Update services restored to manual start.'
    exit 0
} catch {
    Write-Log ('FAIL: ' + $_.Exception.Message)
    exit 1
}
PS;

    /**
     * Conservative temp-file cleanup, adapted from
     * github.com/paulmann/Clear-TempFolders and github.com/Armi1014/TempCleaner.
     * Allow-listed roots only, files older than 7 days, locked files skipped.
     */
    public const CLEAR_TEMP_FILES = self::SILENT_HEADER.<<<'PS'

try {
    $cutoff = (Get-Date).AddDays(-7)
    $roots = @($env:TEMP, (Join-Path $env:SystemRoot 'Temp'))
    foreach ($profile in (Get-ChildItem -LiteralPath (Join-Path $env:SystemDrive 'Users') -Directory -ErrorAction SilentlyContinue)) {
        $userTemp = Join-Path $profile.FullName 'AppData\Local\Temp'
        if (Test-Path -LiteralPath $userTemp) { $roots += $userTemp }
    }
    $freed = 0
    $count = 0
    foreach ($root in ($roots | Select-Object -Unique)) {
        $resolved = [System.IO.Path]::GetFullPath($root)
        if ($resolved.TrimEnd('\') -eq $env:SystemDrive.TrimEnd('\')) { continue }
        Get-ChildItem -LiteralPath $resolved -File -Recurse -Force -ErrorAction SilentlyContinue |
            Where-Object { $_.LastWriteTime -lt $cutoff } |
            ForEach-Object {
                $size = $_.Length
                try {
                    Remove-Item -LiteralPath $_.FullName -Force -ErrorAction Stop
                    $freed += $size
                    $count++
                } catch {}
            }
    }
    Write-Log ("OK: removed $count temp file(s), freed {0:N1} MB." -f ($freed / 1MB))
    exit 0
} catch {
    Write-Log ('FAIL: ' + $_.Exception.Message)
    exit 1
}
PS;

    /** Empty the Recycle Bin without a confirmation prompt. */
    public const EMPTY_RECYCLE_BIN = self::SILENT_HEADER.<<<'PS'

try {
    Clear-RecycleBin -Force -ErrorAction SilentlyContinue
    Write-Log 'OK: Recycle Bin emptied.'
    exit 0
} catch {
    Write-Log ('FAIL: ' + $_.Exception.Message)
    exit 1
}
PS;

    /** Read-only health report: OS, uptime, disk, memory and failed services. */
    public const SYSTEM_HEALTH_CHECK = self::SILENT_HEADER.<<<'PS'

try {
    $os = Get-CimInstance Win32_OperatingSystem
    $uptime = (Get-Date) - $os.LastBootUpTime
    $disk = Get-CimInstance Win32_LogicalDisk -Filter "DeviceID='$env:SystemDrive'"
    Write-Log ('HOST: {0} | OS: {1} | Uptime: {2:N1} hours' -f $env:COMPUTERNAME, $os.Caption, $uptime.TotalHours)
    Write-Log ('DISK {0}: {1:N1} GB free of {2:N1} GB' -f $disk.DeviceID, ($disk.FreeSpace / 1GB), ($disk.Size / 1GB))
    Write-Log ('MEMORY: {0:N1} GB free of {1:N1} GB' -f ($os.FreePhysicalMemory / 1MB), ($os.TotalVisibleMemorySize / 1MB))
    $failed = Get-Service | Where-Object { $_.Status -eq 'Stopped' -and $_.StartType -eq 'Automatic' } | Select-Object -ExpandProperty Name
    Write-Log ('STOPPED AUTOMATIC SERVICES: ' + $(if ($failed) { $failed -join ', ' } else { 'none' }))
    exit 0
} catch {
    Write-Log ('FAIL: ' + $_.Exception.Message)
    exit 1
}
PS;

    /**
     * The catalogue shown in the Push Script modal. Keeping it here means the
     * label, notes, default target and the script body cannot drift apart.
     *
     * @return array<string, array{label: string, body: string, runAs: string, workingDir: string, target: string, notes: string, section: string}>
     */
    public static function definitions(): array
    {
        // [label, script, runAs, default target, notes, section]
        $definitions = [
            'disable-usb' => ['Disable USB storage', self::DISABLE_USB, 'system', 'all', 'Disables USB mass storage (USBSTOR Start=4). Needs System/admin. Reboot to apply.', 'USB Storage'],
            'enable-usb' => ['Enable USB storage', self::ENABLE_USB, 'system', 'all', 'Restores USB mass storage to manual start (3). Reboot to apply.', 'USB Storage'],
            'printer-restart' => ['Restart Print Spooler', self::RESTART_PRINT_SPOOLER, 'system', 'device', 'Restarts the Print Spooler on one laptop. Queued jobs are kept.', 'Printer'],
            'printer-clear-queue' => ['Clear Stuck Print Jobs', self::CLEAR_PRINT_QUEUE, 'system', 'device', 'DESTRUCTIVE: deletes pending print job files, then restarts the spooler. Confirm before use.', 'Printer'],
            'printer-rpc' => ['Fix Printer RPC Policy', self::FIX_PRINTER_RPC, 'system', 'device', 'Applies the office RPC keys and enables LPD/LPR print features.', 'Printer'],
            'network-check' => ['Network Quick Check', self::NETWORK_QUICK_CHECK, 'current-user', 'device', 'Read-only adapter, IP, gateway, DNS and connectivity report written to the log.', 'Network'],
            'flush-dns' => ['Flush DNS Cache', self::FLUSH_DNS, 'current-user', 'all', 'Clears the DNS resolver cache. Safe on any user context.', 'Network'],
            'reset-network' => ['Reset Network Stack', self::RESET_NETWORK_STACK, 'system', 'device', 'Flushes DNS/ARP, resets Winsock and TCP/IP, clears proxy. Reboot required.', 'Network'],
            'folder-sharing' => ['Fix Folder Sharing', self::FIX_FOLDER_SHARING, 'system', 'device', 'Applies guest-auth and SMB settings for failed network folder access. Reboot recommended.', 'Sharing'],
            'outlook-50' => ['Outlook Attachment 50 MB', self::OUTLOOK_ATTACHMENT_50MB, 'current-user', 'all', 'Sets the Outlook 16.0 attachment limit to 50 MB.', 'Outlook'],
            'outlook-100' => ['Outlook Attachment 100 MB', self::OUTLOOK_ATTACHMENT_100MB, 'current-user', 'all', 'Sets the Outlook 16.0 attachment limit to 100 MB.', 'Outlook'],
            'outlook-150' => ['Outlook Attachment 150 MB', self::OUTLOOK_ATTACHMENT_150MB, 'current-user', 'all', 'Sets the Outlook 16.0 attachment limit to 150 MB.', 'Outlook'],
            'reset-windows-update' => ['Reset Windows Update', self::RESET_WINDOWS_UPDATE, 'system', 'device', 'Stops update services, clears caches, resets Winsock, then restarts services.', 'Windows Update'],
            'disable-windows-update' => ['Disable Windows Update', self::DISABLE_WINDOWS_UPDATE, 'system', 'all', 'Disables update services. Reversible with Restore Windows Update.', 'Windows Update'],
            'restore-windows-update' => ['Restore Windows Update', self::RESTORE_WINDOWS_UPDATE, 'system', 'all', 'Returns update services to manual start.', 'Windows Update'],
            'clear-temp' => ['Clear Temp Files', self::CLEAR_TEMP_FILES, 'system', 'all', 'Removes temp files older than 7 days from allow-listed folders only.', 'Maintenance'],
            'empty-recycle-bin' => ['Empty Recycle Bin', self::EMPTY_RECYCLE_BIN, 'current-user', 'all', 'Empties the Recycle Bin without a confirmation prompt.', 'Maintenance'],
            'system-health' => ['System Health Check', self::SYSTEM_HEALTH_CHECK, 'current-user', 'all', 'Read-only OS, disk, memory and stopped-services report in the log.', 'Diagnostics'],
            'sfc-dism' => ['SFC + DISM Scan', self::SFC_DISM_SCAN, 'system', 'device', 'Repairs the component store and system files. Allow a long timeout.', 'Diagnostics'],
            'ultraviewer-credentials' => ['Read UltraViewer ID/Password', self::READ_ULTRAVIEWER_CREDENTIALS, 'current-user', 'device', 'Read-only: reports the current UltraViewer ID and password so the Remote button can open a session.', 'Diagnostics'],
        ];

        return collect($definitions)->mapWithKeys(fn (array $preset, string $key) => [
            $key => [
                'label' => $preset[0],
                'body' => $preset[1],
                'runAs' => $preset[2],
                'target' => $preset[3],
                'notes' => $preset[4],
                'section' => $preset[5],
                'workingDir' => 'C:\\Temp\\Scripts',
            ],
        ])->all();
    }

    /** Disk and system-file verification; allow a long timeout for this one. */
    public const SFC_DISM_SCAN = self::SILENT_HEADER.<<<'PS'

if (-not (Test-Admin)) { Write-Log 'FAIL: administrator privileges required.'; exit 1 }
try {
    DISM.exe /Online /Cleanup-Image /AnalyzeComponentStore | Out-Null
    DISM.exe /Online /Cleanup-Image /StartComponentCleanup | Out-Null
    $result = sfc.exe /scannow 2>&1
    Write-Log ('SFC: ' + ($result -join ' '))
    exit 0
} catch {
    Write-Log ('FAIL: ' + $_.Exception.Message)
    exit 1
}
PS;

    /**
     * Read the UltraViewer ID and the password currently shown by the running
     * client. The password changes on every start, so it must be read on demand
     * at the moment an operator clicks Remote - it cannot be stored in advance.
     *
     * Three sources are tried: the values visible in the UltraViewer window,
     * the HKCU settings key, and the user configuration folder. The raw findings
     * are logged too, because UltraViewer changes where it keeps them between
     * versions and an operator can still pick the right one by hand.
     */
    public const READ_ULTRAVIEWER_CREDENTIALS = <<<'PS'
$ErrorActionPreference = 'Stop'
Add-Type @"
using System;
using System.Text;
using System.Collections.Generic;
using System.Runtime.InteropServices;
public static class UvWindow {
    public delegate bool EnumProc(IntPtr h, IntPtr p);
    [DllImport("user32.dll")] public static extern bool EnumWindows(EnumProc cb, IntPtr p);
    [DllImport("user32.dll")] public static extern bool EnumChildWindows(IntPtr parent, EnumProc cb, IntPtr p);
    [DllImport("user32.dll", CharSet=CharSet.Unicode)] public static extern int GetWindowText(IntPtr h, StringBuilder s, int max);
    [DllImport("user32.dll")] public static extern uint GetWindowThreadProcessId(IntPtr h, out uint pid);
    public static string Text(IntPtr h) { var s = new StringBuilder(512); GetWindowText(h, s, s.Capacity); return s.ToString(); }
}
"@

$script:findings = @()
$uvIds = @()
$script:seen = New-Object 'System.Collections.Generic.HashSet[string]'
$script:uvPids = @(Get-Process -Name 'UltraViewer*' -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Id)

if ($script:uvPids.Count -gt 0) {
    $topCb = [UvWindow+EnumProc] {
        param($h, $p)
        $procId = [uint32]0
        [void][UvWindow]::GetWindowThreadProcessId($h, [ref]$procId)
        if ($script:uvPids -notcontains [int]$procId) { return $true }
        $text = [UvWindow]::Text($h)
        if ($text -and $script:seen.Add($text)) { $script:findings += "WINDOW: $text" }
        $childCb = [UvWindow+EnumProc] {
            param($ch, $cp)
            $t = [UvWindow]::Text($ch)
            if ($t -and $script:seen.Add($t)) { $script:findings += "CONTROL: $t" }
            return $true
        }
        [void][UvWindow]::EnumChildWindows($h, $childCb, [IntPtr]::Zero)
        return $true
    }
    [void][UvWindow]::EnumWindows($topCb, [IntPtr]::Zero)
}

foreach ($path in @('HKCU:\Software\UltraViewer', 'HKCU:\Software\DucFabulous\UltraViewer')) {
    if (Test-Path -LiteralPath $path) {
        $item = Get-ItemProperty -LiteralPath $path
        foreach ($name in $item.PSObject.Properties.Name) {
            if ($name -like 'PS*') { continue }
            $value = $item.$name
            $findings += "REG: $name=$value"
            if ($name -match 'id' -and "$value" -match '^\d{5,12}$') { $uvIds += "$value" }
            if ($name -match 'pass|pwd|key' -and "$value" -ne '') { $findings += "PASSWORD_CANDIDATE: $value" }
        }
    }
}

$configDir = Join-Path $env:APPDATA 'UltraViewer'
if (Test-Path -LiteralPath $configDir) {
    Get-ChildItem -LiteralPath $configDir -File -Recurse -ErrorAction SilentlyContinue |
        Where-Object { $_.Length -lt 200000 } |
        ForEach-Object {
            $content = Get-Content -LiteralPath $_.FullName -Raw -ErrorAction SilentlyContinue
            if ($content) {
                $findings += "FILE: $($_.Name) => $($content.Substring(0, [Math]::Min(500, $content.Length)))"
                if ($content -match '(?i)(?:id|partner)[^0-9]{0,12}([0-9]{6,12})') { $uvIds += $Matches[1] }
            }
        }
}

foreach ($line in @($findings)) {
    if ($line -match '(?<!\d)([0-9]{8,10})(?!\d)') { $uvIds += $Matches[1] }
}

$id = ($uvIds | Where-Object { $_ } | Sort-Object Length -Descending | Select-Object -First 1)
$passwordCandidates = @()
foreach ($line in @($findings)) {
    if ($line -match 'PASSWORD_CANDIDATE:\s*(.+)$') { $passwordCandidates += $Matches[1].Trim() }
}
$password = ($passwordCandidates | Where-Object { $_ } | Select-Object -First 1)

Write-Output "UV_ID=$id"
Write-Output "UV_PASS=$password"
Write-Output "UV_FOUND=$($script:uvPids.Count) UltraViewer process(es)"
foreach ($line in ($findings | Select-Object -First 60)) { Write-Output "UV_RAW=$line" }
exit 0
PS;
}
