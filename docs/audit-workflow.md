# Audit Workflow — TRACKER-ASSET

## Purpose

Compare registered asset data against actual device data. Record discrepancies for review and decision-making. Do NOT silently overwrite registered data.

## Architecture

```text
Windows Device
       ↓
Lightweight Audit Agent (tracker-audit-agent.exe) [optional]
       ↓ (HTTP API, JSON)
Laravel API
       ↓
PostgreSQL (store audit record)
       ↓
Dashboard (display results)
```

If browser APIs can reliably collect device info (e.g., via a web-based audit page), an agent may not be necessary for MVP.

## Audit Flow

```text
Select / Scan Asset
       ↓
Asset Detail Loaded (registered data shown)
       ↓
Technician checks physical device
       ↓
Collect actual device data:
  - Hostname
  - Serial Number
  - Brand
  - Model
  - Processor
  - RAM
  - Storage
  - OS
  - MAC Address
  - IP Address
       ↓
Comparison (server-side in Laravel)
       ↓
Show results: MATCH / MISMATCH / NEW DEVICE / UNKNOWN DEVICE / INCOMPLETE
       ↓
Technician fills notes, attaches photo (optional)
       ↓
Submit Audit
       ↓
Save Audit Record + Asset History
       ↓
Dashboard Updated
```

## Audit Result Types

| Result | Meaning |
|---|---|
| MATCH | All collected data matches registered data |
| MISMATCH | One or more fields differ (shown per-field) |
| NEW DEVICE | Device registered but data suggests different asset |
| UNKNOWN DEVICE | Device not in system |
| INCOMPLETE | Could not collect all fields |

## Mismatch Example

```text
MISMATCH

RAM:
  Registered = 8 GB
  Actual = 16 GB

MAC Address:
  Registered = AA:BB:CC:DD:EE:01
  Actual = AA:BB:CC:DD:EE:02
```

User decides whether to update registered data. Update is explicit, never automatic.

## Audit Data Collected

- Hostname, Serial Number, Brand, Model, Processor, RAM, Storage, OS, MAC Address, IP Address
- Optional: photo attachment
- Notes from technician
- Timestamp and auditor identity

## Audit Record Fields

- `asset_id` — which asset
- `audit_date` — when
- `auditor_id` — who
- Device fields (actual values collected)
- `result` — MATCH / MISMATCH / etc.
- `notes` — technician notes
- `photo_path` — optional photo
- `status` — Draft, Submitted, Approved

## Audit History

Every audit is saved and visible from:
- Asset Detail → Audit tab
- Dashboard warnings (Asset Not Audited, Audit Mismatch)
