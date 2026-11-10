# Document workflow — templates, PDFs, and tracking

TRACKER-ASSET creates FAT, BAST, TRANSFER, RETURN, and ASSIGNMENT documents. Documents
can be linked to an asset or created with manually entered details. Each generated record
is persistent and discoverable; it is not just a PDF in a temporary browser tab.

## Create from the Documents page

1. Open **Documents** and choose **Create Document**.
2. Select **Blank document** to enter asset, recipient, and handover details manually, or
   choose an existing asset to prefill the form from the register.
3. Review the document type, optional template, parties, dates, and asset details.
4. Submit to generate, save, and preview the PDF.

## Create from an asset

1. Open an asset and select its **Documents** tab.
2. Choose **Generate FAT/BAST**. A modal asks for the document type and an optional active
   template matching that type.
3. Continue to the document form. Asset, assignment, company, location, and transfer data
   are prefilled where available; review signatories, dates, checklists, and notes.
4. Submit. Laravel validates the type and template, allocates the document number, renders
   an A4 PDF, persists the payload and record, and adds an activity-log entry.
5. Preview, print, or download the generated document.

## Create from the Templates page

1. Open **Templates** and select **Use template** for an active template.
2. In the pop-up modal, choose the asset.
3. Continue to the same document form, with the selected template and document type already
   selected. The server revalidates that the template is active and matches the type.

Templates define field metadata and document type. They do not bypass the standard server
validation, asset authorization, PDF renderer, or document audit trail.

## Tracking and retrieval

`/documents` is the cross-asset document register. It shows generated document number,
type, title, linked asset, generated date, creator, and download action. Search matches the
number, title, or asset ID; the type selector narrows the list. The displayed total refers
to all document records, not only the current page or filtered results.

The asset's **Documents** tab remains the per-asset history. A manually added document
record is for an existing external file; use the generate flow when TRACKER-ASSET should
create the PDF itself.

## Persistence and numbering

- Metadata and the generated payload are stored on the `documents` record.
- The PDF is stored under the configured Laravel filesystem path (default: `documents/`).
- The generated record links to its asset, optional transfer/template, and creator.
- Numbering rules are configured in `config/documents.php`; uniqueness is enforced by the
  database as a final guard.
- Signatures are typed names or an optional image, not a legally binding digital signature.

## Document types

| Type | Typical use |
|---|---|
| FAT | Acceptance / handover form |
| BAST | Berita Acara Serah Terima |
| TRANSFER | Asset moved between holders/locations |
| RETURN | Asset returned by its holder |
| ASSIGNMENT | Initial or updated assignment |

Run `php artisan test --filter=DocumentTemplateTest` to verify template selection,
generation, and cross-asset tracking behavior.
