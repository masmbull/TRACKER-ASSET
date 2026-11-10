---
name: sensitive-data-git
description: Keep real people data, credentials, operational records, and private uploads out of Git.
---

# Sensitive data in Git

Adapted for this project from [Sultonisky/keep-it-out-of-git](https://github.com/Sultonisky/keep-it-out-of-git), MIT licensed.

- Never add production exports, asset registers, employee information, tokens, credentials, private keys, database dumps, generated documents, or private uploads to source control.
- Treat `storage/app/private/` as private. Its tracked `.gitignore` sentinel is the only allowed file in that directory.
- `storage/app/imports/`, `storage/app/documents/`, and `storage/app/attachments/` contain runtime data and must remain untracked.
- `.env.example` may contain safe placeholders. Real `.env` files and other environment variants must not be committed.
- Before staging, inspect `git status --short` and `git diff --cached --name-only`; do not stage everything blindly.
- Run `bash scripts/check-sensitive-paths.sh` and the Gitleaks scan before merging.
- If a real secret is exposed, do not paste it into a report or allowlist. Revoke/rotate it and follow the incident procedure.

See [the project data-safety policy](../../../docs/data-safety.md) for path-specific controls.
