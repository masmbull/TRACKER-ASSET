# Documentation library

Use this index to find current setup instructions and workflow references. Keep credentials
in environment files or a secrets manager; this documentation intentionally contains no
passwords, API tokens, private keys, or live configuration values.

## Start here

| Guide | What it answers |
|---|---|
| [`../README.md`](../README.md) | Product overview, quick start, install and verification |
| [`../TODO.md`](../TODO.md) | Open UI polish and release verification work |
| [`local-development.md`](local-development.md) | Fresh clone, Docker setup, tests, first administrator |
| [`deployment.md`](deployment.md) | New production server, CI/CD, scheduler, backup and rollback |
| [`architecture.md`](architecture.md) | Components, data flow, and trust boundaries |
| [`../TRACKER-ASSET-PRD.md`](../TRACKER-ASSET-PRD.md) | Product requirements and design history |

## Operational workflows

| Guide | Workflow |
|---|---|
| [`document-workflow.md`](document-workflow.md) | Template modal, PDF generation, and document tracking |
| [`transfer-workflow.md`](transfer-workflow.md) | Assignment/ownership transfer and history |
| [`audit-workflow.md`](audit-workflow.md) | Server-side audit comparison and review |
| [`audit-api.md`](audit-api.md) | Agent API, authentication, telemetry, and commands |
| [`import-workflow.md`](import-workflow.md) | Spreadsheet staging, mapping, validation, and import |
| [`ike-bast-inspection.md`](ike-bast-inspection.md) | IKE BAST receiving inspection |
| [`m365-directory.md`](m365-directory.md) | Optional Entra and Microsoft Graph directory integration |

## Data and operations references

| Guide | Contents |
|---|---|
| [`database-schema.md`](database-schema.md) | Tables, relationships, migration notes |
| [`asset-data-mapping.md`](asset-data-mapping.md) | Import fields mapped to the asset register |
| [`backup-recovery.md`](backup-recovery.md) | Backup and restore runbook |
| [`../agent/README.md`](../agent/README.md) | Windows agent build, installation, and operation |

### Source-of-truth note

Workflow guides describe the current implementation; the PRD records requirements and
historical decisions and may include planned work. If they differ, verify behavior in the
application and tests before treating a PRD checklist as implemented.
