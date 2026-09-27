@echo off
REM --------------------------------------------------------------------------
REM  Build TrackerAgent.exe - run from the agent/TrackerAgent directory
REM  Requires: .NET 8 SDK (https://dotnet.microsoft.com/download/dotnet/8.0)
REM --------------------------------------------------------------------------

echo [*] Building TrackerAgent.exe (single-file, self-contained)...
REM Single-file, self-contained. Two flags below are REQUIRED on SDK 8.0.425:
REM  -p:EnableCompressionInSingleFile=true breaks the bundle: the apphost exits
REM   0x8000809A with ".NET Runtime 1023: The application to execute does not
REM   exist: TrackerAgent.dll" (verified 2026-09-27). Do not enable it.
REM  -p:IncludeNativeLibrariesForSelfExtract=true is mandatory for WPF: without it
REM   the native libs stay inside the bundle and window creation dies with
REM   "Unhandled 0xe0434352: System.DllNotFoundException at
REM   MS.Internal.WindowsBase.NativeMethodsSetLastError.SetWindowLongPtrWndProc"
REM   (verified 2026-09-27).
dotnet publish -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true

if %ERRORLEVEL% neq 0 (
    echo [X] Build failed.
    exit /b 1
)

echo [OK] Output: bin\Release\net8.0-windows\win-x64\publish\TrackerAgent.exe
echo.
echo GUI:
echo   Double-click TrackerAgent.exe    - zero-config install (no URL or token prompt)
echo.

echo USAGE:
echo   TrackerAgent.exe --token tat_xxx --url http://127.0.0.1:8000
echo   TrackerAgent.exe --token tat_xxx --daemon
echo   TrackerAgent.exe --uninstall
echo.

REM Copy to parent agent/ folder for easy access
copy /Y "bin\Release\net8.0-windows\win-x64\publish\TrackerAgent.exe" "..\TrackerAgent.exe" >nul
echo [OK] Copied to ..\TrackerAgent.exe

REM Copy to Laravel storage for the web download route (agent.download)
copy /Y "bin\Release\net8.0-windows\win-x64\publish\TrackerAgent.exe" "..\..\storage\app\agent\TrackerAgent.exe" >nul
if exist "..\..\storage\app\agent\TrackerAgent.exe" echo [OK] Copied to storage\app\agent\TrackerAgent.exe
