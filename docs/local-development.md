# Local Development — TRACKER-ASSET

## Running the suite against PostgreSQL

SQLite is the default because it needs no services, and it is the engine that
hides a whole class of portability bug. Run both before trusting a change:

```bash
php artisan test   # SQLite, in-memory

# PostgreSQL — the production engine (PRD §14, §47)
createdb tracker_test
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=tracker_test DB_USERNAME=postgres DB_PASSWORD=postgres \
  php artisan test
```

CI runs both as separate jobs, so a change that only works on one engine is
caught on the pull request rather than on deploy.

### On Windows / PowerShell

The `VAR=value && command` form above is POSIX shell. PowerShell has no `VAR=value`
prefix, and routing it through `cmd /c` fails in a way that looks like a broken
application rather than a broken invocation:

```powershell
cmd /c "set DB_CONNECTION=pgsql && php artisan test"
# every test fails: Database connection [pgsql DB_HOST=127.0.0.1 ...] not configured
```

The connection name becomes the whole assignment string. Set the variables on the
process instead, then start PHP:

```powershell
[Environment]::SetEnvironmentVariable('DB_CONNECTION', 'pgsql', 'Process')
[Environment]::SetEnvironmentVariable('DB_HOST',       '127.0.0.1', 'Process')
[Environment]::SetEnvironmentVariable('DB_PORT',       '5432', 'Process')
[Environment]::SetEnvironmentVariable('DB_DATABASE',   'tracker_test', 'Process')
[Environment]::SetEnvironmentVariable('DB_USERNAME',   'postgres', 'Process')
[Environment]::SetEnvironmentVariable('DB_PASSWORD',   'postgres', 'Process')

php artisan test
```

`Start-Process` has no `-Environment` on Windows PowerShell 5.1 either; setting it
on the current process first works because the child inherits it.

To be certain which engine a run used, drop the test database and watch it come
back:

```powershell
psql -U postgres -c "DROP DATABASE IF EXISTS tracker_test;"
php artisan test
psql -U postgres -d tracker_test -c "\dt"
```

A table count of 29 means the run really migrated and queried PostgreSQL. Byte
counts of the output prove nothing — the summary lines are the same length on
both engines.

Two things the second engine finds that the first cannot:

- **Foreign keys are not enforced by SQLite by default**, and the schema declared
  several of them to tables that later migrations created. SQLite accepted the
  forward references; PostgreSQL refuses to build the schema at all.
- **SQLite hands out rowid 1 again after each rolled-back test. A PostgreSQL
  sequence never rewinds.** A fixture written as `company_id => 1` is right for
  the first test and names a company that no longer exists for every test after
  it. `AssetFactory` and the test payloads now resolve the rows they mean.

## If your database predates the migration renumbering

Fourteen initial migrations were renamed on 2026-09-30 to remove forward
foreign-key references, so `asset_transfers` and `document_templates` are
created before the tables that point at them. The schema is unchanged; only the
filenames are.

A database that already ran the old filenames has 14 records under names that no
longer exist, and `php artisan migrate` will try to re-run all of them and fail
with "table already exists". Either rebuild, or re-file the records by name:

```sql
UPDATE migrations SET migration = REPLACE(migration, ...) WHERE migration LIKE '0001_01_01_%';
```

`docs/database-schema.md` lists the full old → new mapping. This is bookkeeping
only — no data is touched, and no migration runs.

## Requirements

- Windows 10/11
- Docker Desktop (with WSL2 backend)
- Git

## Quick Start

```bash
# Clone repo
git clone https://github.com/masmbull/TRACKER-ASSET.git
cd TRACKER-ASSET

# Copy environment
cp .env.example .env

# Start all services
docker compose up -d --build

# Install dependencies (inside container)
docker compose exec app composer install

# Run migrations
docker compose exec app php artisan migrate

# Seed database
docker compose exec app php artisan db:seed

# Run tests
docker compose exec app php artisan test

# Start dev server
docker compose exec app php artisan serve --host 0.0.0.0 --port 8000
```

Access: http://localhost:8000

## Services

| Service | Port | Purpose |
|---|---|---|
| Laravel App | 8000 | Main application |
| PostgreSQL | 5432 | Database |
| Redis | 6379 | Cache, sessions, queue |
| Mailpit | 8025 | Email testing (incoming), 8024 (web UI) |

## Development Commands

```bash
# View logs
docker compose logs -f app
docker compose logs -f postgres

# Stop all services
docker compose down

# Stop and remove volumes (⚠️ deletes all data)
docker compose down -v

# Rebuild after Dockerfile changes
docker compose up -d --build

# Enter container shell
docker compose exec app bash

# Run artisan commands
docker compose exec app php artisan [command]
```

## Authentication (Local)

For local development without Microsoft Entra ID configured:

- Use Laravel's local auth (email/password)
- Seed a default admin user
- Switch to Entra auth when app registration is ready

## Environment Variables

See `.env.example` for all available variables. Copy and adjust before running.

## Directory Structure

```text
TRACKER-ASSET/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   ├── Livewire/          # Blade components with state
│   │   ├── Middleware/
│   │   └── Requests/
│   ├── Models/
│   ├── Policies/              # Authorization policies
│   ├── Services/              # Business logic
│   └── ...
├── bootstrap/
├── config/
├── database/
│   ├── migrations/
│   ├── seeders/
│   └── factories/
├── docker/
│   ├── Dockerfile
│   └── php.ini
├── docs/
│   ├── architecture.md
│   ├── database-schema.md
│   ├── asset-data-mapping.md
│   ├── audit-workflow.md
│   ├── transfer-workflow.md
│   ├── document-workflow.md
│   └── local-development.md
├── public/
├── resources/
│   ├── views/                 # Blade templates
│   ├── js/                    # Alpine.js components
│   └── css/                   # Tailwind
├── routes/
│   ├── web.php               # Web routes (Livewire)
│   └── api.php               # API routes
├── storage/
├── tests/
│   ├── Unit/
│   └── Feature/
├── .env.example
├── .gitignore
├── docker-compose.yml
├── Dockerfile
├── README.md
└── PRD.md
```
