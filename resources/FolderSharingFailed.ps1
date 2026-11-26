# Silent agent-ready version. Generated from App\Support\ScriptPresets::FIX_FOLDER_SHARING.
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
}function Set-RegistryValue {
    param([string]$Path, [string]$Name, $Value, [string]$Kind = 'DWord')
    if (-not (Test-Path -LiteralPath $Path)) { New-Item -Path $Path -Force | Out-Null }
    New-ItemProperty -LiteralPath $Path -Name $Name -Value $Value -PropertyType $Kind -Force | Out-Null
}
if (-not (Test-Admin)) { Write-Log 'FAIL: administrator privileges required.'; exit 1 }
try {
    Set-SmbClientConfiguration -EnableInsecureGuestLogon $true -Force -ErrorAction Stop
    Set-SmbClientConfiguration -RequireSecuritySignature $false -Force -ErrorAction SilentlyContinue
    Set-SmbServerConfiguration -RequireSecuritySignature $false -Force -ErrorAction SilentlyContinue
    Set-RegistryValue -Path 'HKLM:\SYSTEM\CurrentControlSet\Services\LanmanWorkstation\Parameters' -Name 'AllowInsecureGuestAuth' -Value 1
    Set-RegistryValue -Path 'HKLM:\SOFTWARE\Policies\Microsoft\Windows\LanmanWorkstation' -Name 'AllowInsecureGuestAuth' -Value 1
    Set-RegistryValue -Path 'HKLM:\SYSTEM\CurrentControlSet\Control\Lsa' -Name 'restrictanonymous' -Value 0
    Set-RegistryValue -Path 'HKLM:\SYSTEM\CurrentControlSet\Control\Lsa' -Name 'LocalAccountTokenFilterPolicy' -Value 1
    Write-Log 'OK: folder-sharing settings applied. Reboot to fully apply.'
    exit 0
} catch {
    Write-Log ('FAIL: ' + $_.Exception.Message)
    exit 1
}
