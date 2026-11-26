# Silent agent-ready version. Generated from App\Support\ScriptPresets::OUTLOOK_ATTACHMENT_150MB.
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
try {
    Set-RegistryValue -Path 'HKCU:\Software\Microsoft\Office\16.0\Outlook\Preferences' -Name 'MaximumAttachmentSize' -Value 157286400
    Write-Log 'OK: Outlook attachment limit set to 150 MB.'
    exit 0
} catch {
    Write-Log ('FAIL: ' + $_.Exception.Message)
    exit 1
}
