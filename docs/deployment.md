# Deployment — TRACKER-ASSET

## Production Target

Ubuntu VPS with Docker Compose behind Nginx reverse proxy with HTTPS.

## Infrastructure

```text
Internet
   ↓
Cloudflare / Reverse Proxy (HTTPS)
   ↓
Nginx
   ↓
Laravel Docker
   ↓
PostgreSQL
   ↓
Redis
```

## Deployment Steps

1. Provision Ubuntu VPS (20.04+)
2. Install Docker + Docker Compose
3. Clone repository
4. Configure `.env` with production values:
   - `APP_ENV=production`
   - `APP_URL=https://your-domain.com`
   - `DB_*` production database credentials
   - `ENTRA_*` Microsoft Entra ID credentials
5. Build and start:
   ```bash
   docker compose up -d --build
   ```
6. Run migrations and seed:
   ```bash
   docker compose exec app php artisan migrate --force
   docker compose exec app php artisan db:seed --force
   ```
7. Configure Nginx / reverse proxy with SSL
8. Set up backups (PostgreSQL automated backup)

## Production Configuration

- HTTPS enforced
- `APP_DEBUG=false`
- Entra ID authentication
- Server-side authorization (RBAC)
- Secrets in environment variables / secrets manager
- PostgreSQL automated backup with retention

## CI/CD (Phase 9)

```text
Developer → git push → GitHub Actions → tests → Docker image → push image → deploy VPS → docker compose up -d
```

## Backup Strategy

- PostgreSQL automated daily backup
- Backup retention: configurable (e.g., 30 days)
- Document/PDF backup strategy (SharePoint)
- Documented recovery procedure
