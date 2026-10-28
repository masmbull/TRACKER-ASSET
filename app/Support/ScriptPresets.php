<?php

namespace App\Support;

/**
 * Ready-made scripts offered in the Push Script modal, so a routine job is one
 * click rather than a hand-paste.
 *
 * These are the one place the text lives: the modal renders them from here, so
 * editing the script here changes what every operator queues. They are written
 * to run non-interactively - no `set /p`, no `pause` - because the agent runs
 * them unattended, and a prompt would hang the command until its timeout.
 */
class ScriptPresets
{
    /**
     * Disable USB mass storage.
     *
     * USBSTOR Start=4 selects the "disabled" start type at boot, the same
     * registry change the Group Policy "All Removable Storage classes: Deny all
     * access" writes. Needs SYSTEM or admin, since HKLM is not writable by a
     * standard user.
     */
    public const DISABLE_USB = <<<'BAT'
@echo off
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERROR] This script must run as Administrator or SYSTEM.
    exit /b 1
)
echo Disabling USB storage...
reg add "HKLM\SYSTEM\CurrentControlSet\Services\USBSTOR" /v Start /t REG_DWORD /d 4 /f
if %errorlevel% equ 0 (
    echo [OK] USB storage has been disabled.
) else (
    echo [ERROR] Failed to disable USB storage.
    exit /b 1
)
BAT;

    /**
     * Re-enable USB mass storage, by returning USBSTOR to its default start type
     * (3 = manual / on demand). The inverse of DISABLE_USB.
     */
    public const ENABLE_USB = <<<'BAT'
@echo off
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERROR] This script must run as Administrator or SYSTEM.
    exit /b 1
)
echo Enabling USB storage...
reg add "HKLM\SYSTEM\CurrentControlSet\Services\USBSTOR" /v Start /t REG_DWORD /d 3 /f
if %errorlevel% equ 0 (
    echo [OK] USB storage has been enabled.
) else (
    echo [ERROR] Failed to enable USB storage.
    exit /b 1
)
BAT;

    /** Restart the Windows print queue service without prompting for input. */
    public const RESTART_PRINT_SPOOLER = <<<'BAT'
@echo off
net stop spooler
if errorlevel 1 (
    echo [ERROR] Could not stop the Print Spooler.
    exit /b 1
)
net start spooler
if errorlevel 1 (
    echo [ERROR] Could not start the Print Spooler.
    exit /b 1
)
echo [OK] Print Spooler restarted.
BAT;

    /** Clear pending print jobs only after the spooler has stopped successfully. */
    public const CLEAR_PRINT_QUEUE = <<<'BAT'
@echo off
net stop spooler
if errorlevel 1 (
    echo [ERROR] Could not stop the Print Spooler. No files were removed.
    exit /b 1
)
del /q /f "%SystemRoot%\System32\spool\PRINTERS\*.*"
net start spooler
if errorlevel 1 (
    echo [ERROR] Queue files were cleared, but the Print Spooler did not restart.
    exit /b 1
)
echo [OK] Pending print jobs cleared and Print Spooler restarted.
BAT;

    /** Read-only network summary based on the toolkit's ipconfig action. */
    public const NETWORK_QUICK_CHECK = <<<'BAT'
@echo off
ipconfig
BAT;
}
