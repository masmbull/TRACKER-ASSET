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
9. **Start the scheduler** (see below) — the app is useless without it

## Scheduler (required in production)

Two behaviours are not event-driven and simply never happen unless something
runs the Laravel scheduler every minute:

| Command | Cadence | What breaks without it |
|---|---|---|
| `agent:resolve-stale-commands` | every 5 min | A command queued for a revoked/expired token stays `pending` forever, and the fleet page shows a permanent "Uninstall queued" badge on a machine nobody can reach. |
| `audit:sweep` | weekly, Mon 06:00 | The register stops re-verifying itself; the fleet only refreshes when someone clicks. |

Inside Docker Compose, add a **separate service** rather than overriding the
`app` command — the image runs `php-fpm` (port 9000, no `artisan serve`), so a
combined `command:` would take the web server down with it:

```yaml
  scheduler:
    build:
      context: .
      dockerfile: Dockerfile
    container_name: tracker-asset-scheduler
    restart: unless-stopped
    working_dir: /var/www/html
    volumes:
      - .:/var/www/html
    depends_on:
      - app
    environment:
      - APP_ENV=production
      - APP_KEY=${APP_KEY}
      - DB_HOST=postgres
      - DB_PORT=5432
      - DB_DATABASE=${DB_DATABASE}
      - DB_USERNAME=${DB_USERNAME}
      - DB_PASSWORD=${DB_PASSWORD}
      - REDIS_HOST=redis
      - REDIS_PORT=6379
    command: php artisan schedule:work
```

`restart: unless-stopped` is what makes this survive a VPS reboot — without it
the fleet quietly stops re-verifying itself the next time the host restarts.

Verify it is alive with:

```bash
docker compose exec app php artisan schedule:list
```

Outside Docker, run it on the host:

```bash
php artisan schedule:work
```

`schedule:work` blocks the foreground, so in production run it under Supervisor —
do not just leave it in a terminal, because it dies with the SSH session.

## Production Configuration

- HTTPS enforced
- `APP_DEBUG=false`
- Entra ID authentication
- Server-side authorization (RBAC)
- Secrets in environment variables / secrets manager
- PostgreSQL automated backup with retention

## CI/CD (PRD §47)

Implemented:

```text
Developer → git push → GitHub Actions (ci.yml)
              → composer install → tests (SQLite + PostgreSQL) + Pint
              → build Docker image
              → push image        (main only)
```

The image job declares `needs: [tests, tests-postgres, pint]`, so the order in
that chain is enforced by the workflow rather than by convention: a commit that
fails either engine or the style gate never gets an image.

- **Registry:** GHCR, `ghcr.io/masmbull/tracker-asset:<commit sha>`, with
  `latest` alongside it as a pointer for humans reading the package page —
  never what a deployment pulls. The name is lowercased before pushing; GHCR
  rejects `ghcr.io/masmbull/TRACKER-ASSET` with "invalid reference format".
- **Auth:** the run's `packages: write` scope and the built-in `GITHUB_TOKEN`.
  No repository secret exists in this path, so none can silently expire.
- **Pull requests** build the same Dockerfile but do not publish, so a broken
  build is caught before merge without a branch ever putting a tag on the
  registry.
- **Pulling one** (the package is private unless you change its visibility):
  `docker login ghcr.io` with a PAT carrying `read:packages`, then
  `docker pull ghcr.io/masmbull/tracker-asset:<sha>`.

Still manual, on purpose:

- **Office cluster:** `./deploy.sh` builds the same Dockerfile and pushes to the
  office registry (`192.168.1.178:5000`), which GitHub Actions cannot reach.
  CI proves a green commit builds; deploy.sh is what puts it in front of users.
  Pointing the cluster at GHCR's exact bytes instead would need registry
  credentials on the nodes and internet from them — not configured, not
  required.
- **VPS / `docker compose up -d`** (the second half of §47 and §48's Compose
  target): no VPS is provisioned yet. PRD §52 tracks this as not built.

## Backup Strategy

- PostgreSQL automated daily backup
- Backup retention: configurable (e.g., 30 days)
- Document/PDF backup strategy (SharePoint)
- Documented recovery procedure
