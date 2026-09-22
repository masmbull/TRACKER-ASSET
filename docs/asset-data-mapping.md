# Asset Data Mapping — TRACKER-ASSET

Maps every legacy Excel column from `IT Assets.xlsx` (Details sheet) to its PostgreSQL destination.

## Critical Rule

Do NOT copy the Excel structure directly into PostgreSQL. Excel columns like `User Pertama`, `User Kedua`, `User Ketiga` must become relational `asset_assignments` records, not flat columns.

## Excel → Database Mapping

### Asset Core Fields

| Excel Column (Original) | DB Destination | Type | Notes |
|---|---|---|---|
| Company Name | `companies.name` | FK → companies | Create company if not exists |
| ASSET ID | `assets.asset_id` | string, unique | Business identifier |
| Kategori | `asset_categories.name` | FK → asset_categories | Create category if not exists |
| Merk | `assets.brand` | string | |
| Model | `assets.model` | string | |
| Serial Number | `assets.serial_number` | string, unique | Check duplicate |
| Hostname | `assets.hostname` | string | |
| Processor | `assets.processor` | string | Was in Specification |
| Storage | `assets.storage` | string | Was in Specification |
| RAM | `assets.ram` | string | Was in Specification |
| OS / Versi | `assets.os_version` | string | Was in Specification |
| LOKASI | `locations.name` | FK → locations | Create location if not exists |
| Tahun Pembelian | `assets.purchase_year` | integer | |
| Vendor | `assets.vendor` | string | |
| Harga (Rp) | `assets.purchase_price` | decimal | |
| Masa Pakai (thn) | `assets.useful_life_years` | integer | In years |
| Status Aset | `asset_statuses.name` | FK → asset_statuses | Active, Assigned, Repair, etc. |
| Garansi s/d | `assets.warranty_until` | date | Warranty end date |
| Tanggal Deploy | `assets.deploy_date` | date | |
| Lisensi SW/Office | `assets.software_license` | string | |
| MAC Address | `assets.mac_address` | string | Validate MAC format |
| IP Address (Statis) | `assets.static_ip` | string | Validate IP format |
| Admin/IT PIC | `assets.admin_it_pic` | FK → users | IT responsible person |
| Riwayat Perbaikan / Catatan | `asset_repairs` | text | Create repair record(s) |
| Tgl Retire/Disposal | `assets.retire_date` | date | |
| QR | `assets.qr_code` | string, nullable | QR URL |
| BARCODE | `assets.barcode` | string, nullable | |

### Assignment History (Legacy → Relational)

| Excel Column(s) | DB Destination | Type | Notes |
|---|---|---|---|
| User Pertama | `asset_assignments` (user_id) | FK → users | First assignment |
| Tgl Mulai (U1) | `asset_assignments.assigned_date` | date | |
| Tgl Serah (U1→U2) | `asset_assignments.returned_date` | date | |
| User Kedua | `asset_assignments` (user_id) | FK → users | Second assignment |
| Tgl Mulai (U2) | `asset_assignments.assigned_date` | date | |
| Tgl Serah (U2→U3) | `asset_assignments.returned_date` | date | |
| User Ketiga | `asset_assignments` (user_id) | FK → users | Third assignment |
| Tgl Mulai (U3) | `asset_assignments.assigned_date` | date | |
| Tgl Serah (U3→U4) | `asset_assignments.returned_date` | date | |
| USER SEKARANG | `assets.current_user_id` | FK → users | Current assignee |
| Tgl Mulai (Current) | `asset_assignments.assigned_date` | date | Current assignment start |
| Tgl Serah Terakhir | `asset_assignments.returned_date` | date, nullable | Current assignment end (null if still active) |

## Duplicate Detection

Potential duplicate identifiers during import:

- `asset_id` — exact match
- `serial_number` — exact match (case-insensitive)
- `hostname` — exact match (case-insensitive)

Import should show duplicates in preview before committing. Options: skip, update, or create new.

## Validation Rules

| Field | Validation |
|---|---|
| ASSET ID | Required, unique |
| Serial Number | Required, format validation, unique |
| Hostname | Required, format validation, unique |
| MAC Address | MAC format validation |
| IP Address | IP format validation |
| Purchase Year | Integer, reasonable range |
| Category | Must exist in `asset_categories` or be created |
| Location | Must exist in `locations` or be created |
| Company | Must exist in `companies` or be created |
| Status | Must exist in `asset_statuses` |

## Data Quality Checks

Flag records with:
- Missing Asset ID
- Missing Serial Number
- Missing Hostname
- Missing User (no assignment)
- Missing Location
- Missing Department (from Entra)
- Missing Purchase Year
- Invalid MAC address
- Invalid IP address
- Duplicate Serial Number
- Duplicate Asset ID
