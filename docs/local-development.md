# Local development and first-run setup

This guide starts a fresh clone without copying a database, sharing a default login, or
putting credentials in Git. For production setup, use [`deployment.md`](deployment.md).

## Requirements

- Git
- Docker Engine and Docker Compose v2 (Docker Desktop with WSL2 on Windows)
- Optional host tools for non-container development: PHP 8.4, Composer, Node.js 22+

## Fresh clone with Docker (recommended)

```bash
git clone https://github.com/masmbull/TRACKER-ASSET.git
cd TRACKER-ASSET
cp .env.example .env
```

Open `.env` in a local editor and set the values for this machine. Keep the file private;
it is ignored by Git. Generate a unique Laravel application key, then start the stack:

```bash
docker compose build
docker compose run --rm --no-deps -e RUN_MIGRATIONS=false app php artisan key:generate
docker compose up -d
```

On startup, the app waits for PostgreSQL, migrates the database, and seeds reference data.
The user seeder intentionally creates **no accounts**. Create the initial administrator in
an interactive terminal:

```bash
docker compose exec app php artisan tracker:admin:create
```

Enter a name, email, and a unique password when prompted. Password input is hidden; the
command does not print it or store it in plain text. Sign in at <http://localhost:8000>.
The administrator can then add accounts using **Access Control**.

Mailpit is available for local mail inspection at <http://localhost:8024>.

## Local development without Docker

Install PHP 8.4 and the extensions required by `composer.json`, Composer, Node.js 22+, and
either PostgreSQL or SQLite. Configure `.env` privately, then run:

```bash
composer install
php artisan key:generate
php artisan migrate --seed
npm ci
npm run build
php artisan tracker:admin:create
php artisan serve --port=8000
```

For front-end hot reload, use `npm run dev` in a second terminal. For a queue worker when
using an asynchronous queue driver, run `php artisan queue:work` separately.

## Tests

SQLite is the fast local default and is configured as an in-memory database by PHPUnit:

```bash
php artisan test
vendor/bin/pint --test
```

The CI workflow also runs the suite on PostgreSQL. To test PostgreSQL locally, create a
dedicated throwaway database and configure its connection in the current process or a
private test `.env`. Never point `RefreshDatabase` at a shared or production database.
Keep connection values out of documentation and shell history.

On Windows PowerShell, set environment variables for the process rather than using the
POSIX `NAME=value command` syntax:

```powershell
$env:DB_CONNECTION = 'pgsql'
$env:DB_HOST = '127.0.0.1'
$env:DB_DATABASE = 'tracker_test'
php artisan test
```

For Docker-only test runs, use the Compose services and a dedicated test database; avoid
overriding the configured development database accidentally.

## Services and ports

| Service | Local address | Role |
|---|---|---|
| Web app (Nginx → PHP-FPM) | `http://localhost:8000` | Application |
| PostgreSQL | `localhost:5432` | Relational data |
| Redis | `localhost:6379` | Optional cache, sessions, and queue |
| Mailpit UI | `http://localhost:8024` | Inspect captured email |
| Mailpit SMTP | `localhost:1025` | Local mail transport |

The development Compose file publishes database and Redis ports for local tooling. Do not
publish those ports on a public server; production Compose keeps them on its private
network.

## Common commands

```bash
docker compose logs -f app
docker compose exec app php artisan schedule:list
docker compose exec app php artisan test
docker compose exec app php artisan cache:clear
docker compose down
```

`docker compose down -v` deletes local database and Redis volumes. Use only when you
intend to reset local data.

## Existing database and legacy seed accounts

The current `UserSeeder` does not create fixed demo accounts. Existing databases are not
automatically changed by that update: review old seeded accounts, reset or remove any
that should not remain, and verify a trusted administrator can sign in. Back up the
database before changing users or migration history.

Some repository data files are deliberately excluded because they may contain real
inventory information. A fresh clone can still run the application; import approved
asset data through **Assets → Import** after setup.
