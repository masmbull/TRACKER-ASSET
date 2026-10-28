@echo off
setlocal EnableExtensions
title MITOGROUP | IT Support Toolkit
color 0B
mode con: cols=82 lines=32 >nul 2>&1

:: Admin elevation - keep window open and relaunch through cmd.exe
net session >nul 2>&1
if errorlevel 1 (
    powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process -FilePath $env:ComSpec -ArgumentList @('/k',('""{0}""' -f '%~f0')) -Verb RunAs"
    exit /b
)

:menu
cls
echo.
echo   +----------------------------------------------------------------------------+
echo   ^|                                                                            ^|
echo   ^|   MITOGROUP                                      IT SUPPORT                ^|
echo   ^|   WORKPLACE TECHNOLOGY                           SERVICE TOOLKIT           ^|
echo   ^|                                                                            ^|
echo   +----------------------------------------------------------------------------+
echo   ^|                                                                            ^|
echo   ^|   PRINTER CARE                                                           ^|
echo   ^|   Diagnose common Windows printing problems and manage the print queue.    ^|
echo   ^|                                                                            ^|
echo   +----------------------------------------------------------------------------+
echo   ^|                                                                            ^|
echo   ^|    [1]  Restart Print Spooler                                              ^|
echo   ^|         Restart the Windows service that manages print jobs.              ^|
echo   ^|                                                                            ^|
echo   ^|    [2]  Clear Stuck Print Jobs                                             ^|
echo   ^|         Remove pending jobs from this computer's print queue.              ^|
echo   ^|                                                                            ^|
echo   ^|    [3]  Printer Settings                                                  ^|
echo   ^|         Open Windows printer configuration.                               ^|
echo   ^|                                                                            ^|
echo   ^|    [4]  Devices and Printers                                              ^|
echo   ^|         View installed printers and devices.                              ^|
echo   ^|                                                                            ^|
echo   ^|    [5]  Printer Status                                                    ^|
echo   ^|         Show installed printers and their Windows status.                 ^|
echo   ^|                                                                            ^|
echo   ^|    [6]  Network Quick Check                                               ^|
echo   ^|         Display this computer's IP configuration.                         ^|
echo   ^|                                                                            ^|
echo   ^|    [0]  Exit                                                              ^|
echo   ^|                                                                            ^|
echo   +----------------------------------------------------------------------------+
echo.
echo   MITOGROUP  /  IT SUPPORT  /  CREATED BY IT SUPPORT
echo.
set "choice="
set /p "choice=   Select an option [0-6] ^> "

if "%choice%"=="1" goto spooler
if "%choice%"=="2" goto clearjobs
if "%choice%"=="3" goto settings
if "%choice%"=="4" goto devices
if "%choice%"=="5" goto status
if "%choice%"=="6" goto network
if "%choice%"=="0" goto end

echo.
echo   [!] Invalid selection. Enter a number from 0 to 6.
timeout /t 2 >nul
goto menu

:spooler
cls
call :section "RESTART PRINT SPOOLER"
echo   This restarts the Windows service that handles print jobs.
echo.
choice /c YN /n /m "   Proceed? [Y/N] "
if errorlevel 2 goto menu
echo.
echo   [1/2] Stopping Print Spooler...
net stop spooler
echo.
echo   [2/2] Starting Print Spooler...
net start spooler
echo.
echo   Operation finished. Review the messages above for any errors.
call :return
goto menu

:clearjobs
cls
call :section "CLEAR STUCK PRINT JOBS"
echo   This deletes ALL pending print jobs on this computer.
echo   Jobs cannot be recovered after deletion.
echo.
choice /c YN /n /m "   Delete pending jobs? [Y/N] "
if errorlevel 2 goto menu
echo.
echo   [1/3] Stopping Print Spooler...
net stop spooler
if errorlevel 1 (
    echo.
    echo   [ERROR] The service could not be stopped. No queue files were deleted.
    call :return
    goto menu
)
echo.
echo   [2/3] Removing queued print files...
del /q /f "%SystemRoot%\System32\spool\PRINTERS\*.*" 2>nul
echo.
echo   [3/3] Starting Print Spooler...
net start spooler
echo.
echo   Queue cleanup finished. Check the messages above for errors.
call :return
goto menu

:settings
start "" ms-settings:printers
goto menu

:devices
start "" control.exe printers
goto menu

:status
cls
call :section "INSTALLED PRINTER STATUS"
echo   Reading printer information...
echo.
powershell -NoProfile -ExecutionPolicy Bypass -Command "Get-CimInstance Win32_Printer | Select-Object Name, PrinterStatus, WorkOffline, Default | Format-Table -AutoSize"
echo.
echo   Note: Windows-reported status may not match the printer's physical display.
call :return
goto menu

:network
cls
call :section "NETWORK QUICK CHECK"
echo   Showing IPv4 addresses, gateways, and DNS configuration.
echo.
ipconfig
echo.
call :return
goto menu

:section
echo.
echo   +----------------------------------------------------------------------------+
echo   ^|  MITOGROUP  /  IT SUPPORT                                                  ^|
echo   +----------------------------------------------------------------------------+
echo.
echo      %~1
echo   ----------------------------------------------------------------------------
echo.
exit /b

:return
echo.
echo   ----------------------------------------------------------------------------
pause
exit /b

:end
cls
echo.
echo   +----------------------------------------------------------------------------+
echo   ^|                                                                            ^|
echo   ^|   MITOGROUP  /  IT SUPPORT                                                 ^|
echo   ^|                                                                            ^|
echo   ^|   Thank you for using the Printer Care Toolkit.                            ^|
echo   ^|                                                                            ^|
echo   ^|   Created by IT Support                                                    ^|
echo   ^|                                                                            ^|
echo   +----------------------------------------------------------------------------+
echo.
timeout /t 2 >nul
endlocal
exit /b
