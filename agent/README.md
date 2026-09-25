# TRACKER-ASSET Audit Agent

Windows hardware collector that runs on each laptop/workstation and reports back to the TRACKER-ASSET server.

## What It Does

1. Collects hardware info (hostname, serial, CPU, RAM, storage, OS, MAC, IP) via WMI
2. POSTs JSON to the server API
3. Server compares against registered asset data
4. Returns MATCH / MISMATCH / UNKNOWN DEVICE result
5. **Never overwrites registered data** — comparison only

## Two Modes

| Mode | Description |
|---|---|
| **One-shot** | Run once, audit current device, display result |
| **Daemon** | Resident background process, polls server inbox for scan/update commands |

## Files

| File | Description |
|---|---|
| `TrackerAgent/` | C# .NET 8 source code |
| `TrackerAgent.exe` | Pre-built single-file executable (after build) |
| `tracker-audit-agent.ps1` | PowerShell one-shot collector |
| `tracker-agent-daemon.ps1` | PowerShell daemon (supports auto-update) |

## Prerequisites

- Windows 10/11
- .NET 8 SDK (for building only): https://dotnet.microsoft.com/download/dotnet/8.0
- Agent token from TRACKER-ASSET web UI (`/agent` page)

## Build (C# .exe)

```powershell
cd agent\TrackerAgent
.\build.bat
```

Output: `agent\TrackerAgent.exe` (single-file, self-contained, ~15MB)

## Usage

### One-shot audit

```powershell
.\TrackerAgent.exe --token tat_xxx --url http://your-server:8000
```

### Daemon mode (background, auto-start)

```powershell
.\TrackerAgent.exe --token tat_xxx --url http://your-server:8000 --daemon
```

### Remove daemon autostart

```powershell
.\TrackerAgent.exe --uninstall
```

### Options

| Flag | Description |
|---|---|
| `--url`, `-u` | API base URL (default: `http://127.0.0.1:8000`) |
| `--token`, `-t` | Bearer token (or set `TRACKER_AUDIT_TOKEN` env) |
| `--asset-id`, `-a` | Specific asset ID to audit against |
| `--quiet`, `-q` | Suppress console output |
| `--daemon`, `-d` | Run as resident background daemon |
| `--poll` | Daemon poll interval in seconds (default: 10) |
| `--uninstall` | Remove daemon from Windows autostart |

## PowerShell Alternative

```powershell
# One-shot
.\tracker-audit-agent.ps1 -ApiUrl http://127.0.0.1:8000 -Token tat_xxx

# Daemon
.\tracker-agent-daemon.ps1 -ApiUrl http://127.0.0.1:8000 -Token tat_xxx
```

## Fleet Deployment

1. Create an agent token on the TRACKER-ASSET web UI (`/agent`)
2. Distribute `TrackerAgent.exe` to target machines (or use the one-liner install command)
3. Run with `--daemon` flag
4. Agent registers itself for Windows autostart (HKCU Run key)
5. Monitor from TRACKER-ASSET dashboard → Agent Fleet section

## API Endpoints Used

| Method | Endpoint | Purpose |
|---|---|---|
| POST | `/api/audits` | Submit hardware scan data |
| GET | `/api/agent/inbox` | Poll for pending commands |
| POST | `/api/agent/commands/{id}/ack` | Acknowledge command execution |
