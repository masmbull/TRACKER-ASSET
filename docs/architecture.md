# Architecture — TRACKER-ASSET

## Overview

TRACKER-ASSET is a web-based IT Asset Management system for MITO Group. It replaces the legacy workflow of Excel spreadsheets, a Windows audit `.exe`, and manual FAT/BAST document creation.

## Technology Stack

| Layer | Technology |
|---|---|
| Backend | Laravel (PHP) |
| Database | PostgreSQL |
| Frontend | Blade + Livewire + Alpine.js + Tailwind CSS |
| Cache | Redis |
| Mail | Mailpit (local) |
| Infrastructure | Docker Compose |
| Authentication | Microsoft Entra ID (OAuth/OIDC) |

## Architecture Diagram

```text
Browser (Chrome/Brave)
   │ HTTPS
   ▼
Nginx (reverse proxy, production) / Laravel dev server (local :8000)
   │
   ▼
Laravel Application
   ├── HTTP Kernel
   ├── Routes
   ├── Controllers / Livewire Components
   ├── Services (business logic)
   ├── Models / Eloquent ORM
   └── Policies / Gates (RBAC)
   │
   ├── PostgreSQL (primary data store)
   ├── Redis (cache, sessions, queue)
   └── File Storage (attachments; metadata in DB, files in SharePoint eventually)
```

## Key Principles

1. **ONE ASSET = ONE SOURCE OF TRUTH** — PostgreSQL asset record is the single source of truth. All other data references it.
2. **Excel is import/export only** — never the primary database.
3. **Business logic stays in Laravel** — not in Excel formulas, not in the audit agent, not in the client.
4. **History is immutable** — asset changes are recorded, never silently overwritten.
5. **Local first** — develop and test entirely with Docker Compose before any production deployment.

## Module Breakdown

| Module | Description |
|---|---|
| Dashboard | KPI cards, charts, warnings, recent activity |
| Asset Management | CRUD, search, filter, import/export |
| Asset Detail | Overview, Hardware, Assignment History, Audit, Repair History, Documents, Activity Log |
| Audit | Compare registered vs actual device values, save audit history |
| Transfer | Transfer workflow with assignment history |
| Repair | Repair history tracking |
| FAT / BAST | Document generation from asset data, PDF output |
| Documents / Attachments | File attachments linked to assets |
| Master Data | Companies, Locations, Categories, Users, Statuses |
| Activity Log | Immutable audit trail of all mutations |

## Environment

- **Local:** Docker Compose (Windows + Docker Desktop)
- **Production:** Docker Compose on the office Ubuntu server behind Nginx/HTTPS

## Performance Targets

| Operation | Target |
|---|---|
| Asset list | < 2 sec |
| Search | < 1 sec |
| Dashboard | < 3 sec |
| PDF generation | < 5 sec |

Supported scale: 10,000+ assets, 100+ concurrent users.
