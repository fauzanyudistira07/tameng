# Security Scan System MVP Internal

This repository is split into two applications:

- `backend`: Laravel 12 control plane API for SecSys.
- `frontend`: Vue/Vite dashboard shell.

## Locked Decisions

- Backend stack: Laravel.
- Frontend stack: Vue.
- Database: MySQL/MariaDB for local MVP bootstrap.
- Authentication: internal accounts created by an administrator.
- AI role: advisory security analyst only.
- Worker role: deterministic CLI/container executor only.
- Security rule: no verified target means no execution.
- Scope rule: outside scope means deny.

## Backend Foundation

The backend includes initial migrations for:

- roles, permissions, and admin-created users
- projects, repositories, targets, verifications, and scopes
- immutable authorization records
- scan profiles, jobs, workers, and engine runs
- normalized findings and evidence
- AI analyses, reports, artifacts, audit logs, and policy decisions

Default local admin seeded by `php artisan migrate:fresh --seed`:

- Email: `admin@secsys.local`
- Password: `ChangeMe!12345`

Change this password before using the system beyond local development.

## Frontend Foundation

The frontend is a Vue dashboard shell for the MVP control plane. It currently renders the main navigation, security guardrails, scan profile summary, and workflow shape. Dashboard access is protected by a Vue Router guard backed by the Laravel session.

## Internal Auth

The backend uses Laravel Sanctum SPA/session authentication for local development.

Auth endpoints:

- `GET /sanctum/csrf-cookie`
- `POST /api/login`
- `POST /api/logout`
- `GET /api/user`

Protected MVP endpoint:

- `GET /api/overview`

Control plane endpoints now include:

- `GET|POST|PUT /api/projects`
- `GET|POST|PUT /api/repositories`
- `POST /api/repositories/{repository}/verify`
- `GET|POST|PUT /api/targets`
- `POST /api/targets/{target}/verify`
- `GET|POST|PUT /api/scopes`
- `GET /api/scan-profiles`
- `GET|POST /api/authorizations`
- `GET|POST /api/scan-jobs`
- `POST /api/scan-jobs/{scanJob}/process-simulated`
- `GET /api/findings`
- `GET /api/findings/{finding}`
- `PUT /api/findings/{finding}`

Authorization creation is deterministic: the project must be active, selected repository or target must be verified, the scan profile must be active, and at least one active allow scope must exist.

## Worker Skeleton

The current worker phase is simulated only. It does not execute scanners, does not open network connections, and does not clone repositories. It creates `scan_runs` per planned engine after the Execution Gateway allows each engine.

The current finding phase is also simulated. The simulated worker emits safe placeholder findings through the `FindingNormalizer` to validate schema, deduplication, triage status, and dashboard rendering before real scanner adapters are added.

Manual worker command:

```bash
php artisan secsys:worker-once
```

## Verified Commands

From `backend`:

```bash
php artisan migrate:fresh --seed
php artisan route:list
php artisan test
```

Default local database settings are in `backend/.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=secsys
DB_USERNAME=root
DB_PASSWORD=
```

Create the `secsys` database first, or adjust these values to match your local MySQL/MariaDB account.

From `frontend`:

```bash
npm run build
```
