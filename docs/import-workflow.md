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

## Lookup Names

A sheet exported from Excel prefixes its cells with a UTF-8 byte order mark, and a
name copied out of a browser or Word arrives carrying zero-width and
non-breaking spaces. Neither is visible on screen, but both make two spellings of
the same organisation compare as different strings.

Every name the importer writes goes through `App\Support\NameNormalizer` first,
and the four lookup models normalise again on the way in, so a cell that says
`﻿PT. Mahakarya Sukses Indonesia` resolves to the company that already exists
rather than filing a second one. Lookups use `whereNameIs()`, which matches the
normalised form and folds case the way a MySQL unique index compares.

This is not cosmetic. The register was carrying a real duplicate: two companies
whose names were byte-identical apart from a BOM, rendering identically in every
dropdown and signing identically on a generated document, with five assets
attached to the copy nobody could see.

Generated company codes use the highest `CMPnnn` number in use, not
`count() + 1`. The count drops when a company is deleted while the codes do not,
so `count() + 1` eventually asks for a code that is taken and fails the import
with an integrity error on a row that looks perfectly ordinary.

## Repairing Data That Already Split

`MasterDataNormalizer` (run by `2026_09_30_000017`) folds rows whose names are
identical once invisible characters are removed, repointing every foreign key at
the surviving row first. Referencing columns are discovered from the schema, so a
table that starts pointing at a lookup table later is covered without editing the
repair.

What it will not do is merge names that merely resemble each other. `PT. Mito`
and `PT Mito` are different strings after normalisation and both are left alone;
deciding they are one organisation is a judgement about the business, and
guessing it would destroy a distinction somebody may have made on purpose. The
same goes for capitalisation: `PABRIK BITUNG` and `Pabrik Bitung` stay two rows
until somebody says otherwise, and the migration names them in its output.

The repair is safe to re-run — it reports what it did and does nothing the second
time. It is also **not reversible**: the merged row's id and code are gone, and
the migration's `down()` says so rather than pretending otherwise. Generated
documents are deliberately left untouched, since a stored payload and its PDF are
a record of what was signed on a particular day.

## Lookup Name Uniqueness

`MasterDataController` has always validated `unique:locations,name` and
`unique:companies,name`, but the database did not enforce either: only
`asset_categories` and `asset_statuses` carried the index. That gap is why a
duplicate company could exist at all. Migration `2026_09_30_000017` closes it.

The index on `locations` is **skipped** while that table holds names differing
only by capitalisation, because MySQL's default collation folds case and would
reject an index that SQLite accepts — and the two databases disagreeing about
the same data is worse than one missing index. The migration prints the offending
names and logs a warning. Nothing else is lost: the model normalises on write,
the form's unique rule still applies, and on MySQL that rule already behaves
case-insensitively.

Note that `unique` comparisons are case-insensitive on MySQL and case-sensitive on
SQLite. That asymmetry is inherent to running one locally and the other in
production, and it is the reason the repair does not fold case variants for you.

## CLI

```bash
# Analyse a spreadsheet without importing anything
php artisan excel:check "IT Assets.xlsx"
```

## Exporting the Local Register to Another Environment

`php tools/export-for-import.php` writes `storage/app/imports/local-assets-merged.xlsx`:
the local database in the format this workflow's import screen accepts, for
populating a fresh deployment from local data.

The app's own export (`/assets/export`) cannot be re-imported: it speaks modern
headers (`Company`, `OS Version`, `Status`) while `config/asset-import.php` speaks
the legacy sheet's (`Company Name`, `OS / Versi`, `Status Aset`), so half the
columns land unmapped and are dropped. The tool reuses the original sheet's 39
headers verbatim instead — the result normalises onto `column_map` by construction
— and merges rather than dumps, because neither source is complete on its own:
hardware and status come from the database (the agent has re-reported them since
the original import, and the sheet's `Status Aset` column is empty for all 272
rows), while custody columns (`User Pertama`, `USER SEKARANG`, `Admin/IT PIC`,
`Riwayat Perbaikan`) come from the sheet, because the original seeder never read
them.

Identity is cleaned before writing, because the importer skips any row repeating
`asset_id`, `serial_number` or `hostname` — inside the file or against the
register. The register holds junk serials from WMI (`System Serial Number` six
times, bare `-` seven) and generic hostnames (`MSI` six times), so the tool empties
junk and repeated serials (a warning, which still imports) and suffixes repeated
hostnames (`MSI-2`; hostname cannot be blanked — it is required). Without that,
261 local rows would arrive as roughly 230. Verify what is about to be shipped:

```bash
php tools/export-for-import.php
php artisan excel:check "storage/app/imports/local-assets-merged.xlsx"
```

Custody columns are matched by serial, then hostname, never by `asset_id` — 247
of 261 rows carry generated ids. Repeated keys break the tie with the second key
and otherwise stay blank: a wrong custody row is worse than a missing one.

## Safety Rules

- Nothing is written until the preview is confirmed.
- Existing records are never updated or deleted by an import.
- The register is re-checked at confirm time (duplicates may have appeared meanwhile).
- Every import is recorded in `activity_logs` (action `IMPORT`).
- Uploaded files are staged under `storage/app/imports/` and deleted after confirm.
