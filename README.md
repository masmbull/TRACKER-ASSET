# TRACKER-ASSET

Web-based Asset Tracker + IT Audit + FAT Generator for MITO Group.

## Goal

Replace the Windows `ITAuditMITO` workflow with a browser-based system that centralizes asset tracking, audit history, dashboarding, and FAT generation.

## Tech Stack

- **Backend:** Laravel 13
- **Database:** PostgreSQL (local: SQLite for development)
- **Frontend:** Blade + Livewire + Alpine.js + Tailwind CSS
- **Infrastructure:** Docker Compose
- **Auth:** Microsoft Entra ID (production) / Local auth (development)
- **Excel:** maatwebsite/excel + PhpSpreadsheet (import/export only)

## Quick Start

```bash
docker compose up -d --build
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed
```

Or locally with PHP/Composer:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan serve --port=8000
```

Seeded local accounts (password `password`): `shohibul.anwar@mito.co.id` (admin), `it-support@mito.co.id` (support), `it-technician@mito.co.id` (technician), `viewer@mito.co.id` (viewer).

## Module Status

| Module | Status |
|---|---|
| Authentication (local dev mode) | ✅ Done |
| Asset CRUD + search/filter/sort/pagination | ✅ Done |
| Asset Detail (overview, assignment/audit/repair history, documents, activity log) | ✅ Done |
| Excel Import (mapping, validation, preview, duplicate detection, confirm) | ✅ Done — see `docs/import-workflow.md` |
| Excel Export (filtered, logged) | ✅ Done |
| Dashboard (KPI, charts breakdown, warnings, recent activity) | ✅ Done |
| Transfer Workflow (atomic, assignment history, sequential numbering) | ✅ Done |
| RBAC (admin / support / technician / viewer via policies + gates) | ✅ Done |
| Activity Log (immutable trail on every mutation) | ✅ Done (dedicated page pending) |
| Repair History | ✅ Done |
| Document Templates | ✅ Done (CRUD) |
| Document Generation (auto-populate) | 🟡 Partial (manual entry) |
| Audit Comparison (server-side MATCH/MISMATCH) | ⬜ Pending |
| FAT / BAST PDF | ⬜ Pending |
| QR Code | ⬜ Pending |
| Master Data CRUD (Companies/Locations/Categories/Users/Statuses) | ⬜ Pending |
| Attachments Upload | ⬜ Pending |
| Microsoft Entra ID SSO | ⬜ Pending |
| Microsoft Graph / SharePoint integration | ⬜ Pending |
| Reports page | ⬜ Pending |
| CI/CD | ⬜ Pending (after local MVP is stable) |

## Tests

```bash
php artisan test
```

## Repository

`https://github.com/masmbull/TRACKER-ASSET`

