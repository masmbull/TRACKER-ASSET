# Import Workflow — TRACKER-ASSET

Excel import (PRD §25, §26). Excel is import/export only — never the primary database.

## Flow

```text
Upload Excel (xlsx/xls/csv)
  ↓
File staged at storage/app/imports/
  ↓
Analyze (AssetImportService)
  ├── Detect sheet ("Details")
  ├── Map legacy columns (config/asset-import.php)
  ├── Validate rows
  ├── Detect duplicates (file + register)
  ↓
Preview
  ├── Rows detected / Valid / Warning / Error / Duplicate
  └── Row-by-row issues
  ↓
User confirms
  ↓
Import (transaction per row)
  ├── Only VALID + WARNING rows are inserted
  ├── Duplicates + errors are SKIPPED (never overwritten)
  ├── Lookup values created on the fly (company/category/status/location)
  ├── Assignment history rebuilt into asset_assignments
  └── ActivityLog IMPORT written per asset + one summary log
```

## Column Mapping

Legacy header names are normalised (lowercase, non `a-z0-9` stripped) and matched
against `config/asset-import.php`. Unknown columns are listed in the preview as
"Ignored columns". Example:

```text
"Serial Number"      -> serialnumber      -> assets.serial_number
"Harga (Rp)"         -> hargarp           -> assets.purchase_price
"Masa Pakai (thn)"   -> masapakaithn      -> assets.useful_life_years
"Tgl Serah (U1→U2)"  -> tglserahu1u2      -> asset_assignments.returned_date
"USER SEKARANG"      -> usersekarang      -> assets.current_user_id (+ assignment)
```

Full destination table: see `docs/asset-data-mapping.md`.

## Row Classification

| Status | Meaning | Imported? |
|---|---|---|
| valid | All required fields present, no duplicates | Yes |
| warning | Importable, but incomplete (e.g. missing serial, unknown user) | Yes |
| error | Missing identity (no hostname, or neither Asset ID nor Serial) | No |
| duplicate | Asset ID / Serial / Hostname already in the register or earlier in the file | No |

Duplicates are detected — never blindly rejected or overwritten (PRD §15).

## People Resolution

Legacy sheets only contain display names. Users are matched case-insensitively
by name/email. Unknown people get a placeholder account (`{slug}@imported.local`,
`role=viewer`, `is_active=false`) so the assignment history survives; placeholder
accounts can never log in (PRD §13, §39). Disable this behaviour via
`create_missing_users` in `config/asset-import.php`.

## CLI

```bash
# Analyse a spreadsheet without importing anything
php artisan excel:check "IT Assets.xlsx"
```

## Safety Rules

- Nothing is written until the preview is confirmed.
- Existing records are never updated or deleted by an import.
- The register is re-checked at confirm time (duplicates may have appeared meanwhile).
- Every import is recorded in `activity_logs` (action `IMPORT`).
- Uploaded files are staged under `storage/app/imports/` and deleted after confirm.
