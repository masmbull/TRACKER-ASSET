# TRACKER-ASSET Audit Agent

Windows hardware collector that runs on each laptop/workstation and reports back to the TRACKER-ASSET server.

## What It Does

1. Collects hardware info (hostname, serial, CPU, RAM, storage, OS, MAC, IP) via WMI
2. POSTs JSON to the server API
3. Server compares against registered asset data
4. Returns MATCH / MISMATCH / UNKNOWN DEVICE result
5. Writes a scan audit, then updates only reported hardware fields when the serial number matches exactly one registered asset; management fields remain unchanged

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

## Shipping a Release

`build.bat` writes one shared file; the release registry is what makes a *version*
downloadable, rollback-able and push-able. Two ways to register a build:

**Web UI (recommended):** open **/agent/releases**, click **Upload Build**, enter the
version and attach the `.exe`. Size and SHA-256 are read from the file, and the build
becomes the current release immediately. Then **Push to fleet** queues an update on
every device that is not already on it.

**Artisan (same effect, scriptable):**

```powershell
php artisan agent:release 1.4.0 --notes="Fixed RAM reporting" --file=agent\TrackerAgent.exe
php artisan agent:release --list
```

Each release stores its own copy under `storage\app\agent\releases\<version>-<sha12>.exe`,
so **Activate** on the releases page is a real rollback: an older stored build becomes
the one the download button and updates serve, with no rebuild and no file copy.

The download routes serve the *current release's* stored file; if a release has no
stored binary (any row created before per-release storage, or `agent:release` without
`--file`), they fall back to the legacy shared `storage\app\agent\TrackerAgent.exe`.

> Uploading ~160 MB through the web form needs PHP `upload_max_filesize` /
> `post_max_size` and nginx `client_max_body_size` raised above it. The bundled
> Docker image and `docker/nginx.conf` already set limits for this; a hand-built
> PHP host must do the same or the upload is rejected before it reaches Laravel.

## Usage

For manual CLI use, set `TRACKER_AUDIT_TOKEN` through Windows Credential Manager or your
organization's endpoint-management secret facility. Do not add a token literal to a script,
shell history, README, or command-line example. The downloaded, server-configured release is
the preferred unattended deployment path because its token is delivered inside the binary
configuration trailer.

### One-shot audit

```powershell
.\TrackerAgent.exe --url https://your-tracker-host
```

### Daemon mode (background, auto-start)

```powershell
.\TrackerAgent.exe --url https://your-tracker-host --daemon
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
| `--status` | Print the installed device ID, server URL and install path, then exit |

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
.\TrackerAgent.exe --update --url https://your-tracker-host
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
| GET | `/api/agent/register/options` | Fetch company/category/location/status/user choices for first install |
| POST | `/api/agent/bootstrap-asset` | Create the first asset from the install form (agent bearer token required) |


## GUI Installer Mode (zero configuration)

Double-clicking `TrackerAgent.exe` with no CLI flags opens the installer window. The
download route inlines the server address, so there is no URL or token to type. The UI
shows each phase, its current status, a labelled stage message, and a percentage bar.

1. Reads the server address baked into its own binary (see below) - falling back to
   `TRACKER_API_URL`, then `--server-url`, then the address used by a previous
   installation, then `/api/health` probes of `http://127.0.0.1:8000` (plus 8003, 8004
   and `localhost`).
2. Collects the device serial and asks `/api/agent/register` whether it is already in
   the asset register. Known assets skip the data-entry form.
3. For a new device, shows ten register fields: generated asset code, company, category,
   location, status, holder, IT PIC, purchase year, vendor, and purchase price. Choice
   lists are fetched from `/api/agent/register/options`; the server generates the final
   asset code and saves the form through the authenticated `/api/agent/bootstrap-asset`
   endpoint after issuing the agent token.
4. Copies itself to `%LOCALAPPDATA%\TRACKER`, saves `config.json`, configures autostart,
   starts the tray agent, and queues the first hardware scan. The completed checklist
   remains visible until the operator closes the result window.

Once configured, later launches go straight to the system tray. Its context menu has
exactly two actions—**Status** and **Check for Updates**—with icons. There is no Exit
item: the agent remains resident and is removed only through the uninstall command on
the fleet page.

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
