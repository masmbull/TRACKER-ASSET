---
name: sensitive-path-guard
description: Enforce the repository's sensitive tracked-path blocklist without broad exceptions.
---

# Sensitive path guard

Adapted for this project from [Sultonisky/keep-it-out-of-git](https://github.com/Sultonisky/keep-it-out-of-git), MIT licensed.

- The source of truth is `scripts/check-sensitive-paths.sh`; run it with Bash, including Git Bash or WSL on Windows.
- The script checks tracked paths, so review staged and untracked files too before committing.
- Do not weaken a pattern or add a broad exception to unblock a file. Confirm the file is safe, then use the narrowest exact-path exception with a documented reason.
- `storage/app/private/.gitignore` is the deliberate exact-path exception. Do not allow the rest of that directory.
- Keep the check as a required `sensitive-data` dependency of the image-publishing job in `.github/workflows/ci.yml`.

See [the project data-safety policy](../../../docs/data-safety.md).
