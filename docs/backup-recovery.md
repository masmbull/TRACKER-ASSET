# Backup & Recovery — TRACKER-ASSET

## What Must Be Backed Up

| Item | Where | Priority |
|---|---|---|
| PostgreSQL database | Docker volume `pgdata` | Critical (the single source of truth) |
| Uploaded files | `storage/app/attachments` | High |
| Generated documents | `storage/app/documents` | High |
| Imported staging files | `storage/app/imports` | Low (safe to drop) |
| `.env` | host / secrets manager | Critical (contains app key + DB credentials) |

## Backup Procedures

### PostgreSQL (daily)

```bash
# Inside the container
docker compose exec -T postgres pg_dump -U tracker tracker_asset | gzip > backup_$(date +%F).sql.gz
```

Schedule via cron on the VPS (e.g. 01:00 daily), keep **30 days** retention:

```bash
0 1 * * * cd /opt/tracker-asset && docker compose exec -T postgres pg_dump -U tracker tracker_asset | gzip > /backups/db/backup_$(date +\%F).sql.gz && find /backups/db -name '*.sql.gz' -mtime +30 -delete
```

### Uploaded files & documents (daily)

```bash
tar -czf /backups/files/storage_$(date +%F).tar.gz storage/app/attachments storage/app/documents
```

### Off-site copy

Copy `/backups` to object storage (S3 / Backblaze / SharePoint) with versioning enabled — a backup that lives on the same disk as the database is not a backup.

## Recovery Procedures

### Full database restore

```bash
gunzip -c backup_2026-09-22.sql.gz | docker compose exec -T postgres psql -U tracker tracker_asset
```

### Files restore

```bash
tar -xzf /backups/files/storage_2026-09-22.tar.gz -C /opt/tracker-asset
```

### Post-restore checklist

1. `docker compose exec app php artisan migrate --force` (apply any pending migrations)
2. Verify login works (local admin account)
3. Spot-check: asset count, one asset detail, one generated FAT document opens
4. Verify `storage/app/documents` PDFs open from the preview page

## Rules (PRD §56)

- **Never destroy existing asset data** — restores go to a fresh database/volume, never an in-place destructive migration.
- Test the restore procedure at least once before go-live; an untested backup is not a backup.
- The activity log lives in the same database — backing up the database preserves the full audit trail.
