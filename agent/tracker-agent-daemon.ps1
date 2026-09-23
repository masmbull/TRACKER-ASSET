<#
.SYNOPSIS
    tracker-agent-daemon - resident background collector for TRACKER-ASSET.
.DESCRIPTION
    Runs fully hidden (no window, tray or popup). Polls the server command
    inbox every 10 seconds and executes:
      scan    -> collect this machine's hardware and POST /api/audits
      update  -> re-pull the daemon from the server, replace itself, restart
    Autostarts through the HKCU Run key (no admin rights needed).
    Run with -Uninstall to remove autostart and stop.

.PARAMETER ApiUrl
    TRACKER-ASSET base URL, e.g. http://127.0.0.1:8001 or https://domain/.
.PARAMETER Token
    Agent token created on the /agent page.
.PARAMETER PollSeconds
    Inbox poll interval in seconds (default 10).
.PARAMETER Uninstall
    Remove HKCU Run entry and stop the daemon.

.EXAMPLE
    powershell -NoProfile -ExecutionPolicy Bypass -File tracker-agent-daemon.ps1 -ApiUrl http://127.0.0.1:8001 -Token tat_xxx
#>
param(
    [string]$ApiUrl = $env:TRACKER_API_URL,
    [string]$Token = $env:TRACKER_AUDIT_TOKEN,
    [int]$PollSeconds = 10,
    [switch]$Uninstall
)

$ErrorActionPreference = 'Stop'
$base = $ApiUrl.TrimEnd('/')
$headers = @{ Authorization = "Bearer $Token" }
$runName = 'TRACKER Audit Agent'
$runKey = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Run'
$workDir = Join-Path $env:LOCALAPPDATA 'TRACKER'
$selfName = 'tracker-agent-daemon.ps1'

$dest = Join-Path $workDir $selfName
$runningFromInstalledCopy = $PSCommandPath -and ($PSCommandPath -eq $dest)

if ($Uninstall) {
    Remove-ItemProperty -Path $runKey -Name $runName -ErrorAction SilentlyContinue
    Write-Host 'Daemon removed from autostart.'
    exit 0
}

New-Item -ItemType Directory -Force -Path $workDir | Out-Null

if (-not $runningFromInstalledCopy) {
    # Not the installed copy yet: persist ourselves.
    if ($PSCommandPath) {
        Copy-Item -Force -Path $PSCommandPath -Destination $dest
    } else {
        # Piped through iex: capture our own source text.
        $text = $MyInvocation.MyCommand.ScriptBlock.ToString()
        if ([string]::IsNullOrWhiteSpace($text)) { throw 'Could not capture script body.' }
        Set-Content -Path $dest -Value $text -Encoding UTF8
    }
}

# Autostart registration is idempotent and always enforced - even when the
# daemon was started by hand from the installed path.
$cmd = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $dest + '"'
Set-ItemProperty -Path $runKey -Name $runName -Value $cmd -Force

if (-not $runningFromInstalledCopy) {
    # Hand over to the installed copy (hidden) and exit this bootstrap instance.
    Start-Process powershell.exe -ArgumentList ('-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $dest + '"') -WindowStyle Hidden
    exit 0
}

# --- helpers -----------------------------------------------------------------
function ConvertTo-Gb([double]$bytes) {
    if ($bytes -le 0) { return $null }
    return [math]::Round($bytes / 1GB, 0).ToString() + 'GB'
}

function Invoke-Scan {
    try {
        $cs = Get-CimInstance Win32_ComputerSystem
        $bios = Get-CimInstance Win32_BIOS
        $cpu = Get-CimInstance Win32_Processor | Select-Object -First 1
        $os = Get-CimInstance Win32_OperatingSystem
        $disk = Get-CimInstance Win32_DiskDrive | Sort-Object Size -Descending | Select-Object -First 1
        $nic = Get-CimInstance Win32_NetworkAdapterConfiguration | Where-Object { $_.IPEnabled -and $_.MACAddress } | Select-Object -First 1

        $body = [ordered]@{
            hostname      = $env:COMPUTERNAME
            serial_number = (($bios.SerialNumber | ForEach-Object { ($_ -replace '\s+', ' ').Trim() }))
            brand         = $cs.Manufacturer
            model         = $cs.Model
            processor     = $cpu.Name
            ram           = ConvertTo-Gb ([double]$cs.TotalPhysicalMemory)
            storage       = ConvertTo-Gb ([double]$disk.Size)
            os            = (($os.Caption + ' ' + $os.Version).Trim())
            mac_address   = $nic.MACAddress
            ip_address    = ($nic.IPAddress | Select-Object -First 1)
        }

        $clean = [ordered]@{}
        foreach ($k in $body.Keys) {
            $v = $body[$k]
            if (-not [string]::IsNullOrWhiteSpace("$v")) { $clean[$k] = "$v" }
        }

        Invoke-RestMethod -Uri ($base + '/api/audits') -Method Post -Headers $headers `
            -ContentType 'application/json; charset=utf-8' -Body ($clean | ConvertTo-Json) -TimeoutSec 30 | Out-Null
        return $true
    }
    catch {
        return $false
    }
}

function Update-Self {
    try {
        $latest = Invoke-WebRequest -Uri ($base + '/agent/daemon.ps1?token=' + $Token) -Headers $headers -UseBasicParsing -TimeoutSec 30
    } catch {
        return $false
    }
    New-Item -ItemType Directory -Force -Path $workDir | Out-Null
    $newFile = Join-Path $workDir ('_new_' + $selfName)
    Set-Content -Path $newFile -Value $latest.Content -Encoding UTF8
    $boot = Join-Path $workDir '_boot.ps1'
    $bootText = "Start-Sleep -Seconds 2`r`nMove-Item -Force -Path '$newFile' -Destination '$(Join-Path $workDir $selfName)'`r`n& powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File '$(Join-Path $workDir $selfName)'"
    Set-Content -Path $boot -Value $bootText -Encoding UTF8
    Start-Process powershell.exe -ArgumentList ('-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $boot + '"') -WindowStyle Hidden
    return $true
}

while ($true) {
    try {
        $inbox = Invoke-RestMethod -Uri ($base + '/api/agent/inbox') -Headers $headers -TimeoutSec 20
        if ($inbox.commands) {
            foreach ($cmd in $inbox.commands) {
                if ($cmd.command -eq 'scan') {
                    $ok = Invoke-Scan
                    $status = if ($ok) { 'done' } else { 'failed' }
                    try { Invoke-RestMethod -Uri ($base + '/api/agent/commands/' + $cmd.id + '/ack') -Method Post -Headers $headers -ContentType 'application/json' -Body (@{ status = $status } | ConvertTo-Json) -TimeoutSec 15 | Out-Null } catch { }
                }
                elseif ($cmd.command -eq 'update') {
                    try { Invoke-RestMethod -Uri ($base + '/api/agent/commands/' + $cmd.id + '/ack') -Method Post -Headers $headers -ContentType 'application/json' -Body (@{ status = 'done' } | ConvertTo-Json) -TimeoutSec 15 | Out-Null } catch { }
                    if (Update-Self) { exit 0 }
                }
            }
        }
    } catch { }
    Start-Sleep -Seconds $PollSeconds
}
