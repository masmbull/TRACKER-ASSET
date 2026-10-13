# Deployment — TRACKER-ASSET

## Production Target

The office Ubuntu server, running the stack in `docker-compose.prod.yml` as Docker
containers behind a reverse proxy that terminates HTTPS for
`tracker.growthuptogether.web.id`. No Kubernetes — the whole deployment is Docker
Compose, and GitHub Actions drives it over SSH.

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

Deploys are automatic. Every push to `main` runs `ci.yml`, and when it is green
`deploy.yml` pulls the image it published and restarts the stack. There is
nothing to run by hand for a normal release.

### First-time setup (once)

On the server:

1. Install Docker Engine and the Compose v2 plugin:
   ```bash
   curl -fsSL https://get.docker.com | sh
   ```
2. Create the deploy directory and the environment file it reads:
   ```bash
   sudo mkdir -p /opt/tracker-asset
   sudo nano /opt/tracker-asset/.env
   ```
   Fill in at least `APP_KEY` (`php artisan key:generate --show`), `APP_URL`,
   `ASSET_QR_BASE_URL`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` and your
   `MAIL_*` settings. This file is never committed and never uploaded by the
   workflow — it is the one thing only you can write.
3. Put the deploy user in the `docker` group so it can run Compose without sudo:
   ```bash
   sudo usermod -aG docker "$USER"   # log out and back in afterwards
   ```
4. Point the reverse proxy / Cloudflare tunnel for
   `tracker.growthuptogether.web.id` at the host's port 80 (or whatever
   `HTTP_PORT` you set in `.env`).

In GitHub, under **Settings → Secrets and variables → Actions**, add:

| Secret | Value |
|---|---|
| `DEPLOY_HOST` | address the runner SSHes to |
| `DEPLOY_USER` | the deploy user, in the `docker` group |
| `DEPLOY_SSH_KEY` | private key whose public half is in that user's `~/.ssh/authorized_keys` |
| `DEPLOY_PORT` | optional, defaults to `22` |
| `DEPLOY_PATH` | optional, defaults to `/opt/tracker-asset` |

Nothing else is needed: the GHCR pull authenticates with the run's own
`GITHUB_TOKEN`, so there is no registry password to store.

### Then

Push to `main` and watch the **Deploy** workflow. It uploads
`docker-compose.prod.yml` and `docker/nginx.conf`, pulls the commit's image,
re-copies the compiled front-end assets, and recreates the containers. The app
container runs the migrations as it starts, so a release applies them before
php-fpm answers a request.

The manual equivalent, if you ever need to run it on the server yourself:

```bash
cd /opt/tracker-asset
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml run --rm --no-deps assets
docker compose -f docker-compose.prod.yml up -d --remove-orphans
```

## Scheduler (required in production)

Two behaviours are not event-driven and simply never happen unless something
runs the Laravel scheduler every minute:

| Command | Cadence | What breaks without it |
|---|---|---|
| `agent:resolve-stale-commands` | every 5 min | A command queued for a revoked/expired token stays `pending` forever, and the fleet page shows a permanent "Uninstall queued" badge on a machine nobody can reach. |
| `audit:sweep` | weekly, Mon 06:00 | The register stops re-verifying itself; the fleet only refreshes when someone clicks. |

Inside `docker-compose.prod.yml` this is the `scheduler` service, separate from
`app` rather than an override of its command — the image runs `php-fpm` (port
9000, no `artisan serve`), so a combined `command:` would take the web server
down with it:

```yaml
  scheduler:
    image: ${IMAGE_REPOSITORY:-ghcr.io/masmbull/tracker-asset}:${IMAGE_TAG:-latest}
    restart: unless-stopped
    env_file: [.env]
    command: php artisan schedule:work
```

`restart: unless-stopped` is what makes this survive a server reboot — without it
the fleet quietly stops re-verifying itself the next time the host restarts.

Verify it is alive with:

```bash
cd /opt/tracker-asset
docker compose -f docker-compose.prod.yml exec app php artisan schedule:list
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

Two workflows, deliberately separate:

```text
Developer → git push (main) → GitHub Actions
   ci.yml     → composer install → tests (SQLite + PostgreSQL) + Pint
              → build Docker image
              → push ghcr.io/masmbull/tracker-asset:<commit sha>
   deploy.yml → fires on workflow_run once ci.yml is green
              → scp compose files to the server
              → docker login ghcr.io → pull <commit sha> → up -d
```

- **CI (`ci.yml`):** the image job declares `needs: [tests, tests-postgres,
  pint]`, so the order is enforced by the workflow rather than by convention — a
  commit that fails either engine or the style gate never gets an image. Pull
  requests build the same Dockerfile but do not publish.
- **Deploy (`deploy.yml`):** never builds or tests. It waits for `CI` to finish
  successfully on `main` and then deploys the commit CI built, so the tag it
  pulls does not exist unless every gate passed. `workflow_dispatch` redeploys
  the current `main` without a new commit.
- **Registry:** GHCR, `ghcr.io/masmbull/tracker-asset:<commit sha>`, with
  `latest` alongside it as a pointer for humans reading the package page — never
  what a deployment pulls. The name is lowercased before pushing; GHCR rejects
  `ghcr.io/masmbull/TRACKER-ASSET` with "invalid reference format".
- **Auth:** CI publishes with the run's `packages: write` scope, and the server
  pulls with the same run's `packages: read` scope — both the built-in
  `GITHUB_TOKEN`, so no repository secret exists in this path and none can
  silently expire.
- **Rollback:** pruning keeps the last week of images on the server. To go back,
  set `IMAGE_TAG` to the previous commit's sha in `/opt/tracker-asset/.env` and
  run the three compose commands from `docker-compose.prod.yml`'s header.
- **Pulling one by hand** (the package is private unless you change its
  visibility): `docker login ghcr.io` with a PAT carrying `read:packages`, then
  `docker pull ghcr.io/masmbull/tracker-asset:<sha>`.

## Backup Strategy

- PostgreSQL automated daily backup
- Backup retention: configurable (e.g., 30 days)
- Document/PDF backup strategy (SharePoint)
- Documented recovery procedure
