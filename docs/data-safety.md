# Data safety in Git

This repository contains an asset register and an agent that handles operational data. Real people data, credentials, exports, and generated documents must stay outside Git.

## Repository boundaries

- `.env.example` may contain placeholders; real `.env` files must not be tracked.
- Keep real asset workbooks, database files and dumps, logs, keys, and backups out of the repository.
- `storage/app/private/` is private. The tracked `storage/app/private/.gitignore` sentinel is its only allowed tracked file.
- Runtime imports, generated documents, and attachments under `storage/app/` must not be tracked.
- `agent/TrackerAgent/InstallerWindow.xaml.cs.bak` is an existing source-code snapshot, not an operational-data backup; it is the only `.bak` path explicitly exempted by the guard.
- Seeders, factories, and tests use fictional, reproducible data only. Do not turn production exports or real agent responses into fixtures.

## Checks

- Run `bash scripts/check-sensitive-paths.sh` before committing. On Windows, use Git Bash or WSL.
- The `sensitive-data` CI job checks tracked paths and scans commits with Gitleaks. Image publishing depends on that job.
- Keep Gitleaks' default rules enabled. Any false-positive exception must be narrow, verified, and documented; never add a real secret to an allowlist.
- If a secret is exposed, revoke or rotate it immediately. Removing it from the latest commit is not enough if it remains in history.

The policy and guard structure are adapted from [Sultonisky/keep-it-out-of-git](https://github.com/Sultonisky/keep-it-out-of-git) (MIT). The adapted project skills are in `.github/skills/`; the upstream license is preserved in [`licenses/keep-it-out-of-git-MIT.txt`](licenses/keep-it-out-of-git-MIT.txt).
