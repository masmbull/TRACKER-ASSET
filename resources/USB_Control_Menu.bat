@echo off
:: Silent launcher. Usage: USB_Control_Menu.bat [Disable|Enable] - default Disable.
:: No menu, no pause, no UAC prompt; pass "System" in the Push Script modal.
set "ACTION=%~1"
if "%ACTION%"=="" set "ACTION=Disable"
powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "%~dp0USB_Control.ps1" -Action %ACTION%
exit /b %errorlevel%
