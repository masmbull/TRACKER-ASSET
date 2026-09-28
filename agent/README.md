# TRACKER-ASSET Audit Agent

Windows hardware collector that runs on each laptop/workstation and reports back to the TRACKER-ASSET server.

## What It Does

1. Collects hardware info (hostname, serial, CPU, RAM, storage, OS, MAC, IP) via WMI
2. POSTs JSON to the server API
3. Server compares against registered asset data
4. Returns MATCH / MISMATCH / UNKNOWN DEVICE result
5. **Never overwrites registered data** â€” comparison only

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
| `--url`, `-u` | API base URL (default: baked download address, else `http://127.0.0.1:8000`) |
| `--token`, `-t` | Bearer token (or set `TRACKER_AUDIT_TOKEN` env) |
| `--asset-id`, `-a` | Specific asset ID to audit against |
| `--quiet`, `-q` | Suppress console output |
| `--daemon`, `-d` | Run as resident background daemon |
| `--poll` | Daemon poll interval in seconds (default: 10) |
| `--uninstall` | Remove daemon from Windows autostart |
| `--update` | Pull the newest `TrackerAgent.exe` from the server and self-swap |

### Double-click: install / update / already installed

Double-clicking a downloaded `TrackerAgent.exe` probes the machine first
(config in `%LocalAppData%\TRACKER` plus the assembly versions of both files):

| State | What happens |
|---|---|
| Not installed | Full zero-config install (as before) |
| Installed, **same version** | Notice: **"Aplikasi Sudah Pernah Diinstall"** |
| Installed, **different version** | In-place update: stops the running agent, swaps the exe (config and device identity untouched), restarts whatever was running before |

Double-clicking the installed executable itself still opens the tray icon.
Bump `<Version>` in `TrackerAgent.csproj` on every release — the version
comparison is what drives the three states above.

### Manual self-update

```powershell
.\TrackerAgent.exe --update --url http://your-server:8000 --token tat_xxx
```

### Auto-update (no user action needed)

Push **Update All Agents** from the web UI (`/agent` page). The command lands in
the agent's inbox; on the next poll the daemon acks it, downloads the current
exe from `/agent/download`, swaps it via a detached helper script, and restarts
itself with the exact same flags.

## Fleet Deployment

1. Create an agent token on the TRACKER-ASSET web UI (`/agent`)
2. Distribute `TrackerAgent.exe` to target machines (or use the one-liner install command)
3. Run with `--daemon` flag
4. Agent registers itself for Windows autostart (HKCU Run key)
5. Monitor from TRACKER-ASSET dashboard â†’ Agent Fleet section

## API Endpoints Used

| Method | Endpoint | Purpose |
|---|---|---|
| POST | `/api/audits` | Submit hardware scan data |
| GET | `/api/agent/inbox` | Poll for pending commands |
| POST | `/api/agent/commands/{id}/ack` | Acknowledge command execution |
| GET | `/agent/download` | Fetch the newest agent exe (self-update) |
| POST | `/api/agent/register` | Register a device during GUI install |


## GUI Installer Mode (zero configuration)

Double-clicking `TrackerAgent.exe` with no CLI flags opens the installer window. It is
fully automatic: the download route inlines the server address, so there is no URL or
token to type and the only UI while it works is an animated progress bar.

1. Reads the server address baked into its own binary (see below) - falling back to
   `TRACKER_API_URL`, then `--server-url`, then the address used by a previous
   installation, then `/api/health` probes of `http://127.0.0.1:8000` (plus 8003, 8004
   and `localhost`).
2. Copies itself to `%LOCALAPPDATA%\TRACKER`, generates or reuses the device id,
   registers the device on `/api/agent/register` and writes `config.json`.
3. Adds the `--daemon` autostart entry (HKCU Run), starts the resident agent and runs a
   first hardware scan, so the laptop appears in Master Assets and Live Fleet within
   seconds.
4. Shows "Installation complete" and closes itself. If no server answers, a single
   **Retry** button is offered.

Once configured, later launches go straight to the tray icon (Scan Now / Check for
Updates / Exit).

## Zero-Config Download (config trailer)

`AgentTokenController::download()` streams the built exe and appends a UTF-8 trailer:

```
<<<TRACKER-AGENT-CONFIG:<base64 json>:END>>>
```

The JSON carries `server_urls` (the host the browser used plus `APP_URL`) and
`issued_at`. `AgentConfigTrailer.cs` reads the last 64 KB of the running executable, so
the installer and the daemon know their server without any input. Appending to a .NET 8
single-file executable is safe: the host locates its bundle through an absolute offset
rather than an end-of-file footer (verified by running an appended build).

The trailer is exactly `"\n<<<TRACKER-AGENT-CONFIG:<base64 json>:END>>>\n"` — 33 bytes
of fixed overhead (24-byte opener, 7-byte closer, 2 newline bytes) plus the base64
payload, so a typical trailer measures 173 bytes total (171 excluding the newlines).

## Standard Users & UAC (fleet reality)

Fleet laptops run local **standard** accounts; the admin profile belongs to IT and end
users cannot approve UAC. The agent is built for exactly that:

- No manifest elevation (`asInvoker`) — double-clicking the installer never raises a
  UAC prompt, so an ordinary user can complete the 1-click install unattended.
- All state lives in `%LOCALAPPDATA%\TRACKER` and `HKCU` (Run key, device id). The
  agent never writes HKLM, never installs to Program Files, never creates a Windows
  service, scheduled task or firewall rule.
- WMI hardware collection and the scan/audit loop work for standard users.

Install **from the daily-driver account** — installation is per-user, so the HKCU
autostart belongs to whoever runs the installer.

## Windows Smart App Control (SAC) & code signing

The exe is unsigned. Smart App Control / SmartScreen judge **per binary hash**: a
freshly built hash can be blocked once while a re-download passes (the config
trailer embeds `issued_at`, so every download produces a new hash). The trailer
itself is innocent — locally appended builds run normally.

**Real fix: code-sign `TrackerAgent.exe`** with a trusted certificate
(`signtool sign /fd SHA256 ...`). When signing, re-verify the signed binary together
with the download-time trailer append (sign happens at build time, the trailer is
appended when the server streams the file) and re-run the install E2E. Until the
binary is signed, expect occasional SmartScreen/SAC friction on fresh builds.
