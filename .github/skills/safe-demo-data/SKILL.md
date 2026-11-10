---
name: safe-demo-data
description: Create reproducible seeders and fixtures using fictional, non-sensitive data only.
---

# Safe demo and test data

Adapted for this project from [Sultonisky/keep-it-out-of-git](https://github.com/Sultonisky/keep-it-out-of-git), MIT licensed.

- Use deterministic fictional names such as `Sample User`, and reserved domains such as `example.test` or `example.invalid`.
- Use fake, clearly nonfunctional identifiers and credentials. Never copy production rows, logs, screenshots, spreadsheets, or API responses into fixtures.
- Keep fixtures minimal and tied to the behavior under test; do not make test data look like an export from a real employee or device inventory.
- Seeders used in production must be safe to run in production: separate demo-only seeders and require an explicit non-production environment guard before inserting demo data.
- Review generated fixtures for names, emails, device identifiers, IP addresses, tokens, and metadata before tracking them.

See [the project data-safety policy](../../../docs/data-safety.md).
