# Repository-specific safety rules

- Keep real asset registers, employee data, credentials, API tokens, exports, database dumps, backups, logs, and generated documents out of Git.
- Treat `storage/app/private/` as private; `storage/app/private/.gitignore` is its only allowed tracked file.
- Use fictional deterministic fixtures and reserved example domains. Never copy production records into tests or seeders.
- Before staging, review `git status --short` and `git diff --cached --name-only`; do not stage all files blindly.
- Run `bash scripts/check-sensitive-paths.sh` and preserve the required Gitleaks check in `.github/workflows/ci.yml`.
- Never print a detected secret in a report or allowlist. Rotate exposed credentials and follow [the data-safety policy](../docs/data-safety.md).
