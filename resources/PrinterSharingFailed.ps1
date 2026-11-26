# Silent agent-ready version. Generated from App\Support\ScriptPresets::FIX_PRINTER_RPC.
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
    $rpc = 'HKLM:\SOFTWARE\Policies\Microsoft\Windows NT\Printers\RPC'
    Set-RegistryValue -Path $rpc -Name 'RpcUseNamedPipeProtocol' -Value 1
    Set-RegistryValue -Path $rpc -Name 'RpcOverNamedPipes' -Value 1
    Set-RegistryValue -Path $rpc -Name 'RpcOverTcp' -Value 1
    Set-RegistryValue -Path $rpc -Name 'ForceKerberosForRpc' -Value 0
    Set-RegistryValue -Path $rpc -Name 'RpcProtocols' -Value 7
    Set-RegistryValue -Path 'HKLM:\SYSTEM\CurrentControlSet\Control\Print' -Name 'RpcAuthnLevelPrivacyEnabled' -Value 0
    foreach ($feature in @('Printing-Foundation-LPDPrintService', 'Printing-Foundation-LPRPortMonitor')) {
        $state = (Get-WindowsOptionalFeature -Online -FeatureName $feature -ErrorAction SilentlyContinue).State
        if ($state -ne 'Enabled') {
            Enable-WindowsOptionalFeature -Online -FeatureName $feature -NoRestart -ErrorAction SilentlyContinue | Out-Null
            Write-Log ("Enabled feature: $feature")
        }
    }
    Write-Log 'OK: printer RPC policy applied.'
    exit 0
} catch {
    Write-Log ('FAIL: ' + $_.Exception.Message)
    exit 1
}
