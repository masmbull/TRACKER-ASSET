---
name: gitleaks-guard
description: Detect committed secrets and respond safely without exposing secret values.
---

# Gitleaks guard

Adapted for this project from [Sultonisky/keep-it-out-of-git](https://github.com/Sultonisky/keep-it-out-of-git), MIT licensed.

- CI scans commits with Gitleaks using the repository's `gitleaks.toml`, in addition to checking sensitive paths.
- Do not add broad allowlists or disable default rules. For a confirmed false positive, add the narrowest documented fingerprint/path exception and explain why it is safe.
- Do not reproduce a detected value in logs, issue comments, screenshots, or review notes. Record the rule and file/line only.
- If a real credential is found, treat it as compromised: revoke or rotate it first. Removing it from the latest file does not remove it from Git history.
- Do not rewrite shared history or suppress an alert without maintainer approval.

See [the project data-safety policy](../../../docs/data-safety.md).
