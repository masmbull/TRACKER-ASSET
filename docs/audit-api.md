# Audit API — TRACKER-ASSET

## Purpose

Serve the audit agent (`TrackerAgent.exe`, PRD §11 — preferred audit
architecture). The client only collects hardware data and posts JSON;
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

## Fleet rollout — `/agent`

Admin-only page (`/agent`, nav → Master Data → Audit Agents):

1. Create a token (name + expiry) → download **TrackerAgent.exe** for that
   token. The zero-config installer double-clicked on the target laptop asks
   for nothing: the server URL and token travel in a config trailer appended to
   the binary itself.
2. The download route validates the token on every request: unknown or revoked
   tokens get `404`, so a leaked/removed token cannot pull the binary.
3. **Revoke** any token on the spot: affected laptops get `401` on their next poll.
4. `last_used_at` refreshes on every authenticated request and powers the
   online indicator on `/agent/commands`.

Only the SHA-256 hash is stored; plaintext appears once at creation.

## Agent Client (PRD §11) — resident daemon

`agent/TrackerAgent.exe` is a **resident** background collector:

- Runs **hidden** — no console window, no tray icon, no popup
- Polls the command inbox every 10 seconds
- Pushes a **telemetry heartbeat** every 60 seconds to `POST /api/agent/heartbeat`
- Executes `scan` (collect hardware via WMI/CIM → `POST /api/audits`) and
  `update` (re-pull itself from the server, replace, restart) commands
- Autostarts via the `HKCU\...\Run` key — **no admin rights, no installer**

### Telemetry heartbeat

The inbox poll only carries commands, so on its own a laptop nobody ever
clicks "Scan Now" on would keep the same snapshot forever and Live Fleet would
drift out of date. The daemon therefore pushes its own reading every 60 seconds.

`POST /api/agent/heartbeat` accepts the same readings as `/api/audits` but:

- **never writes an `asset_audits` row** — a minute of history per laptop would
  bury real audits. Only the `agent_telemetries` snapshot is refreshed.
- applies a **partial update**: a reading the agent did not send keeps its last
  known value, so a laptop with no battery (which reports `""` every minute)
  does not lose its other data.
- returns `202` when the device has never completed a scan — there is no fleet
  row to update yet, and the response asks the agent to run a full scan rather
  than inventing a half-populated row.

`AgentTelemetry::isOnline()` uses a **2-minute** window: twice the heartbeat
interval, so exactly one dropped ping is tolerated before a machine flips to
offline. The fleet page also treats a recent inbox poll (90 s) as proof of life,
so an agent predating the heartbeat is not reported offline mid-rollout.

See `tests/Feature/LiveFleetTest.php` for the heartbeat contract.

### Deep inventory — `POST /api/agent/fingerprint`

The heartbeat is deliberately cheap. The installed-software list alone is several
hundred rows and costs a registry walk, so putting it on a 60-second timer would
slow the poll loop across the whole fleet and bloat the database. Deep inventory
is therefore a **separate endpoint**, posted on the first poll after install and
then **once every 24 hours**.

| Field group | Examples |
|---|---|
| Security | `antivirus_product`, `antivirus_enabled`, `antivirus_signature_age_days`, `firewall_enabled`, `firewall_profiles_off`, `uac_enabled`, `tpm_present`, `bitlocker_status`, `rdp_enabled`, `local_admin_count` |
| Licensing | `windows_edition`, `license_channel`, `activation_status` |
| OS state | `os_build`, `os_installed_at`, `last_boot_at`, `pending_reboot`, `domain`, `timezone` |
| Storage / capacity | `disk_type`, `smart_health`, `smart_predicted_failure`, `cpu_cores`, `cpu_physical_cores`, `monitor` |
| Inventory | `software[]` (`name`, `version`, `publisher`, `installed_at`), `software_count`, `startup_count` |

`software[]` is replaced wholesale on every report. A diff would be cheaper but
the registry gives no stable per-entry key, and a stale row is worse than a
re-scan: keeping an entry for an uninstalled program would make the fleet report
software that is no longer on the machine.

Two behaviours worth knowing:

- **Unparsable booleans become `null`, not `false`.** The agent sends strings, so
  values are normalised before validation (Laravel's `boolean` rule only accepts
  real booleans and `0`/`1`, which would 422 the entire report). An unreadable
  sensor must not destroy several hundred valid software rows, and `null` stays
  distinguishable from a genuine "off".
- **The asset link is resolved by identity, then by telemetry.** The report is
  matched against `assets.asset_id` / `serial_number` / `hostname` / `mac_address`
  in that order, falling back to the device's own `agent_telemetries` row.
  Every one of those four keys needs a rule in `AgentFingerprintController::rules()`
  or Laravel drops it from the validated payload before the lookup runs — the
  `mac_address` case was exactly that, and it made the code look capable of MAC
  matching while the field could never arrive.
- **Unusable tokens are resolved, not left hanging.** `agent:resolve-stale-commands`
  (every 5 min) fails a pending command whose token is revoked **or expired**,
  since the middleware rejects both with 401 before the inbox is read.
  `audit:sweep` filters on the same rule so it never queues a scan that cannot
  be delivered. Both need the scheduler running — see `docs/deployment.md`.

`AgentFingerprint::findings()` turns the readings into operator-facing badges
(antivirus off, firewall off, BitLocker off, disk predicted failure, unlicensed
Windows, stale signatures, pending reboot, RDP enabled). A device that has simply
not reported is **not** a finding — that is a liveness question answered by
`AgentTelemetry::isOnline()`.

> Collector sources are Windows-version-sensitive and were chosen after the
> obvious ones produced wrong answers. Defender state and signature age come from
> `MSFT_MpComputerStatus`; the SecurityCenter2 `productState` bitfield reads a
> fully enabled Defender as "off" at mask `0x10` (the real flag is `0x1000`).
> Firewall state comes from `MSFT_NetFirewallProfile` because `FirewallProduct`
> is empty and the `EnableFirewall` registry values are gone on Windows 11.
> TPM presence falls back to the `TPM\WMI` service key because `Win32_Tpm` is an
> "Invalid class" on most consumer machines. Software is read from the registry
> Uninstall keys and never from `Win32_Product`, which triggers an unsolicited MSI
> repair across the machine.

Browse it at `/agent/{token}/fingerprint`; the fleet page shows a per-device
finding summary and a `Security Findings` KPI.

See `tests/Feature/FingerprintTest.php` for the endpoint contract.

## Fleet Command Center — `/agent/commands`

Admin-only. The page shows agent online status (poll within 90 s = 🟢 online)
and provides 1-click fleet actions:

| Action | Effect |
|---|---|
| **Scan Now** (per laptop) | queues a `scan` command for that token only |
| **Scan All Laptops** | broadcast `scan` (`agent_token_id = null` → every daemon) |
| **Update All Agents** | broadcast `update` carrying the exe download URL; each daemon re-pulls it, swaps the exe and restarts. The agent honours that URL and falls back to `/agent/download` when the command has no payload (e.g. `--update` run by hand). |

### Agent API (Bearer token)

| Endpoint | Purpose |
|---|---|
| `GET /api/agent/inbox` | returns up to 10 pending commands for this token (plus broadcasts) |
| `POST /api/agent/commands/{id}/ack` | body `{"status":"done"\|"failed","result":{...}}` |
| `POST /api/agent/commands/{id}/progress` | body `{"stage":"...","progress":62}` — long-running queued work |
| `POST /api/agent/install-progress` | body `{"command":"install"\|"update","status":...,"stage":...,"progress":...}` |
| `POST /api/agent/heartbeat` | refreshes the Live Fleet snapshot; no audit row written |

Each daemon poll also refreshes `last_used_at`, which powers the online/offline
indicator. Commands are never delivered twice: the daemon acks each one.

### Progress reporting

A queued command is invisible until something happens to it, so an install that
takes four minutes looks identical to one that never started. Two endpoints
close that gap, and they are separate because the work is.

**`/api/agent/commands/{id}/progress`** is for a command the daemon pulled from
its inbox. The first report flips the row to `in_progress` and stamps
`started_at`; later reports only overwrite `stage` / `progress`. `progress` is
`0–100`; `stage` is free text the agent owns, so the server never has to be
taught a new vocabulary. Reporting against a command another token owns is
`403`, and reporting against an already-settled command is a no-op that returns
`200` — a late report from an agent that raced an ack must not resurrect a
finished row or fail the daemon's poll.

**`/api/agent/install-progress`** is for `install` and `update`, which are *not*
queued commands: the operator started them by running the installer, so there is
no inbox row to report against. It finds the device's open `install`/`update` row
and adopts it. Adoption rather than insert is deliberate — re-running the
installer must attach to the row already in flight, not leave two half-finished
installs with no way to tell which is real.

| Field | Meaning |
|---|---|
| `stage` | human-readable line the fleet page shows under the bar |
| `progress` | `0–100`, drives the bar width |
| `status` | `in_progress` \| `done` \| `failed` |

Uninstall is the exception: the agent reports `Removing the agent...` **before**
acking, because it cannot report after deleting its own executable. The ack is
the confirmation, and it carries a fixed marker string the server matches before
burning the token — see `UNINSTALL_DONE_MARKER`.

### Stalled commands

A command sitting in `in_progress` with no progress report for
`IN_PROGRESS_GRACE_MINUTES` (2 minutes) is treated as abandoned and handed back
out on the next poll. A daemon killed mid-install does not ack, so without this
the command would be stuck forever and the device unreachable by any later
action. The grace period is what keeps a healthy but slow command from being
duplicated, and the fleet page shows the same condition in red as **stalled** so
an operator sees it.

Both sides read the same number. `AgentCommand::STALL_GRACE_MINUTES` is the only
definition, and the inbox's `IN_PROGRESS_GRACE_MINUTES` is an alias for it rather
than a second literal — it was two copies once, held together by a comment, and a
drift between them would have failed silently. That is the detail that matters: if
the page and the retry loop disagreed about what "stalled" means, the page would
show a stalled command as healthy while the server had already handed it to a
second agent.

### Timing

Click → data visible:

| Phase | Time |
|---|---|
| Dashboard click → command queued | instant |
| Daemon picks it up (10 s poll) | 0–10 s |
| WMI/CIM hardware collection | 2–4 s |
| POST + server comparison + render | < 1 s |
| **Total (typical)** | **≈ 6–12 s** |


