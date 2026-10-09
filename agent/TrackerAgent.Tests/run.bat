@echo off
REM --------------------------------------------------------------------------
REM  Run the offline agent checks - run from anywhere.
REM  Requires: .NET 8 SDK (same prerequisite as agent\TrackerAgent\build.bat)
REM  No NuGet feed needed: no test framework, no extra packages.
REM --------------------------------------------------------------------------

pushd "%~dp0"

echo [*] Running the agent checks...
dotnet run --project "%~dp0TrackerAgent.Tests.csproj"
set EXITCODE=%ERRORLEVEL%

popd

if %EXITCODE% neq 0 (
    echo [X] Agent checks failed.
    exit /b 1
)

echo [OK] Agent checks passed.