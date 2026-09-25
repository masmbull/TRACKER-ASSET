@echo off
REM ──────────────────────────────────────────────────────────────────────────
REM  Build TrackerAgent.exe — run from the agent/TrackerAgent directory
REM  Requires: .NET 8 SDK (https://dotnet.microsoft.com/download/dotnet/8.0)
REM ──────────────────────────────────────────────────────────────────────────

echo [*] Building TrackerAgent.exe (single-file, self-contained)...
dotnet publish -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true -p:EnableCompressionInSingleFile=true

if %ERRORLEVEL% neq 0 (
    echo [X] Build failed.
    exit /b 1
)

echo [OK] Output: bin\Release\net8.0-windows\win-x64\publish\TrackerAgent.exe
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
