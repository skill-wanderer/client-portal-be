# Client Portal Backend

This repository is the Laravel 12 backend for the Skill Wanderer client portal.

`main` is the canonical branch.

The canonical engineering runtime is:

- Laravel 12 on PHP 8.2+
- PostgreSQL 18 for persisted read and write data
- Redis 7 for session and cache infrastructure
- Docker Compose for deterministic local topology

SQLite is not part of the supported local runtime, test runtime, or live workflow validation path.

## Platform Overview

The backend is built around a controlled write platform rather than ad hoc CRUD mutations.

Key characteristics:

- DTO-first HTTP contracts
- read and write responsibilities separated at the application boundary
- persisted read models for workspaces, projects, and tasks
- optimistic concurrency through explicit version fields
- database-backed idempotency reservations and stored replay payloads
- durable mutation event persistence
- transaction-safe workflow coordination across project and task aggregates
- Keycloak-backed auth flow with local callback support on `127.0.0.1`

Workflow semantics currently enforced:

- when all active tasks become done, the project lifecycle transitions from `active` to `completed`
- when a completed task reopens, the project lifecycle transitions from `completed` to `active`
- generic project updates are not allowed to perform `completed -> active`; that transition remains localized to workflow coordination

## Runtime Topology

| Service | Purpose | Host access | Notes |
| --- | --- | --- | --- |
| `backend-app` | Laravel API runtime | `http://127.0.0.1:8003` | Health-checked through `/up` |
| `postgres` | Canonical persistence runtime | `127.0.0.1:15432` | PostgreSQL 18 with the named volume `client-portal-be-postgres18-data` |
| `redis` | Session and cache infrastructure | `127.0.0.1:16379` | Uses the named volume `client-portal-be-redis-data` and must keep `REDIS_CLIENT=predis` |

The app container talks to Postgres and Redis over the internal Docker network. Host-side `artisan` and test commands talk to the same services through the fixed host ports above.

Compose hardening that is now part of the runtime contract:

- project name is pinned to `client-portal-be`
- network name is pinned to `client-portal-network`
- service container names are explicit
- service restart policy is `unless-stopped`
- backend startup clears cached config and route artifacts before boot
- PostgreSQL 18 uses a new named volume so the old PostgreSQL 17 data directory is not reused accidentally

## Prerequisites

- Docker Desktop with `docker compose`
- PHP 8.2+ and Composer 2 if you want to run `artisan` directly on the host
- Node.js if you need Vite asset workflows
- a valid `KEYCLOAK_CLIENT_SECRET` in `.env`

If you are not using host PHP, run the `artisan` and `composer` commands below through `docker compose exec backend-app ...`.

## Quick Start

1. Copy `.env.example` to `.env` if the file does not already exist.
2. Set `KEYCLOAK_CLIENT_SECRET` in `.env`.
3. Install PHP dependencies.
4. Start the canonical runtime.
5. Rebuild the database from migrations.
7. Execute the test suite.

Host CLI flow:

```bash
composer install
docker compose up --build -d
php artisan migrate:fresh --seed
php artisan test
```

Container CLI flow:

```bash
docker compose up --build -d
docker compose exec backend-app composer install
docker compose exec backend-app php artisan migrate:fresh --seed
docker compose exec backend-app php artisan test
```

## PostgreSQL 18 Safety

Do not perform an in-place upgrade of a PostgreSQL 17 data directory.

The Compose topology now uses the named volume `client-portal-be-postgres18-data` so a PostgreSQL 18 boot cannot silently attach the previous PostgreSQL 17 volume.

The canonical upgrade and recovery path is a deterministic rebuild:

```bash
docker compose down -v
docker compose up --build -d
php artisan migrate:fresh --seed
```

That recreates `client_portal` and `client_portal_test` from migrations plus the Postgres init scripts.

If you still have an old PostgreSQL 17 volume from a prior runtime, leave it detached until you have explicitly decided whether it should be archived or deleted. Do not mount it into the PostgreSQL 18 container.

## Daily Commands

Start or refresh the runtime:

```bash
docker compose up --build -d
```

Stop the runtime:

```bash
docker compose down
```

Reset the runtime state completely:

```bash
docker compose down -v
```

Rebuild the full deterministic runtime from scratch:

```bash
docker compose down -v
docker compose up --build -d
php artisan migrate:fresh --seed
php artisan test
```

Tail backend logs:

```bash
docker compose logs -f backend-app
```

Run all automated tests:

```bash
php artisan test
```

Run the workflow-focused regression slice:

```bash
php artisan test tests/Unit/ClientPortal/Write tests/Feature/ClientTaskStatusMutationApiTest.php
```

Run migrations and seeders against the canonical Postgres runtime:

```bash
php artisan migrate:fresh --seed
```

## Current Validation Baseline

The current repository baseline has been validated with the canonical PostgreSQL 18 and Redis runtime.

Executable checks that passed:

- `docker compose config`
- `docker compose down -v`
- `docker compose up --build -d`
- `php artisan migrate:fresh --seed`
- `php artisan test`
- `php artisan test tests/Feature/ClientTaskCreateApiTest.php tests/Feature/ClientTaskStatusMutationApiTest.php tests/Feature/ClientProjectTasksApiTest.php`

Observed results:

- full suite: 61 tests passed, 269 assertions passed
- focused workflow slice: 19 tests passed, 126 assertions passed

Observed PostgreSQL 18 runtime invariants:

- server version: `18.3`
- timezone: `UTC`
- encoding: `UTF8`
- collation and ctype: `C.UTF-8`
- default transaction isolation: `read committed`
- UUID cast, JSONB extraction, timestamp microseconds, and core workflow indexes all behaved as expected

Live runtime validation also passed for:

- `GET /v1/auth/me`
- authenticated workspace project reads on the canonical runtime
- task creation on the onboarding project
- idempotent create replay returning the stored success response without duplicate task writes
- final active task completion driving `active -> completed`
- idempotent replay returning the stored success response without duplicate workflow events
- stale write rejection returning `409 conflict` with `STALE_WRITE`
- completed task reopen driving `completed -> active`
- persisted event counts remaining singular for each workflow transition

## Workflow Validation Procedure

Use this when validating the workflow layer outside PHPUnit:

1. Start the Docker runtime with `docker compose up --build -d`.
2. Run `php artisan migrate:fresh --seed`.
3. Create a session for `user-123` through `App\Services\Session\SessionService`.
4. Verify `GET /v1/auth/me` returns `200` for that session.
5. Call `GET /api/v1/client/projects` and resolve the seeded Atlas and Onboarding project IDs from the returned names rather than hard-coding historical IDs.
6. Create an onboarding task with a fixed idempotency key and replay the same request to confirm the payload is stable and the task is only written once.
7. Prepare the Atlas project so only the remaining active task is incomplete, then patch that task to `done` with `expectedVersion=1` and a fresh idempotency key.
8. Replay the same PATCH request with the same idempotency key and verify the response is unchanged.
9. Submit a stale write for the same Atlas task with `expectedVersion=1` and verify the API returns `409 conflict` with `STALE_WRITE`.
10. Patch the seeded done Atlas task back to `todo` with a new idempotency key and verify the project reopens.
11. Inspect `client_mutation_events` or `ClientMutationEvent` counts to confirm the workflow events were persisted once.

## Troubleshooting

- If you are moving from an older PostgreSQL 17 runtime, do not reuse the previous data directory. Use `docker compose down -v` and let Compose create `client-portal-be-postgres18-data`.
- If live auth returns `The auth session could not be resolved.`, verify the app is running through `docker compose up` and not through `php artisan serve` inside the container. The hardened runtime relies on the Compose-provided container env plus `php -S`.
- If host-side `artisan` commands cannot reach Postgres or Redis, confirm that `docker compose ps` shows healthy services and that ports `15432` and `16379` are free on the host.
- If you changed environment values recently, restart the stack so the backend container clears cached config and routes on boot.

## Engineering Guardrails

- Use `127.0.0.1`, not `localhost`, for backend URLs, callback URLs, and local cookie-domain-sensitive flows.
- Keep `REDIS_CLIENT=predis`. The `phpredis` extension is not available in this workspace.
- Do not reintroduce SQLite defaults in `.env`, `.env.example`, `config/database.php`, `composer.json`, or `phpunit.xml`.
- Do not recreate `database/database.sqlite` or any parallel SQLite bootstrap path.
- Preserve database-backed idempotency, durable event persistence, and rollback guarantees when changing write flows.
- Keep workflow-derived lifecycle transitions localized to the workflow coordination layer.

## Branch Governance

- `main` is the canonical branch.
- Runtime, persistence, or workflow changes are not ready to merge until the canonical runtime boots cleanly and the automated suite passes.
- Compose topology, environment defaults, PHPUnit configuration, and documentation should move together in the same change set to prevent runtime drift.
