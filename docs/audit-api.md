# Audit API — TRACKER-ASSET

## Purpose

Serve the lightweight audit agent (`tracker-audit-agent`, PRD §11 — preferred
audit architecture). The client only collects hardware data and posts JSON;
**all business logic stays in Laravel**, so agent results are identical to the
browser audit flow.

## Protocol

```text
POST /api/audits
Authorization: Bearer <token>
Content-Type: application/json

{
  "asset_id": "LAPTOP-001",        // optional; resolved first if present
  "serial_number": "SN001",        // optional; resolved second
  "hostname": "PC-001",            // optional; resolved third
  "mac_address": "AA:BB:CC:DD:EE:01",
  "brand": "Dell",
  "model": "Latitude 5520",
  "processor": "i5-1135G7",
  "ram": "8 GB",
  "storage": "256GB NVMe",
  "os": "Windows 11 Pro",
  "ip_address": "10.0.0.10"
}
```

One of `asset_id` / `serial_number` / `hostname` / `mac_address` is enough to
resolve the asset; resolution order is asset_id → serial → hostname → MAC.

## Responses

| Case | HTTP | Body |
|---|---|---|
| `MATCH` | 200 | `{"asset": "...", "result": "MATCH", "mismatches": [], "message": "..."}` |
| `MISMATCH` | 200 | `{"asset": "...", "result": "MISMATCH", "mismatches": {"ram": {...}}, "message": "..."}` |
| `UNKNOWN DEVICE` | 202 | `{"asset": null, "result": "UNKNOWN DEVICE", ...}` — nothing was written except an audit log entry; the device is never auto-registered |
| Missing/invalid token | 401 | `{"message": "Invalid or expired audit API token."}` |

Registered data is **never overwritten** — the agent only reads and reports.

## Authentication

Tokens are issued by IT Support with:

```bash
php artisan audit:token lab-agent-01
php artisan audit:token lab-agent-01 --expires=2027-01-01
```

The plaintext token (`tat_...`) is shown **once**; only its SHA-256 hash is
stored. Tokens carry `audit:submit` + `assets:lookup` abilities, support expiry
and revocation (`is_active = false`).

## Tests

See `tests/Feature/AuditApiTest.php` — auth rejection, MATCH, MISMATCH with
field detail, UNKNOWN DEVICE immutability, inactive/expired tokens.

## Fleet rollout (web, no .exe) — `/agent`

Admin-only page (`/agent`, nav → Master Data → Audit Agents):

1. Create a token (name + expiry) → copy the **one-line install command**. It
   downloads `/agent/daemon.ps1` (with site URL + token already baked in),
   saves it to `%LOCALAPPDATA%\TRACKER\`, registers autostart and starts it
   **hidden** — no window, no tray, no popup.
2. The script endpoint validates the token on every download: unknown or
   revoked tokens get `404`, so a leaked/removed token cannot pull the script.
3. **Revoke** any token on the spot: affected laptops get `401` on their next poll.
4. `last_used_at` refreshes on every inbox poll and powers the online indicator
   on `/agent/commands`.

Only the SHA-256 hash is stored; plaintext appears once at creation.



## Agent Client (PRD §11 — resident daemon, no .exe)

`agent/tracker-agent-daemon.ps1` is a **resident** background collector:

- Runs **hidden** — no console window, no tray icon, no popup
- Polls the command inbox every 10 seconds
- Executes `scan` (collect hardware via WMI/CIM → `POST /api/audits`) and
  `update` (re-pull itself from the server, replace, restart) commands
- Autostarts via the `HKCU\...\Run` key — **no admin rights, no installer**
- Idle footprint ≈ 55–70 MB RAM (script runs in a hosted PowerShell process)

### Install (one line, generated on `/agent`)

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -Command "iwr '<site>/agent/daemon.ps1?token=TAT' -OutFile '%LOCALAPPDATA%\TRACKER\daemon.ps1'; powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File '%LOCALAPPDATA%\TRACKER\daemon.ps1'"
```

The daemon saves itself to `%LOCALAPPDATA%\TRACKER\`, registers autostart and
keeps polling. Token + server URL are already baked into the served script.

### Remove

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File "$env:LOCALAPPDATA\TRACKER\daemon.ps1" -Uninstall
```

Or revoke the token on `/agent` — the daemon then gets `401` on every poll.

### One-shot collector (no daemon)

`agent/tracker-audit-agent.ps1` collects and posts once, then exits — useful for
spot checks or packaging to `.exe` with ps2exe:

```powershell
.\agent\tracker-audit-agent.ps1 -ApiUrl https://your-domain -Token tat_xxx
```

## Fleet Command Center — `/agent/commands`

Admin-only. The page shows agent online status (poll within 90 s = 🟢 online)
and provides 1-click fleet actions:

| Action | Effect |
|---|---|
| **Scan Now** (per laptop) | queues a `scan` command for that token only |
| **Scan All Laptops** | broadcast `scan` (`agent_token_id = null` → every daemon) |
| **Update All Agents** | broadcast `update`; each daemon re-pulls `/agent/daemon.ps1`, swaps the file and restarts hidden |

### Command API (Bearer token)

| Endpoint | Purpose |
|---|---|
| `GET /api/agent/inbox` | returns up to 10 pending commands for this token (plus broadcasts) |
| `POST /api/agent/commands/{id}/ack` | body `{"status":"done"\|"failed","result":{...}}` |

Each daemon poll also refreshes `last_used_at`, which powers the online/offline
indicator. Commands are never delivered twice: the daemon acks each one.

### Timing

Click → data visible:

| Phase | Time |
|---|---|
| Dashboard click → command queued | instant |
| Daemon picks it up (10 s poll) | 0–10 s |
| WMI/CIM hardware collection | 2–4 s |
| POST + server comparison + render | < 1 s |
| **Total (typical)** | **≈ 6–12 s** |


