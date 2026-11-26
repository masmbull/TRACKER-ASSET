@echo off
:: Silent launcher for the printer RPC policy fix. The old interactive menu
:: (choice / set /p / pause) is gone; progress goes to TrackerAgent\Logs.
powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "%~dp0PrinterSharingFailed.ps1"
exit /b %errorlevel%
