# Local Development — TRACKER-ASSET

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
