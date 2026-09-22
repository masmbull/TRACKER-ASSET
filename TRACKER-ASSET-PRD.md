# TRACKER-ASSET — Product Requirements Document

**Project:** TRACKER-ASSET  
**Repository:** `https://github.com/masmbull/TRACKER-ASSET`  
**Framework:** Laravel  
**Database:** PostgreSQL  
**Local development:** Docker Compose  
**Production:** Docker Compose on Ubuntu VPS  
**Authentication:** Microsoft Entra ID / Microsoft 365  
**Primary users:** IT Support / IT Asset Administrator

> ## CRITICAL: LOCAL FIRST
>
> This project is complex. **DO NOT DEPLOY TO THE VPS DURING INITIAL DEVELOPMENT.**
>
> Build, run, and test the complete MVP locally with Docker first. Production deployment happens only after local acceptance criteria and tests pass.

---

# 1. Product Overview

TRACKER-ASSET is a web-based IT Asset Management system intended to replace the current workflow based on:

- `IT Assets.xlsx`
- Windows audit application `.exe`
- manual asset transfer Excel
- manual FAT/BAST documents
- manual asset history tracking

The goal is to create one source of truth for IT assets.

### Current workflow

```text
Laptop
  ↓
Windows EXE
  ↓
Excel
  ↓
Manual editing
  ↓
Manual FAT / BAST
```

### Target workflow

```text
Browser
  ↓
TRACKER-ASSET
  ├── Asset Database
  ├── Dashboard
  ├── Audit
  ├── Transfer
  ├── Repair History
  ├── FAT / BAST
  └── Reports
        ↓
   PostgreSQL
```

---

# 2. Business Problems

Current Excel-based asset management creates these problems:

- difficult search and filtering
- difficult ownership tracking
- asset history stored horizontally
- manual copy/paste
- inconsistent data
- difficult audit tracking
- audit result separated from asset register
- manual transfer documents
- manual FAT/BAST creation
- no centralized activity history
- high risk of human error

TRACKER-ASSET must eliminate repetitive data entry wherever possible.

---

# 3. Product Goals

## G1 — Centralized Asset Database

All IT assets must be stored in PostgreSQL.

## G2 — Asset Dashboard

IT Support must be able to see asset status, ownership, location, audit status, and warnings.

## G3 — Asset CRUD

Users with permission can:

- create
- view
- edit
- archive
- retire
- dispose

assets.

## G4 — Asset History

All ownership and transfer history must be preserved.

## G5 — Automated Audit

Hardware information collected from devices must be compared with registered asset information.

## G6 — Automated FAT / BAST

Documents should be generated directly from asset/transfer data.

## G7 — Search and Tracking

Assets must be searchable by:

- Asset ID
- Serial Number
- Hostname
- User
- Email
- Department
- Location
- Company
- Category
- Status
- MAC Address
- IP Address

---

# 4. Non-Goals

MVP does NOT need to become:

- accounting software
- procurement system
- ERP
- HRIS
- payroll
- general warehouse management
- mobile native application

Focus on:

**IT Asset Management + Audit + Transfer + FAT/BAST + Reporting**

---

# 5. Technology Stack

## Backend

**Laravel**

Use the current Laravel version compatible with the selected PHP version.

## Database

**PostgreSQL**

## Frontend

Prefer a Laravel-native architecture:

- Blade
- Livewire
- Alpine.js
- Tailwind CSS

Do not introduce Next.js/React unless there is a strong technical reason.

## Infrastructure

Docker Compose.

Local minimum:

```text
app
postgres
redis
mailpit
```

Nginx may be included if useful.

## Production

Ubuntu VPS:

```text
Docker
Docker Compose
Nginx / reverse proxy
HTTPS
PostgreSQL
Redis
```

Do not introduce Kubernetes for MVP.

---

# 6. Local Development Requirement

The first target is:

```text
Windows
  ↓
Docker Desktop
  ↓
Laravel
PostgreSQL
Redis
Mailpit
```

Expected URL:

```text
http://localhost:8000
```

The project must provide:

```text
Dockerfile
docker-compose.yml
.env.example
README.md
```

Developer should be able to start the project with approximately:

```bash
docker compose up -d --build
```

Then:

```bash
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed
```

Exact container commands may be adjusted to the final Docker architecture.

---

# 7. Development Phases

## Phase 0 — Discovery

Before coding:

1. inspect repository
2. inspect PRD
3. inspect supplied Excel files
4. inspect existing EXE if available
5. understand legacy fields
6. create normalized data model
7. document architecture

## Phase 1 — Local Foundation

Build:

- Laravel
- Docker
- PostgreSQL
- Redis
- Mailpit
- migrations
- seeders
- authentication skeleton
- tests

## Phase 2 — Asset Management

Build:

- asset CRUD
- asset detail
- search
- filter
- pagination
- assignment history
- repair history

## Phase 3 — Migration

Build:

- Excel import
- mapping
- validation
- duplicate detection
- preview
- import confirmation
- Excel export

## Phase 4 — Dashboard

Build:

- KPI
- charts
- warnings
- recent activity

## Phase 5 — Transfer

Build:

- transfer workflow
- assignment history
- transfer documents

## Phase 6 — Audit

Build:

- audit API
- audit agent
- hardware collection
- comparison
- audit history

## Phase 7 — FAT / BAST

Build:

- templates
- automatic data population
- document numbering
- PDF generation
- preview/download

## Phase 8 — Microsoft Integration

Build:

- Entra authentication
- Microsoft Graph
- user directory
- SharePoint document integration

## Phase 9 — CI/CD

Only after local MVP is stable:

```text
GitHub
 ↓
GitHub Actions
 ↓
Tests
 ↓
Docker image
 ↓
VPS deployment
```

---

# 8. Existing Asset Excel

Source file:

`IT Assets.xlsx`

The existing `Details` sheet contains approximately 39 columns, including:

```text
Company Name
ASSET ID
Kategori
Merk
Model
Serial Number
Hostname
Processor
Storage
RAM
OS / Versi
LOKASI
Tahun Pembelian
Vendor
Harga (Rp)
Masa Pakai (thn)
Status Aset
Garansi s/d
Tanggal Deploy
Lisensi SW/Office
MAC Address
IP Address (Statis)
User Pertama
Tgl Mulai (U1)
Tgl Serah (U1→U2)
User Kedua
Tgl Mulai (U2)
Tgl Serah (U2→U3)
User Ketiga
Tgl Mulai (U3)
Tgl Serah (U3→U4)
USER SEKARANG
Tgl Mulai (Current)
Tgl Serah Terakhir
Admin/IT PIC
Riwayat Perbaikan / Catatan
Tgl Retire/Disposal
QR
BARCODE
```

### Critical rule

Do NOT copy this Excel structure directly into PostgreSQL.

For example:

```text
User Pertama
User Kedua
User Ketiga
User Sekarang
```

must become relational assignment history.

---

# 9. Existing Transfer / FAT Excel

Source file:

`Template Form Fixed Transfer Asset IT v1 (2026).xlsx`

The template includes workflow/content for:

- SERAH
- TERIMA
- BAST

The application must preserve the required business information while eliminating manual copy/paste.

---

# 10. Existing Windows Audit Application

Existing application:

`ITAuditMITOGroup-NEW(1).exe`

Initial inspection indicates:

- .NET 8
- WPF
- Windows x64
- assembly related to `AuditLaptopMITO`
- Microsoft Graph integration
- Graph service functionality
- token acquisition
- spreadsheet row insertion workflow

The goal is NOT to simply port the WPF UI.

Extract the useful device-data collection and business workflow.

---

# 11. Audit Architecture

Preferred:

```text
Windows Device
      ↓
Lightweight Audit Agent
      ↓
Laravel API
      ↓
PostgreSQL
      ↓
Dashboard
```

If browser APIs can reliably collect the required information, an agent may not be necessary.

If not, create:

```text
tracker-audit-agent.exe
```

The agent should only:

1. collect hardware information
2. authenticate
3. send JSON to API
4. receive audit result
5. display result

The business logic must remain in Laravel.

---

# 12. Authentication

Use Microsoft Entra ID / Microsoft 365.

Primary eligible account:

```text
shohibul.anwar@mito.co.id
```

Use OAuth/OIDC.

Do not build a separate production password system unless specifically required.

For local development, provide a safe local authentication/development mode if Entra configuration is not yet available.

---

# 13. Roles

## IT Admin

Full access.

## IT Support

Can:

- view assets
- create assets
- edit assets
- audit
- transfer
- generate documents

Cannot permanently destroy historical records.

## Viewer

Read-only access.

Use Laravel authorization policies/gates or an appropriate RBAC solution.

---

# 14. Database

Core tables:

```text
users
companies
departments
locations
asset_categories
asset_statuses
assets
asset_assignments
asset_audits
asset_repairs
asset_transfers
documents
document_templates
attachments
activity_logs
```

---

# 15. Assets

Recommended fields:

```text
id
asset_id
company_id
category_id
brand
model
serial_number
hostname
processor
storage
ram
os_version
location_id
purchase_year
vendor
purchase_price
useful_life_years
asset_status_id
warranty_until
deploy_date
software_license
mac_address
static_ip
current_user_id
admin_it_pic
repair_notes
retire_date
qr_code
barcode
created_at
updated_at
```

Add indexes and constraints appropriately.

Legacy duplicates must be detected rather than blindly rejected or overwritten.

Potential identifiers:

```text
asset_id
serial_number
hostname
```

---

# 16. Assignment History

Do NOT create columns for User 1, User 2, User 3, etc.

Use:

```text
asset_assignments
```

Suggested fields:

```text
id
asset_id
user_id
assigned_date
returned_date
from_location_id
to_location_id
transfer_id
notes
created_by
created_at
updated_at
```

Every transfer must preserve history.

Use database transactions for ownership changes.

---

# 17. Companies

Create:

```text
companies
```

Fields:

```text
id
name
code
address
status
created_at
updated_at
```

Existing Excel contains multiple companies; do not hardcode them in business logic.

---

# 18. Locations

Create:

```text
locations
```

Fields:

```text
id
name
branch
type
address
status
created_at
updated_at
```

Locations must be configurable.

---

# 19. Asset Categories

Minimum examples:

```text
Laptop
PC
Server
Printer
Monitor
Network
CCTV
UPS
Storage
Other
```

Categories must be configurable.

---

# 20. Asset Statuses

Minimum:

```text
Active
Available
Assigned
Repair
Spare
Lost
Retired
Disposed
```

Statuses must be configurable.

---

# 21. Dashboard

Dashboard must show:

```text
Total Assets
Active
Available
Assigned
Repair
Retired
Disposed
```

Charts:

- asset by category
- asset by location
- asset by company
- asset status distribution

Warnings:

```text
Warranty Expiring
Asset Not Audited
Audit Mismatch
Missing Serial
Missing Asset ID
Duplicate Serial
Retirement Due
```

Recent activity should also be visible.

---

# 22. Asset List

Must support:

- search
- filtering
- sorting
- pagination
- column visibility
- export
- bulk selection

Minimum fields:

```text
Asset ID
Category
Brand
Model
Serial Number
Hostname
Location
Current User
Status
IT PIC
Updated
```

---

# 23. Asset Detail

Tabs:

```text
Overview
Hardware
Assignment History
Audit
Repair History
Documents
Activity Log
```

---

# 24. Asset CRUD

Create/edit:

```text
Company
Category
Brand
Model
Serial Number
Hostname
Processor
Storage
RAM
OS
Location
Purchase Year
Vendor
Purchase Price
Useful Life
Warranty
Software License
MAC Address
Static IP
IT PIC
Notes
```

Every important update creates an activity log.

---

# 25. Excel Import

Workflow:

```text
Upload Excel
 ↓
Detect sheets
 ↓
Map columns
 ↓
Validate
 ↓
Preview
 ↓
Detect duplicates
 ↓
User confirms
 ↓
Import
```

Show:

```text
Rows detected
Valid
Warning
Error
Duplicate
```

Never automatically delete existing records.

---

# 26. Data Mapping

Create:

```text
docs/asset-data-mapping.md
```

Document every legacy Excel field and its database destination.

Example:

```text
USER SEKARANG
→ assets.current_user_id
→ active asset assignment
```

```text
User Pertama / User Kedua / User Ketiga
→ asset_assignments
```

```text
Riwayat Perbaikan / Catatan
→ asset_repairs / notes
```

```text
Tgl Retire/Disposal
→ assets.retire_date
```

---

# 27. Excel Export

Support:

```text
All Assets
Filtered Assets
Assignment History
Audit Results
Transfer History
```

Output `.xlsx`.

---

# 28. Audit

Audit must compare registered values against actual device values.

Collect where possible:

```text
Hostname
Serial Number
Brand
Model
Processor
RAM
Storage
OS
MAC Address
IP Address
```

Result:

```text
MATCH
MISMATCH
NEW DEVICE
UNKNOWN DEVICE
INCOMPLETE
```

Save every audit.

---

# 29. Audit Comparison Example

Registered:

```text
RAM = 8 GB
```

Actual:

```text
RAM = 16 GB
```

Show:

```text
MISMATCH

RAM:
Registered = 8 GB
Actual = 16 GB
```

The user may then decide whether to update the registered asset data.

Do NOT silently overwrite registered data based on audit results.

---

# 30. Transfer Workflow

```text
Asset Detail
 ↓
Transfer Asset
 ↓
Current User
 ↓
New User
 ↓
New Location
 ↓
Transfer Date
 ↓
Condition
 ↓
Accessories
 ↓
Notes
 ↓
Submit
 ↓
Transaction
 ├── close previous assignment
 ├── create new assignment
 ├── update current asset owner/location
 ├── create transfer record
 └── create activity log
 ↓
Generate document
```

---

# 31. Transfer Form

Fields:

```text
Transfer Number
Transfer Date
Company

Asset
Asset ID
Category
Brand
Model
Serial Number

From User
From Department
From Location

To User
To Department
To Location

Condition
Accessories
Notes

Submitted By
Approved By
Received By
```

---

# 32. FAT / BAST

User must be able to generate a document from an asset or transfer.

Example:

```text
Asset Detail
 ↓
Generate FAT / BAST
 ↓
Load Asset + User + Transfer Data
 ↓
Preview
 ↓
Generate PDF
```

Documents:

```text
FAT
BAST
Transfer Asset
Asset Return
Asset Assignment
```

No manual copy/paste.

---

# 33. Document Numbering

Examples:

```text
FAT/IT/2026/00001
BAST/IT/2026/00001
AST/TRF/2026/00001
```

Number format must be configurable.

Prevent duplicate numbers.

---

# 34. PDF

Requirements:

- A4
- professional
- company branding
- asset information
- document number
- date
- signature section
- print friendly

Actions:

```text
Preview
Download
Print
```

Use a Laravel-compatible PDF package.

---

# 35. Signature

MVP:

- typed name
- signature image
- signature placeholder

Roles:

```text
Yang Menyerahkan
Yang Menerima
Mengetahui
IT
```

Do not implement legally binding digital signatures unless separately requested.

---

# 36. Repair History

Table:

```text
asset_repairs
```

Fields:

```text
id
asset_id
date
problem
action
vendor
cost
status
notes
created_by
created_at
updated_at
```

---

# 37. Documents / Attachments

Possible files:

```text
FAT PDF
BAST PDF
Invoice
Warranty
Repair document
Photo
Other
```

Store metadata in PostgreSQL.

Preferred large-file storage:

Microsoft SharePoint.

Do not store large binary files directly in PostgreSQL unless there is a clear reason.

---

# 38. Microsoft Graph / SharePoint

Use Microsoft Graph for:

- Entra authentication
- user lookup
- SharePoint documents
- OneDrive/SharePoint integration

Excel must NOT be the primary database.

Excel is only:

```text
Import
Export
Legacy compatibility
```

---

# 39. Microsoft 365 Directory

User lookup should support:

```text
Name
Email
Department
Job Title
Company
Status
```

Prefer Entra/Graph as the source for employee directory information.

Cache only what is necessary for relational database references.

---

# 40. Activity Log

Track:

```text
CREATE
UPDATE
TRANSFER
AUDIT
GENERATE_DOCUMENT
IMPORT
EXPORT
LOGIN
ARCHIVE
RETIRE
```

Example:

```text
Shohibul Anwar

Changed Asset LAPTOP-001

Before:
User = John

After:
User = Budi

Timestamp:
2026-09-22 10:30
```

Activity logs should be immutable to normal users.

---

# 41. QR Code

Each asset can have a QR code.

Example:

```text
https://tracker-asset.example.com/assets/{asset_id}
```

Scan → authenticated asset detail.

---

# 42. Barcode

Barcode may support:

- search
- check-in
- check-out
- transfer
- audit

---

# 43. UI Navigation

```text
Dashboard

Assets
 ├── All Assets
 ├── Add Asset
 ├── Audit
 ├── Repairs
 └── Retired

Transfers
 ├── New Transfer
 ├── Transfer History
 └── Pending

Documents
 ├── FAT
 ├── BAST
 └── Templates

Reports

Master Data
 ├── Companies
 ├── Locations
 ├── Categories
 ├── Users
 └── Status

Activity Log

Settings
```

---

# 44. Suggested Laravel Structure

```text
TRACKER-ASSET/
├── app/
│   ├── Http/
│   ├── Models/
│   ├── Policies/
│   ├── Services/
│   └── ...
├── bootstrap/
├── config/
├── database/
│   ├── migrations/
│   ├── seeders/
│   └── factories/
├── docker/
├── docs/
├── public/
├── resources/
│   ├── views/
│   └── js/
├── routes/
├── storage/
├── tests/
├── .env.example
├── .gitignore
├── docker-compose.yml
├── Dockerfile
├── README.md
└── PRD.md
```

---

# 45. Required Documentation

Create:

```text
docs/
├── architecture.md
├── database-schema.md
├── asset-data-mapping.md
├── audit-workflow.md
├── transfer-workflow.md
├── document-workflow.md
├── local-development.md
└── deployment.md
```

Documentation must be updated when architecture changes.

---

# 46. Testing

Testing is mandatory because the system manages real asset records.

## Unit tests

At minimum:

- audit comparison
- assignment logic
- document numbering
- asset status logic

## Feature tests

At minimum:

- authentication
- asset CRUD
- asset import
- asset transfer
- audit
- document generation
- permissions

## Integration tests

Where practical:

- PostgreSQL
- Microsoft Graph
- SharePoint
- PDF generation
- Excel import/export

---

# 47. CI/CD

Only after local MVP is stable.

Target:

```text
Developer
 ↓
git push
 ↓
GitHub Actions
 ↓
composer install
 ↓
tests
 ↓
build Docker image
 ↓
push image
 ↓
deploy VPS
 ↓
docker compose up -d
```

Production deployment must not be the first development target.

---

# 48. Production Architecture

Target:

```text
Internet
   ↓
Cloudflare / Reverse Proxy
   ↓
HTTPS
   ↓
Nginx
   ↓
Laravel Docker
   ↓
PostgreSQL
   ↓
Redis
```

Start with Docker Compose.

No Kubernetes for MVP.

---

# 49. Production Backup

Implement:

- PostgreSQL automated backup
- backup retention
- document backup strategy
- recovery procedure

Secrets must never be committed to Git.

---

# 50. Security

Mandatory:

- HTTPS
- Entra authentication
- RBAC
- Laravel authorization policies
- input validation
- CSRF protection
- SQL injection protection
- XSS protection
- file upload validation
- activity logging
- environment variables for secrets
- no credentials in Git

---

# 51. Performance Targets

Initial targets:

```text
Asset list < 2 sec
Search < 1 sec
Dashboard < 3 sec
PDF generation < 5 sec
```

Architecture should support at least:

```text
10,000+ assets
100+ concurrent users
```

without a major redesign.

---

# 52. Definition of Done — Local MVP

Do NOT deploy to VPS until all are true:

- [ ] Docker starts successfully
- [ ] Laravel works
- [ ] PostgreSQL works
- [ ] migrations work
- [ ] seeders work
- [ ] authentication works
- [ ] asset CRUD works
- [ ] search/filter works
- [ ] asset history works
- [ ] Excel import works
- [ ] duplicate detection works
- [ ] dashboard works
- [ ] transfer works
- [ ] audit workflow works
- [ ] FAT/BAST PDF works
- [ ] Excel export works
- [ ] activity log works
- [ ] RBAC works
- [ ] tests pass
- [ ] documentation is updated

---

# 53. Critical Business Rule

## ONE ASSET = ONE SOURCE OF TRUTH

The PostgreSQL asset record is the source of truth.

Everything else references that asset:

```text
Asset
 ├── Assignment History
 ├── Audit History
 ├── Repair History
 ├── Transfer History
 ├── FAT
 ├── BAST
 ├── Attachments
 ├── QR
 └── Activity Log
```

Do not duplicate asset information unnecessarily.

---

# 54. AI CODING AGENT RULES

The coding agent MUST:

1. Read `PRD.md` before coding.
2. Inspect the repository.
3. Inspect the supplied Excel files.
4. Inspect the EXE if available.
5. Work locally first.
6. Use Docker Compose.
7. Use Laravel.
8. Use PostgreSQL.
9. Never deploy to VPS during initial implementation.
10. Never destroy existing asset data.
11. Never use destructive production migrations.
12. Keep complete asset history.
13. Treat Excel as import/export, not as the database.
14. Keep business logic in Laravel backend.
15. Keep secrets in `.env`.
16. Provide `.env.example`.
17. Write tests for critical workflows.
18. Update documentation.
19. Make incremental commits.
20. Avoid unnecessary technologies.
21. Do not introduce Kubernetes.
22. Do not implement everything in one giant step.

---

# 55. FIRST AGENT TASK

The first agent task is ONLY:

### 1. Inspect

Inspect:

- repository
- `PRD.md`
- supplied Excel files
- existing EXE if available

### 2. Analyze

Determine:

- legacy asset fields
- normalized database design
- asset relationships
- transfer workflow
- audit workflow
- FAT/BAST data requirements

### 3. Document

Create:

```text
docs/architecture.md
docs/database-schema.md
docs/asset-data-mapping.md
docs/audit-workflow.md
docs/transfer-workflow.md
docs/document-workflow.md
docs/local-development.md
```

### 4. Build local infrastructure

Create:

```text
Dockerfile
docker-compose.yml
.env.example
```

Start:

```text
Laravel
PostgreSQL
Redis
Mailpit
```

### 5. Verify

Run:

```text
docker compose up -d --build
php artisan migrate
php artisan db:seed
php artisan test
```

or the equivalent commands inside the container.

### 6. STOP

After the local Laravel skeleton, database, documentation, and baseline tests work, stop and report the result.

Do NOT immediately build the entire product.

---

# 56. Development Philosophy

This system will contain real company asset records.

Therefore:

**Correctness > speed.**

**Data integrity > convenience.**

**Local testing > premature production deployment.**

**History preservation > destructive updates.**

The final system should make this workflow possible:

```text
Select Asset
 ↓
Audit / Transfer / Repair / FAT
 ↓
System updates the correct records
 ↓
History is automatically recorded
 ↓
Document is automatically generated
 ↓
Dashboard reflects the latest state
```

The user should no longer need to:

```text
Open Excel
↓
Find asset
↓
Copy data
↓
Open another Excel
↓
Fill FAT
↓
Save PDF
↓
Update asset Excel
↓
Update history manually
```

Core principle:

> **ONE ASSET = ONE SOURCE OF TRUTH.**
