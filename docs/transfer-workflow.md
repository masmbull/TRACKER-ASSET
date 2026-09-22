# Transfer Workflow — TRACKER-ASSET

## Purpose

Handle asset transfers between users and locations, preserving complete assignment history. Every transfer is a database transaction.

## Transfer Flow

```text
Asset Detail
  ↓
Transfer Asset
  ↓
Current User → New User
From Location → To Location
  ↓
Transfer Date, Condition, Accessories, Notes
  ↓
Submit
  ↓
Transaction (atomic):
  ├── Close previous assignment (set returned_date)
  ├── Create new assignment (new start date)
  ├── Update assets.current_user_id
  ├── Update assets.location_id
  ├── Create asset_transfer record
  └── Create activity log
  ↓
Generate document (optional: Transfer, BAST, etc.)
```

## Transfer Form Fields

| Field | DB Destination | Notes |
|---|---|---|
| Transfer Number | `asset_transfers.transfer_number` | Auto-generated: AST/TRF/YYYY/XXXXX |
| Transfer Date | `asset_transfers.transfer_date` | |
| Company | `asset_transfers.company_id` | FK |
| Asset | `asset_transfers.asset_id` | FK |
| From User | `asset_transfers.from_user_id` | FK |
| From Department | `asset_transfers.from_department` | |
| From Location | `asset_transfers.from_location_id` | FK |
| To User | `asset_transfers.to_user_id` | FK |
| To Department | `asset_transfers.to_department` | |
| To Location | `asset_transfers.to_location_id` | FK |
| Condition | `asset_transfers.condition` | |
| Accessories | `asset_transfers.accessories` | |
| Notes | `asset_transfers.notes` | |
| Submitted By | `asset_transfers.submitted_by` | FK |
| Approved By | `asset_transfers.approved_by` | FK, nullable |
| Received By | `asset_transfers.received_by` | FK, nullable |

## Assignment History (Relational)

Do NOT use columns like `User 1`, `User 2`, `User 3`. Use `asset_assignments`:

| Column | Description |
|---|---|
| `asset_id` | Which asset |
| `user_id` | Assigned user |
| `assigned_date` | When assigned |
| `returned_date` | When returned (null = currently assigned) |
| `from_location_id` | Origin location |
| `to_location_id` | Destination location |
| `transfer_id` | Links to transfer record |
| `notes` | Additional notes |
| `created_by` | Who created the record |

## Transfer Status

- **Pending** — submitted, awaiting approval
- **Approved** — approved, transaction completed
- **Completed** — received by recipient
- **Rejected** — rejected

## Rules

- Every transfer must preserve history (no overwriting)
- Use database transactions for all ownership changes
- Previous assignment gets `returned_date` set
- New assignment gets `assigned_date` set
- Activity log records the transfer
- Transfer document numbering is configurable and prevents duplicates
