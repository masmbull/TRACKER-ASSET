# IKE_BAST Inspection — TRACKER-ASSET

## Purpose

Multi-line acceptance / receiving inspection table per asset. Each session
records one physical inspection with as many line items as needed — the
item-level equivalent of the asset audit (which compares one device against
the register) and of FAT/BAST documents (which certify the result).

There is no legacy Excel template for IKE_BAST in the repository, so the form
follows the common industry pattern for receiving inspection:

- header per session (what, when, by whom, for which asset/transfer)
- table of line items (what was inspected, how much, condition, test result)
- approval (approve / reject) and print-friendly output

## Data Model

```text
ike_bast_inspection_sessions
  ├── id, asset_id (FK → assets), transfer_id (FK → asset_transfers)
  ├── created_by (FK → users)
  ├── session_number (unique, e.g. IKE/20260922/001)
  ├── inspection_date, kind (IKE_BAST | BAST | FAT | CUSTOM)
  ├── status (draft | submitted | approved | rejected)
  └── notes
        └── ike_bast_inspection_line_items
              ├── session_id (FK)
              ├── seq, item_desc, item_code, barcode, serial_number
              ├── unit, qty_planned, qty_received
              ├── physical_condition
              ├── test_result (pass | fail | na)
              ├── is_defective (boolean)
              └── remarks
```

## Flow

```text
Asset Detail
  ↓
Start Inspection (pre-filled from the register)
  ↓
Add rows (dynamic table), set qty / condition / test result
  ↓
Save → session is stored as "submitted"
  ↓
Review → Approve / Reject
  ↓
Print-friendly inspection table (signature blocks included)
```

## Routes

- `GET  /ike-bast/inspection` — list + filters (session number, kind, status, asset)
- `GET  /ike-bast/inspection/create/{asset}` — blank form pre-filled from the asset
- `POST /ike-bast/inspection` — store session + lines
- `GET  /ike-bast/inspection/{session}` — detail with approve/reject
- `PUT  /ike-bast/inspection/{session}` — edit (replaces lines)
- `POST /ike-bast/inspection/{session}/approve` — approve / reject
- `GET  /ike-bast/inspection/{session}/print` — print-friendly table

## Authorization

Inspection actions use the `manageAttachments` policy ability (admin + support).
The navigation "Inspections" link is shown to roles with `manage-master-data`.
