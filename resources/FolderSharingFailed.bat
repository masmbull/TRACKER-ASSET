@echo off
:: Silent launcher. The old version self-elevated with Start-Process -Verb RunAs
:: (UAC prompt), used a menu and paused; none of that works for an unattended
:: agent. Run with "System" or "Administrator" in the Push Script modal.
powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "%~dp0FolderSharingFailed.ps1"
exit /b %errorlevel%
