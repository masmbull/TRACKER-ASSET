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

## Agent Client (PRD §11 — lightweight audit agent)

`agent/tracker-audit-agent.ps1` collects hardware identity from any Windows
machine via WMI/CIM and posts it to this API. Nothing is collected beyond the
identity fields listed above.

```powershell
# Run once (token issued via php artisan audit:token)
.\agent\tracker-audit-agent.ps1 -ApiUrl https://your-domain -Token tat_xxx

# Or via environment variables
$env:TRACKER_AUDIT_TOKEN = 'tat_xxx'
$env:TRACKER_API_URL     = 'https://your-domain'
.\agent\tracker-audit-agent.ps1
```

### Package as `.exe` (optional, for distribution)

```powershell
Install-Module ps2exe -Scope CurrentUser
Invoke-ps2exe .\agent\tracker-audit-agent.ps1 .\agent\tracker-audit-agent.exe `
    -noConsole:$false -requireAdmin:$false
```

Distribute `tracker-audit-agent.exe` with the token pre-configured
(e.g. via env var set by deployment tooling). Revoke any token any time with
`is_active = false` on `agent_api_tokens`.

