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
| Activity Log (immutable trail on every mutation) | ✅ Done — filter + pagination |
| Repair History | ✅ Done |
| Document Templates | ✅ Done (CRUD) |
| Audit (server-side MATCH/MISMATCH comparison, mismatch detail) | ✅ Done — result computed by the system |
| FAT / BAST (auto-populate, configurable numbering, A4 PDF, preview/download/print) | ✅ Done — see `docs/document-workflow.md` |
| Document Generation (auto-populate) | ✅ Done |
| QR Code | ✅ Done — per-asset PNG, encodes asset detail URL |
| Master Data CRUD (Companies/Locations/Categories/Statuses) | ✅ Done — delete protection on referenced records |
| Users management (local) | ✅ Done — admin only |
| Attachments Upload | ✅ Done — validated, logged |
| Activity Log page | ✅ Done — filter + pagination |
| IKE_BAST Inspection Table | ✅ Done — multi-line receiving inspection per asset, approve/reject, print-friendly — see `docs/ike-bast-inspection.md` |
| Audit Agent API | ✅ Done — token-authed JSON endpoint for the lightweight Windows collector — see `docs/audit-api.md` |
| Microsoft Entra ID SSO | ✅ Done — OAuth/OIDC code flow, auto-provision, config-gated (set `ENTRA_ENABLED=true`) |
| Microsoft Graph / SharePoint integration | 🟡 Graph profile sync done (SSO); file storage to SharePoint pending |
| Reports page | ✅ Done — summary, breakdowns, audit coverage, CSV export |
| CI/CD | ✅ CI done (GitHub Actions: PHPUnit + Pint); CD to VPS pending go-live |

## Tests

```bash
php artisan test
```

## Repository

`https://github.com/masmbull/TRACKER-ASSET`

