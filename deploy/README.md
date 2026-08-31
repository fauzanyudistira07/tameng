# SecSys Docker Deployment

## First Run

1. Copy the env file:

```bash
cp deploy/backend.env.example deploy/backend.env
```

2. Set `APP_KEY`:

```bash
docker compose run --rm secsys-backend php artisan key:generate --show
```

Paste the value into `deploy/backend.env`.

3. Start services:

```bash
docker compose up -d --build
```

4. Run migrations and seeders:

```bash
docker compose exec secsys-backend php artisan migrate --seed
```

5. Open:

- Frontend: `http://localhost:8080`
- Backend API: `http://localhost:8000`

Default seeded admin:

- Email: `admin@secsys.local`
- Password: `ChangeMe!12345`

## Optional Automatic Startup Tasks

The backend container can run migrations and seeders on startup when explicitly enabled:

```env
SECSYS_RUN_MIGRATIONS=true
SECSYS_RUN_SEEDERS=true
SECSYS_CACHE_BOOTSTRAP=true
```

For controlled deployments, keep migrations manual and run:

```bash
docker compose exec secsys-backend php artisan migrate --force
```

## Healthchecks

Backend health:

```bash
curl http://localhost:8000/api/health
```

The health response checks:

- database connectivity
- Laravel storage writability
- queue table readiness
- repository workspace root
- engine runtime configuration

Docker service status:

```bash
docker compose ps
```

## Updating

```bash
git pull
docker compose build
docker compose up -d
docker compose exec secsys-backend php artisan migrate --force
```

## Backup

Back up at minimum:

- MySQL database
- `secsys_backend_storage` volume
- repository workspace host path

Example database dump:

```bash
docker compose exec secsys-mysql mysqldump -u root -p secsys > secsys.sql
```

## Engine Runtime

Default engine execution is disabled:

```env
SECSYS_ENABLE_REAL_ENGINES=false
```

When enabled, Gitleaks runs through Docker with:

```env
SECSYS_ENGINE_RUNTIME=docker
SECSYS_GITLEAKS_IMAGE=zricethezav/gitleaks:v8.28.0
```

The worker mounts `/var/run/docker.sock` so it can launch short-lived engine containers. This is acceptable for MVP/internal environments, but should be replaced by a separate engine-runner service for harder isolation before production exposure.

Workspace mapping for a Linux server:

```env
SECSYS_REPOSITORY_WORKSPACE_ROOT=/data/repository-workspaces
SECSYS_DOCKER_HOST_WORKSPACE_ROOT=/data/repository-workspaces
```

For a different absolute host path:

```env
SECSYS_DOCKER_HOST_WORKSPACE_ROOT=/srv/secsys/repository-workspaces
```

Set the same path as `SECSYS_HOST_REPOSITORY_WORKSPACE_ROOT` for Docker Compose volume binding.
