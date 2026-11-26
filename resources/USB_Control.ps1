# Silent, agent-ready replacement for USB_Control_Menu.bat.
# Usage: powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File USB_Control.ps1 -Action Disable
param(
    [ValidateSet('Disable', 'Enable')]
    [string]$Action = 'Disable'
)

$ErrorActionPreference = 'Stop'
$logDir = Join-Path $env:ProgramData 'TrackerAgent\Logs'
New-Item -ItemType Directory -Path $logDir -Force | Out-Null
$logFile = Join-Path $logDir ('usb-control-{0}-{1}.log' -f $env:COMPUTERNAME, (Get-Date -Format 'yyyyMMdd-HHmmss'))
function Write-Log { param([string]$Message) Add-Content -LiteralPath $logFile -Value ('[{0}] {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message) }

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Log 'FAIL: administrator privileges required.'
    exit 1
}

try {
    $value = if ($Action -eq 'Disable') { 4 } else { 3 }
    $path = 'HKLM:\SYSTEM\CurrentControlSet\Services\USBSTOR'
    if (-not (Test-Path -LiteralPath $path)) { New-Item -Path $path -Force | Out-Null }
    New-ItemProperty -LiteralPath $path -Name 'Start' -Value $value -PropertyType DWord -Force | Out-Null
    $actual = (Get-ItemProperty -LiteralPath $path -Name 'Start').Start
    if ($actual -ne $value) { throw "USBSTOR Start is $actual, expected $value" }
    Write-Log ("OK: USB mass storage {0} (Start={1}). Reboot to apply." -f $Action.ToLowerInvariant(), $value)
    exit 0
} catch {
    Write-Log ('FAIL: ' + $_.Exception.Message)
    exit 1
}
