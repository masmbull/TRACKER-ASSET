# TRACKER-ASSET — To do

## UI polish

- [x] Replace application UI icons across every page and component—including dashboard
  stat cards, secondary metric/status cards, empty states, and page-specific controls—with
  the shared rounded-line style. Brand marks and QR/data artwork remain unchanged.
- [x] Remove old bespoke inline icon paths after every UI surface has been audited.
- [ ] Review the icon pass at desktop and mobile breakpoints before marking it complete.
  (PageSweepTest renders every page; a visual pass in a real browser is still needed.)
- [x] Replace the plain page indicator and global processing spinner with branded MITO
  logo animations; honor reduced-motion preferences.

## Git branches

- [x] Move this work onto `feat/secure-bootstrap-docs-ui-polish` instead of leaving it
  directly on `main`.
- [x] Review and prune the already-merged remote branches after confirmation:
  `feat/asset-qr-agent-fleet-improvements`, `feat/script-derive-and-agent-status-redesign`,
  `feature/asset-sequence-script-queue`, and `fix/docker-compose-entrypoint`.

## Verification and release

- [x] Clean the existing repository-wide Pint backlog and restore full-tree Pint CI.
- [ ] Confirm the GitHub Actions run is green for SQLite, PostgreSQL, Pint, and image build.
  (Local equivalents pass: 555 PHPUnit tests and `vendor/bin/pint --test`. The run itself
  could not be queried: the GitHub CLI is not installed and the repository is private.)
- [ ] Review all pending application, documentation, and UI changes before commit/push.
