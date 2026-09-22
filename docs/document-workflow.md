# Document Workflow — TRACKER-ASSET

## Purpose

Generate documents (FAT, BAST, Transfer, Return, Assignment) from asset/transfer data automatically. No manual copy/paste.

## Document Types

| Type | Description |
|---|---|
| FAT | Form Acceptance Test |
| BAST | Berita Acara Serah Terima (Item Reception) |
| TRANSFER | Asset Transfer Document |
| RETURN | Asset Return Document |
| ASSIGNMENT | Asset Assignment Document |

## Document Generation Flow

```text
Asset Detail
  ↓
Generate Document (choose type)
  ↓
System auto-populates:
  ├── Asset info (ID, Serial, Model, etc.)
  ├── User info (current user)
  ├── Location info
  ├── Company info
  ├── Transfer data (optional, linked)
  ├── FAT checklist answers
  └── Signatories (typed names) + notes
  ↓
Preview (HTML page with embedded PDF)
  ↓
Generate PDF (A4, professional, company branding)
  ↓
Download / Print
```

Every payload is persisted on the `documents` record so a document can be
re-rendered at any time. The PDF file is stored under `storage/app/documents/`
(local MVP; SharePoint integration later).

## Document Numbering

Format per type (configurable in `config/documents.php`, `{year}` + `{seq:N}` tokens):

- `FAT/IT/2026/00001`
- `BAST/IT/2026/00001`
- `AST/TRF/2026/00001`
- `AST/RET/2026/00001`
- `AST/ASG/2026/00001`

Rules:

- Sequence is per document type and per year, derived from the highest number
  already issued (never from a plain row count).
- `documents.document_number` has a unique index as the final safety net.

## Manual Documents

The "Add Document" flow still exists for registering already-existing external
files (scanned invoice, warranty letter, photo, repair document). For those the
`file_path`/`file_type` are entered manually.

## FAT/BAST Specifics

### FAT Checklist

- Default checklist items live in `config/documents.php`.
- Each item: PASS / FAIL / N/A (unknown answers default to N/A).
- Rendered as an acceptance checklist table in the PDF.

### FAT Output

- Printable preview page (embedded PDF + Print button).
- PDF (A4, professional).
- Stored document record + file.
- Document history visible per asset.

### BAST

- Same mechanics as FAT but for item reception.
- From/to user, department and location are pulled from the asset or the linked
  transfer record.

## PDF Requirements

- A4 format
- Professional layout
- Company branding
- Asset information
- Document number
- Date
- Signature section (typed name / signature placeholder)
- Print-friendly

Rendered with `barryvdh/laravel-dompdf` (Laravel-compatible PDF package, PRD §34).

## Signature (MVP)

- Typed name (not legally binding digital signature)
- Roles: Yang Menyerahkan, Yang Menerima, Mengetahui, IT

Do not implement legally binding digital signatures unless separately requested.

## Storage

- Document metadata + full render payload in PostgreSQL
- PDF files: local storage (MVP), SharePoint (Phase 8+)

