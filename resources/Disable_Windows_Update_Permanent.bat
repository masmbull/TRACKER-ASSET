@echo off
:: Silent launcher. Usage: Disable_Windows_Update_Permanent.bat [restore]
:: Without an argument it disables the update services; "restore" reverts them.
:: No self-elevation, no menu, no pause, no blinking console output.
if /I "%~1"=="restore" (
    powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "%~dp0Restore_Windows_Update.ps1"
) else (
    powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "%~dp0Disable_Windows_Update_Permanent.ps1"
)
exit /b %errorlevel%
