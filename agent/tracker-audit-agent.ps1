<#
.SYNOPSIS
    tracker-audit-agent - lightweight Windows hardware collector for TRACKER-ASSET (PRD section 11).

.DESCRIPTION
    Collects hardware identity from the local machine (WMI/CIM) and POSTs it to
    the TRACKER-ASSET audit API. All matching/comparison logic lives server-side;
    the agent never writes to the register.

    Token: pass -Token or set $env:TRACKER_AUDIT_TOKEN (issued via `php artisan audit:token`).

.EXAMPLE
    .\tracker-audit-agent.ps1 -ApiUrl http://127.0.0.1:8001 -Token tat_xxx
#>

param(
    [string]$ApiUrl = $env:TRACKER_API_URL,
    [string]$Token = $env:TRACKER_AUDIT_TOKEN,
    [string]$AssetId = "",
    [switch]$Install,
    [switch]$Quiet
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($ApiUrl)) { $ApiUrl = 'http://127.0.0.1:8001' }
if ([string]::IsNullOrWhiteSpace($Token)) {
    Write-Host '[X] No token. Pass -Token or set TRACKER_AUDIT_TOKEN.' -ForegroundColor Red
    exit 1
}

function ConvertTo-GB([double]$bytes) {
    if ($bytes -le 0) { return $null }
    return [math]::Round($bytes / 1GB, 0).ToString() + 'GB'
}

# --- collect hardware identity ------------------------------------------------
$cs = Get-CimInstance Win32_ComputerSystem
$bios = Get-CimInstance Win32_BIOS
$cpu = Get-CimInstance Win32_Processor | Select-Object -First 1
$os = Get-CimInstance Win32_OperatingSystem
$disk = Get-CimInstance Win32_DiskDrive |
    Sort-Object Size -Descending | Select-Object -First 1
$nic = Get-CimInstance Win32_NetworkAdapterConfiguration |
    Where-Object { $_.IPEnabled -and $_.MACAddress } | Select-Object -First 1

$ram = ConvertTo-GB ([double]$cs.TotalPhysicalMemory)
$storage = ConvertTo-GB ([double]$disk.Size)

$payload = [ordered]@{
    asset_id      = $AssetId
    hostname      = $env:COMPUTERNAME
    serial_number = ($bios.SerialNumber | ForEach-Object { ($_ -replace '\s+', ' ').Trim() })
    brand         = $cs.Manufacturer
    model         = $cs.Model
    processor     = $cpu.Name
    ram           = $ram
    storage       = $storage
    os            = "$($os.Caption) $($os.Version)".Trim()
    mac_address   = $nic.MACAddress
    ip_address    = ($nic.IPAddress | Select-Object -First 1)
}

# Drop empty values so the server never receives blank identifiers.
$body = [ordered]@{}
foreach ($key in $payload.Keys) {
    $value = $payload[$key]
    if (-not [string]::IsNullOrWhiteSpace("$value")) { $body[$key] = "$value" }
}

if (-not $Quiet) {
    Write-Host '[i] Collected from this device:' -ForegroundColor Cyan
    foreach ($key in $body.Keys) { Write-Host ("    {0,-14}: {1}" -f $key, $body[$key]) }
    Write-Host "[i] Posting to $ApiUrl/api/audits ..." -ForegroundColor Cyan
}

# --- submit --------------------------------------------------------------------
$headers = @{ Authorization = "Bearer $Token" }
$uri = $ApiUrl.TrimEnd('/') + '/api/audits'

try {
    $response = Invoke-RestMethod -Uri $uri -Method Post -Headers $headers `
        -ContentType 'application/json; charset=utf-8' -Body ($body | ConvertTo-Json) -TimeoutSec 30
}
catch {
    Write-Host "[X] Submission failed: $($_.Exception.Message)" -ForegroundColor Red
    if ($_.Exception.Response) {
        $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
        Write-Host $reader.ReadToEnd() -ForegroundColor DarkRed
    }
    exit 2
}

# --- report ---------------------------------------------------------------------
switch ($response.result) {
    'MATCH'    { $color = 'Green' }
    'MISMATCH' { $color = 'Yellow' }
    default    { $color = 'Magenta' }
}

Write-Host ''
Write-Host ("[OK] Result: {0}  (asset: {1})" -f $response.result, $response.asset) -ForegroundColor $color

if ($response.mismatches -and $response.mismatches.PSObject.Properties.Count -gt 0) {
    Write-Host '[!] Mismatched fields:' -ForegroundColor Yellow
    foreach ($field in $response.mismatches.PSObject.Properties) {
        Write-Host ("    {0}: registered '{1}' vs actual '{2}'" -f $field.Name, $field.Value.registered, $field.Value.actual)
    }
    Write-Host '[i] Registered data was NOT overwritten.' -ForegroundColor Cyan
}

Write-Host $response.message -ForegroundColor $color

# --- optional: register a daily scheduled task (fleet rollout, PRD section 11) ------
if ($Install) {
    $taskName = 'TRACKER Audit Agent'
    try {
        # Save our own source (works whether piped via iex or run as a file),
        # so the scheduled task runs a local copy with URL/token already baked in.
        $scriptText = $MyInvocation.MyCommand.ScriptBlock.Ast.Extent.Text
        if (-not $scriptText) { throw 'Cannot capture the running script text.' }
        $bootFile = Join-Path $env:LOCALAPPDATA 'TRACKER-audit-agent.ps1'
        Set-Content -Path $bootFile -Value $scriptText -Encoding UTF8

        $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $bootFile + '"')
        $trigger = New-ScheduledTaskTrigger -Daily -At 10:17am
        Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Force | Out-Null

        Write-Host "[OK] Scheduled task '$taskName' registered - audits run daily at 10:17." -ForegroundColor Green
        Write-Host "[i] Agent saved to: $bootFile" -ForegroundColor Cyan
        Write-Host "[i] Run it now with: Start-ScheduledTask -TaskName '$taskName'" -ForegroundColor Cyan
    }
    catch {
        Write-Host "[X] Could not register scheduled task: $($_.Exception.Message)" -ForegroundColor Red
        Write-Host '[i] Tip: run PowerShell as your user (elevation may be required).' -ForegroundColor Yellow
        exit 3
    }
}

exit 0
