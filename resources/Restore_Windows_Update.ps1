# Silent agent-ready restore script. Generated from App\Support\ScriptPresets::RESTORE_WINDOWS_UPDATE.
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
