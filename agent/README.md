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

Output: `agent\TrackerAgent.exe` (single-file, self-contained, ~68 MB, no .NET install
required on the target machine). `build.bat` also copies it to
`storage\app\agent\TrackerAgent.exe` so the web UI download link serves the build
you just compiled.

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
| `--update` | Pull the newest `TrackerAgent.exe` from the server and self-swap |

### Manual self-update

```powershell
.\TrackerAgent.exe --update --url http://your-server:8000 --token tat_xxx
```

### Auto-update (no user action needed)

Push **Update All Agents** from the web UI (`/agent` page). The command lands in
the agent's inbox; on the next poll the daemon acks it, downloads the current
exe from `/agent/download`, swaps it via a detached helper script, and restarts
itself with the exact same flags.

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
| GET | `/agent/download` | Fetch the newest agent exe (self-update) |
| POST | `/api/agent/register` | Register a device during GUI install |

## GUI Installer Mode

Double-clicking `TrackerAgent.exe` with no CLI flags opens the installer window:
it probes `http://127.0.0.1:8000` (and ports 8003/8004, plus `localhost`) via
`/api/health`, falls back to a manual server URL box, registers the device on
`/api/agent/register`, writes `%LOCALAPPDATA%\TRACKER\config.json`, and adds the
`--daemon` autostart entry. Once configured, subsequent launches start straight
into the system tray (Scan Now / Check for Updates / Exit).
