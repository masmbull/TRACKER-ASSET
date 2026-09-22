# Database Schema — TRACKER-ASSET

## Core Tables

```text
users ──┬── asset_assignments (from_user, to_user)
        ├── asset_audits (auditor)
        ├── asset_repairs (created_by)
        ├── asset_transfers (submitted_by, approved_by, received_by)
        ├── activity_logs (actor)
        └── documents

companies ──┬── assets (company_id)
             └── asset_transfers (company)

locations ──┬── assets (location_id)
             ├── asset_assignments (from_location_id, to_location_id)
             └── asset_transfers (from_location, to_location)

asset_categories ──┬── assets (category_id)
                   └── (parent_category_id for hierarchy, optional)

asset_statuses ──┬── assets (asset_status_id)
                 └── (status types: Active, Available, Assigned, Repair, Spare, Lost, Retired, Disposed)

assets ──┬── asset_assignments
         ├── asset_audits
         ├── asset_repairs
         ├── asset_transfers
         ├── documents
         └── attachments

asset_assignments
asset_audits
asset_repairs
asset_transfers
documents
document_templates
attachments
activity_logs
```

## Table Definitions

### users

| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| name | string | |
| email | string, unique | Entra ID email |
| entra_id | string, unique | Microsoft Entra ID object ID |
| department | string | From Entra/Graph |
| job_title | string | From Entra/Graph |
| company | string | From Entra/Graph |
| status | string | Active/Inactive |
| role | string | admin, support, technician, viewer |
| is_active | boolean | Default true |
| created_at | timestamp | |
| updated_at | timestamp | |

### companies

| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| name | string | |
| code | string | Company code |
| address | text | |
| status | string | Active/Inactive |
| created_at | timestamp | |
| updated_at | timestamp | |

### locations

| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| name | string | Location name |
| branch | string | Branch name |
| type | string | Office, Warehouse, etc. |
| address | text | |
| status | string | Active/Inactive |
| created_at | timestamp | |
| updated_at | timestamp | |

### asset_categories

| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| name | string, unique | Laptop, PC, Server, Printer, etc. |
| parent_id | foreign key self-ref | For sub-categories (optional) |
| status | string | Active/Inactive |
| created_at | timestamp | |
| updated_at | timestamp | |

### asset_statuses

| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| name | string, unique | Active, Available, Assigned, Repair, Spare, Lost, Retired, Disposed |
| color | string | UI color (optional) |
| created_at | timestamp | |
| updated_at | timestamp | |

### assets

| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| asset_id | string, unique | Business identifier (e.g., LAPTOP-001) |
| company_id | foreign key → companies | |
| category_id | foreign key → asset_categories | |
| brand | string | |
| model | string | |
| serial_number | string | Indexed, checked unique |
| hostname | string | |
| processor | string | |
| storage | string | |
| ram | string | |
| os_version | string | |
| location_id | foreign key → locations | |
| purchase_year | integer | |
| vendor | string | |
| purchase_price | decimal | |
| useful_life_years | integer | |
| asset_status_id | foreign key → asset_statuses | Default: Available |
| warranty_until | date | |
| deploy_date | date | |
| software_license | string | |
| mac_address | string | Validated format |
| static_ip | string | Validated format |
| current_user_id | foreign key → users, nullable | Current assignee |
| admin_it_pic | foreign key → users, nullable | IT responsible person |
| repair_notes | text | |
| retire_date | date | |
| qr_code | string, nullable | URL to asset detail |
| barcode | string, nullable | |
| is_active | boolean | Default true (soft delete equivalent) |
| created_at | timestamp | |
| updated_at | timestamp | |

**Indexes:** asset_id (unique), serial_number (unique), hostname, mac_address, current_user_id, location_id, category_id, asset_status_id, qr_code, barcode

### asset_assignments

| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| asset_id | foreign key → assets | |
| user_id | foreign key → users | Assigned user |
| assigned_date | date | |
| returned_date | date, nullable | Null = still assigned |
| from_location_id | foreign key → locations, nullable | |
| to_location_id | foreign key → locations, nullable | |
| transfer_id | foreign key → asset_transfers, nullable | Links to transfer record |
| notes | text | |
| created_by | foreign key → users | |
| created_at | timestamp | |
| updated_at | timestamp | |

### asset_audits

| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| asset_id | foreign key → assets | |
| audit_date | timestamp | When audit was performed |
| auditor_id | foreign key → users | Who performed audit |
| device_hostname | string | Actual device hostname |
| device_serial | string | Actual device serial |
| device_processor | string, nullable | |
| device_ram | string, nullable | |
| device_storage | string, nullable | |
| device_os | string, nullable | |
| device_mac | string, nullable | |
| device_ip | string, nullable | |
| result | string | MATCH, MISMATCH, NEW DEVICE, UNKNOWN DEVICE, INCOMPLETE |
| notes | text | |
| photo_path | string, nullable | |
| status | string | Draft, Submitted, Approved |
| created_at | timestamp | |
| updated_at | timestamp | |

### asset_repairs

| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| asset_id | foreign key → assets | |
| date | date | When repair occurred |
| problem | text | |
| action | text | What was done |
| vendor | string | Repair vendor |
| cost | decimal, nullable | |
| status | string | Open, In Progress, Closed |
| notes | text | |
| created_by | foreign key → users | |
| created_at | timestamp | |
| updated_at | timestamp | |

### asset_transfers

| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| transfer_number | string, unique | e.g., AST/TRF/2026/00001 |
| transfer_date | date | |
| company_id | foreign key → companies | |
| asset_id | foreign key → assets | |
| from_user_id | foreign key → users | |
| from_department | string | |
| from_location_id | foreign key → locations | |
| to_user_id | foreign key → users | |
| to_department | string | |
| to_location_id | foreign key → locations | |
| condition | string | |
| accessories | text | |
| notes | text | |
| submitted_by | foreign key → users | |
| approved_by | foreign key → users, nullable | |
| received_by | foreign key → users, nullable | |
| status | string | Pending, Approved, Completed, Rejected |
| created_at | timestamp | |
| updated_at | timestamp | |

### documents

| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| asset_id | foreign key → assets, nullable | |
| transfer_id | foreign key → asset_transfers, nullable | |
| document_type | string | FAT, BAST, Transfer, Return, Assignment |
| document_number | string, unique | e.g., FAT/IT/2026/00001 |
| title | string | |
| template_id | foreign key → document_templates, nullable | |
| file_path | string | |
| file_type | string | pdf, docx, etc. |
| generated_date | date | |
| generated_by | foreign key → users | |
| status | string | Draft, Final |
| created_at | timestamp | |
| updated_at | timestamp | |

### document_templates

| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| name | string | Template name |
| document_type | string | FAT, BAST, etc. |
| template_content | text/json | Template structure/fields |
| is_active | boolean | |
| created_by | foreign key → users | |
| created_at | timestamp | |
| updated_at | timestamp | |

### attachments

| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| asset_id | foreign key → assets, nullable | |
| document_id | foreign key → documents, nullable | |
| file_name | string | Original filename |
| file_path | string | Storage path |
| file_type | string | image, pdf, document, etc. |
| mime_type | string | |
| file_size | bigint | Bytes |
| uploaded_by | foreign key → users | |
| created_at | timestamp | |
| updated_at | timestamp | |

### activity_logs

| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| actor_id | foreign key → users | Who performed action |
| action | string | CREATE, UPDATE, TRANSFER, AUDIT, GENERATE_DOCUMENT, IMPORT, EXPORT, LOGIN, ARCHIVE, RETIRE |
| entity_type | string | assets, asset_assignments, etc. |
| entity_id | bigint | Record ID |
| entity_identifier | string | Asset ID or similar |
| field_changed | string, nullable | |
| old_value | text, nullable | |
| new_value | text, nullable | |
| source | string | Web UI, Import, API, Audit, FAT, Admin |
| metadata | jsonb, nullable | |
| created_at | timestamp | |

## Relationships Summary

```text
Asset
 ├── Company (many-to-one)
 ├── Category (many-to-one)
 ├── Location (many-to-one)
 ├── Status (many-to-one)
 ├── Assignments (one-to-many) → User
 ├── Audits (one-to-many)
 ├── Repairs (one-to-many)
 ├── Transfers (one-to-many)
 ├── Documents (one-to-many)
 ├── Attachments (one-to-many)
 └── Activity Logs (one-to-many)

User
 ├── Assignments (many-to-one as assignee)
 ├── Audits (many-to-one as auditor)
 ├── Repairs (many-to-one as creator)
 ├── Transfers (many-to-one as submitter/approver/receiver)
 ├── Documents (many-to-one as generator)
 └── Activity Logs (many-to-one as actor)
```
