# Document Workflow — TRACKER-ASSET

## Purpose

Generate documents (FAT, BAST, Transfer, Return, Assignment) from asset/transfer data automatically. No manual copy/paste.

## Document Types

| Type | Description |
|---|---|
| FAT | Form Acceptance Test |
| BAST | Penerimaan Barang (Item Reception) |
| Transfer | Asset Transfer Document |
| Return | Asset Return Document |
| Assignment | Asset Assignment Document |

## Document Generation Flow

```text
Asset Detail (or Transfer Detail)
  ↓
Choose Document Type
  ↓
System auto-populates:
  ├── Asset info (ID, Serial, Model, etc.)
  ├── User info (from Entra/Graph)
  ├── Location info
  ├── Company info
  └── Transfer data (if applicable)
  ↓
Preview (web page)
  ↓
Generate PDF (A4, professional, company branding)
  ↓
Download / Print / Preview
```

## Document Numbering

Format examples:
- `FAT/IT/2026/00001`
- `BAST/IT/2026/00001`
- `AST/TRF/2026/00001`

Rules:
- Configurable format per document type
- Must prevent duplicate numbers
- Auto-increment per year and prefix

## FAT/BAST Specifics

### FAT Checklist
- Configurable checklist items (not hard-coded)
- Each item: PASS / FAIL / N/A / Notes
- Auto-prefilled from asset data

### FAT Output
- Printable HTML
- PDF (A4, professional)
- Stored FAT record
- FAT history per asset

### BAST
- Similar to FAT but for item reception
- Includes acceptance criteria

## PDF Requirements

- A4 format
- Professional layout
- Company branding/logo
- Asset information
- Document number
- Date
- Signature section (typed name / signature placeholder)
- Print-friendly

## Signature (MVP)

- Typed name (not legally binding digital signature)
- Signature image/placeholder
- Roles: Yang Menyerahkan, Yang Menerima, Mengetahui, IT

## Storage

- Document metadata in PostgreSQL
- PDF files: metadata in DB, files in SharePoint (Phase 2+), local storage (MVP)
