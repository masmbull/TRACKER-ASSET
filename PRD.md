# PRD — TRACKER-ASSET

**Project:** TRACKER-ASSET  
**Owner:** IT / Asset Management  
**Repository:** `https://github.com/masmbull/TRACKER-ASSET.git`  
**Status:** Draft / MVP Planning  
**Target:** Web application accessible from browser

---

## 1. Background

Saat ini proses IT Asset Audit menggunakan aplikasi Windows `.exe` (`ITAuditMITOGroup-NEW(1).exe`). Hasil audit kemudian masuk ke Excel Asset di SharePoint.

Masalah utama:
- Aplikasi audit hanya berjalan sebagai desktop application.
- Data asset masih bergantung pada Excel sebagai media utama tracking.
- Editing, penambahan, pengurangan, filtering, dan tracking asset belum nyaman.
- Monitoring perubahan data asset membutuhkan pekerjaan manual.
- Proses FAT (Form Acceptance Test) masih dibuat satu per satu sehingga memakan waktu.

Tujuan proyek ini adalah membangun **TRACKER-ASSET**, aplikasi web yang menjadi pusat pengelolaan asset dan FAT, dengan browser sebagai interface utama.

---

## 2. Existing Application Findings

File sumber audit yang tersedia adalah aplikasi Windows:

`ITAuditMITOGroup-NEW(1).exe`

Static inspection menunjukkan aplikasi merupakan **.NET 8.0 Windows/WPF application**, dengan indikasi:
- Assembly/application: `AuditLaptopMITO`
- UI: WPF
- Target runtime: `.NETCoreApp,Version=v8.0`
- Target platform: `net8.0-windows`, `win-x64`
- Namespace/class yang terlihat antara lain `AuditLaptopMITO.MainWindow`, `PinDialog`, `SettingsWindow`, dan `GraphService`.
- Terdapat method async seperti `GraphService.GetTokenAsync` dan `GraphService.AddRowAsync`, yang mengindikasikan aplikasi berinteraksi dengan Microsoft Graph untuk memasukkan data ke sumber data cloud/Excel.
- PDB path embedded menunjukkan project/build source pernah berada di folder `WPF_AuditLaptop_App`.

**Implikasi:** aplikasi lama dapat dijadikan referensi untuk merekonstruksi field, alur audit, dan integrasi data. UI desktop tidak perlu dipertahankan; targetnya adalah web application.

---

## 3. Product Vision

Membuat satu dashboard web untuk:

1. **Asset Tracker** — melihat dan mengelola seluruh asset IT.
2. **Asset Audit** — menggantikan workflow aplikasi `.exe` lama dengan form web.
3. **FAT Generator** — membuat Form FAT secara cepat dari data asset/perangkat tanpa mengisi dokumen satu per satu.
4. **Search & Tracking** — mencari asset berdasarkan Asset ID, serial number, hostname, user, lokasi, kategori, status, dan atribut lainnya.
5. **History** — mengetahui perubahan data asset dan siapa yang melakukan perubahan.

---

## 4. Goals

### MVP Goals
- Bisa dibuka melalui browser.
- Login menggunakan akun perusahaan.
- Menampilkan seluruh data asset dari sumber Excel existing.
- CRUD asset: tambah, edit, lihat, dan nonaktifkan/hapus sesuai permission.
- Search, filter, sorting, pagination.
- Import dari Excel.
- Export ke Excel.
- Asset audit form melalui browser.
- Generate FAT berdasarkan template.
- Menyimpan histori perubahan.
- Dashboard KPI asset.

### Future Goals
- Integrasi Microsoft Graph / SharePoint secara native.
- Auto-sync dengan Excel existing.
- QR code / barcode asset.
- Scan QR untuk membuka detail asset.
- Approval workflow FAT.
- Attachment foto asset.
- Notification/email.
- Mobile responsive / PWA.
- Integrasi Microsoft Entra ID SSO.

---

## 5. Non-Goals MVP

Untuk fase pertama tidak wajib:
- Mobile native app.
- Offline-first application.
- Complex procurement module.
- Full ERP integration.
- Automatic hardware discovery dari endpoint tanpa agent.
- Mengubah data Excel existing secara langsung sebelum struktur data baru divalidasi.

---

## 6. Primary Users

| Role | Capability |
|---|---|
| IT Admin | Full access, configuration, import/export, user/role management |
| IT Support | View/edit asset, audit, FAT |
| IT Technician | Audit asset dan update field operasional |
| Viewer/Management | Dashboard dan read-only asset/FAT |

---

## 7. Asset Data Model

Initial fields mengikuti struktur asset management existing dan dapat diperluas setelah mapping Excel selesai.

| Field | Description |
|---|---|
| Asset ID | ID unik asset |
| Category | Laptop, PC, printer, network, etc. |
| Brand | Merk |
| Model | Model perangkat |
| Serial Number | Serial number |
| Hostname | Hostname perangkat |
| Specification | CPU/RAM/Storage/display/etc. |
| OS | Operating system |
| Location | Lokasi asset |
| Purchase Year | Tahun pembelian |
| Vendor | Vendor/pemasok |
| Price | Harga pembelian |
| Useful Life | Masa manfaat |
| Asset Status | Active, Stock, Repair, Lost, Retired, etc. |
| Warranty | Informasi warranty |
| Deploy Date | Tanggal deployment |
| License | License/software info |
| MAC Address | MAC address |
| Static IP | Static IP bila ada |
| Current User | Pengguna saat ini |
| Department | Department pengguna |
| Notes | Catatan |

### Asset History

Setiap perubahan penting harus dapat dilacak:
- timestamp
- user
- field changed
- old value
- new value
- reason/notes bila diperlukan

---

## 8. Dashboard Requirements

Dashboard utama harus memberikan gambaran cepat kondisi asset.

### KPI
- Total Asset
- Active
- In Stock
- Under Repair
- Retired
- Missing/Lost
- Warranty Expired
- Warranty Expiring Soon
- Asset Without User
- Asset Without Location
- Asset Without Serial Number

### Visualizations
- Asset by Category
- Asset by Location
- Asset by Department
- Asset by Status
- Asset by Brand
- Warranty status
- Purchase year distribution

### Quick Actions
- + Add Asset
- Import Excel
- Start Audit
- Create FAT
- Export

---

## 9. Asset Management

### List
Data table dengan:
- search
- multi-filter
- column sorting
- pagination
- column visibility
- export selected/all

### Detail
Detail asset menampilkan:
- information
- current assignment
- specifications
- audit history
- change history
- FAT history
- attachments

### CRUD
- Create asset
- Update asset
- Archive/deactivate asset
- Restore archived asset

Hard delete sebaiknya dibatasi untuk admin dan/atau tidak digunakan pada MVP agar histori tetap aman.

---

## 10. Asset Audit

Aplikasi web harus menggantikan fungsi utama `.exe` lama.

### Audit Flow

```text
Select / Scan Asset
        ↓
Asset Detail Loaded
        ↓
Technician checks physical device
        ↓
Fill / correct audit fields
        ↓
Attach photo (optional)
        ↓
Submit Audit
        ↓
Validation
        ↓
Save Asset + Audit History
        ↓
Dashboard Updated
```

### Audit Features
- Search Asset ID / Serial Number / Hostname.
- Optional QR/barcode scan.
- Show existing asset data.
- Editable audit fields.
- Required-field validation.
- Audit timestamp.
- Auditor identity.
- Notes.
- Photo attachment.
- Save draft.
- Submit final audit.

---

## 11. FAT Module

### Problem
Saat ini Form FAT dibuat satu per satu. Target modul ini adalah membuat FAT secara cepat berdasarkan data asset/perangkat.

### Proposed Flow

```text
Select Asset / Device
        ↓
Choose FAT Template
        ↓
System pre-fills asset information
        ↓
Technician fills checklist/result
        ↓
Add notes / attachment
        ↓
Generate FAT
        ↓
PDF / Print
        ↓
Save FAT record
```

### FAT Header
- FAT Number
- Date
- Project / Department
- Vendor
- Device / Asset
- Asset ID
- Serial Number
- Model
- Location
- Technician
- Requester

### FAT Checklist
Checklist harus configurable agar tidak hard-coded.

Contoh:
- Physical condition
- Power on test
- Display test
- Keyboard test
- Touchpad/mouse test
- Network test
- Wi-Fi test
- LAN test
- USB test
- Audio test
- Camera test
- OS verification
- Application verification
- Antivirus/security verification
- Serial number verification
- Asset label verification

Setiap item dapat memiliki:
- PASS
- FAIL
- N/A
- Notes

### FAT Output
- Printable HTML
- PDF
- Stored FAT record
- FAT history per asset

---

## 12. Excel / SharePoint Integration

Existing sources:

- Asset Excel: SharePoint IT site.
- FAT Excel: SharePoint/OneDrive personal workspace.

The system should initially support **controlled import/export** while the data model is being validated.

### Phase 1
Excel → Import → Database → Web Dashboard

Database → Export → Excel

### Phase 2
Web App ↔ Microsoft Graph ↔ SharePoint Excel

The application should avoid using Excel as the primary transactional database once the web application is production-ready. Excel can remain as reporting/export/integration format.

---

## 13. Authentication & Authorization

Preferred production authentication:
- Microsoft Entra ID / Microsoft 365 SSO.

Roles:
- Admin
- IT Support
- Technician
- Viewer

Permissions should be server-side enforced.

---

## 14. Suggested Technical Architecture

```text
                    Internet / LAN
                          │
                          ▼
                  ┌───────────────┐
                  │    Browser    │
                  │ Brave/Chrome  │
                  └───────┬───────┘
                          │ HTTPS
                          ▼
                  ┌───────────────┐
                  │ Web Frontend  │
                  │ React/Vue     │
                  └───────┬───────┘
                          │ REST API
                          ▼
                  ┌───────────────┐
                  │    Backend    │
                  │ Laravel / API │
                  └───────┬───────┘
                          │
             ┌────────────┼─────────────┐
             ▼            ▼             ▼
         PostgreSQL   Microsoft Graph  Storage
             │            │             │
             ▼            ▼             ▼
        Asset/FAT DB   SharePoint     Photos/PDF
```

Recommended implementation can use **Laravel + PostgreSQL + Vue/React**. The final stack should be confirmed during technical design.

---

## 15. Database Entities

Initial entities:

- users
- roles
- assets
- asset_assignments
- asset_audits
- asset_history
- categories
- locations
- departments
- vendors
- fat_templates
- fat_template_items
- fat_records
- fat_results
- attachments

Relationships:

```text
Asset
 ├── Assignments
 ├── Audits
 ├── History
 ├── FAT Records
 └── Attachments
```

---

## 16. API Requirements

Example endpoints:

```text
GET    /api/assets
POST   /api/assets
GET    /api/assets/{id}
PUT    /api/assets/{id}
POST   /api/assets/{id}/audit
GET    /api/assets/{id}/history
GET    /api/assets/{id}/fat

GET    /api/fat/templates
POST   /api/fat
GET    /api/fat/{id}
GET    /api/fat/{id}/pdf

POST   /api/import/assets
GET    /api/export/assets

GET    /api/dashboard/summary
GET    /api/dashboard/charts
```

---

## 17. Validation & Data Quality

The system should highlight incomplete records.

Examples:
- Missing Asset ID
- Missing Serial Number
- Missing Hostname
- Missing User
- Missing Location
- Missing Department
- Missing Purchase Year
- Invalid MAC address
- Invalid IP address
- Duplicate Serial Number
- Duplicate Asset ID

Dashboard should expose an **Asset Data Quality** indicator.

---

## 18. Audit Trail

Every important mutation should record:

```text
who
when
what changed
old value
new value
source
```

Sources may include:
- Web UI
- Import Excel
- API
- Asset Audit
- FAT
- Admin action

---

## 19. Import Strategy

Excel import must provide a preview before commit.

```text
Upload Excel
   ↓
Read workbook
   ↓
Map columns
   ↓
Validate
   ↓
Show errors/warnings
   ↓
Preview changes
   ↓
Confirm Import
   ↓
Transaction
   ↓
History recorded
```

Duplicate handling options:
- Update existing asset
- Skip duplicate
- Create as new only when explicitly confirmed

---

## 20. Security Requirements

- HTTPS in production.
- Entra authentication preferred.
- Server-side authorization.
- CSRF protection where applicable.
- Input validation.
- SQL injection protection via ORM/parameterization.
- File upload restrictions.
- MIME/type validation.
- Maximum attachment size.
- Audit log should not be user-editable.
- Secrets stored in environment variables/secrets manager.
- Never commit Microsoft Graph credentials or tokens to Git.

---

## 21. MVP Acceptance Criteria

### Asset
- [ ] User can login.
- [ ] User can view asset list.
- [ ] User can search and filter.
- [ ] User can add asset.
- [ ] User can edit asset.
- [ ] User can archive asset.
- [ ] User can view asset history.
- [ ] Excel import works with preview/validation.
- [ ] Excel export works.

### Audit
- [ ] User can select an asset.
- [ ] Existing asset information is loaded.
- [ ] User can modify audit fields.
- [ ] Audit submission creates history.
- [ ] Auditor and timestamp are recorded.

### FAT
- [ ] User can select a FAT template.
- [ ] Asset data is automatically populated.
- [ ] User can complete checklist.
- [ ] FAT is stored.
- [ ] FAT can be printed/exported to PDF.
- [ ] FAT history is visible from asset detail.

### Dashboard
- [ ] KPI cards work.
- [ ] Filters affect dashboard data.
- [ ] Charts reflect database data.
- [ ] Incomplete asset records can be identified.

---

## 22. Development Phases

### Phase 0 — Reverse Engineering & Data Mapping
- Analyze old EXE.
- Identify fields and workflow.
- Map existing Excel columns.
- Map FAT Excel structure.
- Confirm SharePoint/Graph integration.

### Phase 1 — Foundation
- Repository.
- Backend.
- Frontend.
- Database.
- Authentication.
- Docker/local development.

### Phase 2 — Asset Tracker
- Asset CRUD.
- Search/filter.
- Import/export.
- History.

### Phase 3 — Dashboard
- KPI.
- Charts.
- Data quality.

### Phase 4 — Web Audit
- Replace desktop audit workflow.
- Audit history.
- Attachment.

### Phase 5 — FAT
- Templates.
- Checklist.
- Auto-fill.
- PDF generation.
- FAT history.

### Phase 6 — Microsoft Integration
- Entra SSO.
- Microsoft Graph.
- SharePoint/Excel sync where required.

---

## 23. Definition of Done

A feature is considered done when:
- Implemented in web UI.
- Backend API implemented.
- Database migration/model implemented.
- Authorization enforced.
- Validation implemented.
- Error handling implemented.
- Audit trail implemented where applicable.
- Tested with representative data.
- Documentation updated.
- Code committed to Git.

---

## 24. Open Questions

1. Exact Excel column/header structure for Asset workbook.
2. Exact FAT workbook/template structure.
3. Whether Excel should remain synchronized bidirectionally or become export-only.
4. Exact FAT approval/signature requirements.
5. Whether attachments should live in SharePoint or application storage.
6. Entra app registration and Microsoft Graph permissions.
7. Production hosting target (VPS, internal server, Azure/AWS/etc.).
8. Whether QR/barcode scanning is required in MVP.

---

## 25. Initial Success Metric

The application should reduce manual asset administration and FAT preparation by moving repetitive work into the web workflow.

Primary operational metrics:
- % asset records complete
- Number of assets audited
- Number of assets requiring correction
- FAT forms generated per period
- Average time to create FAT
- Number of duplicate/incomplete asset records
- Number of manual Excel edits required

---

## 26. Data Source References

Asset workbook: SharePoint IT site provided by project owner.

FAT workbook: SharePoint/OneDrive location provided by project owner.

These links should be configured as deployment secrets/configuration rather than hard-coded into source code.
