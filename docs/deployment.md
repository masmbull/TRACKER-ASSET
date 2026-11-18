# Production deployment — TRACKER-ASSET

This runbook is for a **new server** as well as regular releases. Keep credentials in the
server's private environment file or a secrets manager and in GitHub's protected Actions
settings—never in this document, an issue, terminal transcript, or commit.

## Production Target

The office Ubuntu server, running the stack in `docker-compose.prod.yml` as Docker
containers behind a reverse proxy that terminates HTTPS for
`tracker.growthuptogether.web.id`. No Kubernetes — the whole deployment is Docker
Compose, and a self-hosted GitHub Actions runner installed on the server drives it
locally.

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

1. Install Docker Engine and the Compose v2 plugin using Docker's official installation
   guide for the server operating system. Also install Git if it is missing
   (`sudo apt install git`); the self-hosted runner needs it to check out the commit.
2. Create a dedicated deploy user and put it in the `docker` group so it can run Compose:
   ```bash
   sudo adduser deploy
   sudo usermod -aG docker deploy
   ```
   Re-login for the group change to take effect. Membership in the Docker group grants
   root-equivalent access; use a dedicated trusted account.
3. Create a private deployment directory and environment file owned by that user:
   ```bash
   sudo install -d -o deploy -g deploy -m 750 /opt/tracker-asset
   sudo install -o deploy -g deploy -m 600 /dev/null /opt/tracker-asset/.env
   sudoedit /opt/tracker-asset/.env
   ```
   Configure at least `APP_KEY`, `APP_URL`, `ASSET_QR_BASE_URL`, `DB_DATABASE`,
   `DB_USERNAME`, `DB_PASSWORD`, and the required `MAIL_*` values. Generate unique values
   with an approved secrets manager; do not copy development or example credentials.
   The deployment workflow copies Compose/Nginx configuration into this directory,
   **not** this environment file.
4. Install the self-hosted runner as the `deploy` user. In GitHub, open
   **Settings → Actions → Runners → New self-hosted runner**, select **Linux → x64**, and
   run the commands it shows. The download URL and registration token are on that page;
   the token expires after one hour.
   ```bash
   sudo -iu deploy
   mkdir -p ~/actions-runner && cd ~/actions-runner
   curl -o actions-runner-linux-x64.tar.gz -L <DOWNLOAD_URL_FROM_GITHUB>
   tar xzf ./actions-runner-linux-x64.tar.gz
   ./config.sh --url https://github.com/masmbull/TRACKER-ASSET --token <FRESH_TOKEN_FROM_GITHUB> --labels production
   sudo ./svc.sh install deploy
   sudo ./svc.sh start
   ```
   Run `config.sh` as `deploy`, never with `sudo`; only the service commands need
   `sudo`. If the archive was already extracted with `sudo`, fix its ownership before
   continuing (substitute the actual runner directory if different):
   ```bash
   sudo chown -R deploy:deploy /opt/tracker-asset/actions-runner
   sudo -iu deploy
   cd /opt/tracker-asset/actions-runner
   ./config.sh --url https://github.com/masmbull/TRACKER-ASSET --token <FRESH_TOKEN_FROM_GITHUB> --labels production
   sudo ./svc.sh install deploy
   sudo ./svc.sh start
   ```
   The runner polls GitHub outbound over HTTPS, so the server needs no inbound SSH port
   and no firewall exception. The `production` label ensures deploy jobs only run on this
   runner. It must stay online for deploys; a queued job waits for it.
5. Point the HTTPS reverse proxy or tunnel at the server's configured `HTTP_PORT`.

In GitHub, under **Settings → Secrets and variables → Actions**, add:

| Secret | Value |
|---|---|
| `DEPLOY_PATH` | optional, defaults to `/opt/tracker-asset` |

Nothing else is needed: the GHCR pull authenticates with the run's own `GITHUB_TOKEN`,
so there is no registry password to store, and the runner connects outbound so there
are no SSH host, user, key, or port secrets.

### First deployment and administrator provisioning

1. Push the reviewed release to `main` and wait for CI and Deploy to finish.
2. Confirm the app, PostgreSQL, Redis, Nginx, and scheduler containers are healthy.
   The app entrypoint applies migrations and reference-data seeders. It does **not** create
   a shared/default user account.
3. Create the initial administrator interactively on the server. The prompt masks
   passwords and only the password hash is stored:

   ```bash
   cd /opt/tracker-asset
   docker compose -f docker-compose.prod.yml exec app php artisan tracker:admin:create
   ```

4. Sign in, add other users through **Access Control**, verify `/services`, and confirm
   HTTPS, monitoring, and backups before operational handover.

If the command reports an administrator already exists, use the authorized account
management/recovery procedure; do not create a second bootstrap credential by editing
the database directly.

### Regular releases

Push to `main` and watch the **Deploy** workflow. It runs on the self-hosted runner on
the server, copies `docker-compose.prod.yml` and `docker/nginx.conf` into the deploy
directory, pulls the commit's image, re-copies the compiled front-end assets, and
recreates the containers. The app container runs the migrations as it starts, so a
release applies them before php-fpm answers a request.

The `assets` step also deletes the compiled Blade views from the `storage` volume.
That volume is persistent, and Blade only recompiles a view when the source file is
newer than the compiled copy - a file copied into the image keeps its checkout
mtime, which is older than what the previous release compiled. Skipping the delete
makes every release after the first serve the first release's markup.

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
ci.yml     → composer install → tests (SQLite + PostgreSQL) + Pint + sensitive-data guards
               → Windows Agent checks + build + deploy configuration checks
              → build Docker image
              → push ghcr.io/masmbull/tracker-asset:<commit sha>
deploy.yml → fires on workflow_run once ci.yml is green, on the self-hosted
               runner on the server
               → copy compose/nginx files into /opt/tracker-asset
               → docker login ghcr.io → pull <commit sha> → up -d
```

- **CI (`ci.yml`):** the image job declares `needs: [tests, tests-postgres,
  windows-agent, sensitive-data, pint, deploy-config]`, so the order is enforced
  by the workflow rather than by convention — a commit that fails either database
  engine, the Windows Agent checks/build, the tracked-path/secret scan, the style
  gate, or the deploy configuration checks never gets an image. Pull requests
  build the same Dockerfile but do not publish.
- **Deploy configuration (`deploy-config`):** runs
  `scripts/check-deploy-config.sh`, which asserts the shape of `deploy.yml`,
  `docker-compose.prod.yml`, and `docker/nginx.conf` — no outbound SSH from a
  hosted runner, no SSH secrets, a self-hosted `production` runner, one deploy at
  a time, an image that is pulled rather than built, only nginx publishing a port,
  and an nginx upstream held in a variable so a recreated container cannot leave
  it dialing a dead IP. It is a script rather than a PHPUnit case because what it
  guards is YAML and nginx syntax, and it has to hold on the runner where there
  is no PHP.
- **Deploy (`deploy.yml`):** never builds or tests. It runs on the self-hosted
  runner installed on the server, so it needs no inbound SSH and no SSH secrets.
  It waits for `CI` to finish successfully on `main` and then deploys the commit
  CI built, so the tag it pulls does not exist unless every gate passed.
  `workflow_dispatch` redeploys the current `main` without a new commit.
- **Registry:** GHCR, `ghcr.io/masmbull/tracker-asset:<commit sha>`, with
  `latest` alongside it as a pointer for humans reading the package page — never
  what a deployment pulls. The name is lowercased before pushing; GHCR rejects
  `ghcr.io/masmbull/TRACKER-ASSET` with "invalid reference format".
- **Auth:** CI publishes with the run's `packages: write` scope, and the deploy
  run logs the server in with the same built-in `GITHUB_TOKEN` (`packages: read`)
  before pulling — no repository secret exists in this path and none can silently
  expire.
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
