# Client Portal Backend

This repository is the Laravel 12 backend for the Skill Wanderer client portal.

`main` is the canonical branch.

The canonical engineering runtime is:

- Laravel 12 on PHP 8.2+
- PostgreSQL 17 for persisted read and write data
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
| `postgres` | Canonical persistence runtime | `127.0.0.1:15432` | Creates `client_portal` and `client_portal_test` |
| `redis` | Session and cache infrastructure | `127.0.0.1:16379` | Must use `REDIS_CLIENT=predis` |

The app container talks to Postgres and Redis over the internal Docker network. Host-side `artisan` and test commands talk to the same services through the fixed host ports above.

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
5. Run migrations.
6. Seed the read models.
7. Execute the test suite.

Host CLI flow:

```bash
composer install
docker compose up --build -d
php artisan migrate --force
php artisan db:seed --class=ClientPortalReadModelSeeder --force
php artisan test
```

Container CLI flow:

```bash
docker compose up --build -d
docker compose exec backend-app composer install
docker compose exec backend-app php artisan migrate --force
docker compose exec backend-app php artisan db:seed --class=ClientPortalReadModelSeeder --force
docker compose exec backend-app php artisan test
```

If you need a pristine database state, destroy the Docker volumes and recreate the stack:

```bash
docker compose down -v
docker compose up --build -d
```

That recreates both `client_portal` and `client_portal_test` from the Postgres init scripts.

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
php artisan migrate --force
php artisan db:seed --class=ClientPortalReadModelSeeder --force
```

## Current Validation Baseline

The current repository baseline has been validated with the canonical Postgres and Redis runtime.

Executable checks that passed:

- `docker compose config`
- `docker compose up --build -d`
- `php artisan test`
- `php artisan test tests/Feature/ClientTaskCreateApiTest.php tests/Feature/ClientTaskStatusMutationApiTest.php tests/Feature/ClientProjectTasksApiTest.php`

Observed results:

- full suite: 61 tests passed, 269 assertions passed
- focused workflow slice: 27 tests passed, 166 assertions passed

Live runtime validation also passed for:

- authenticated workspace project reads on the canonical runtime
- final active task completion driving `active -> completed`
- idempotent replay returning the stored success response without duplicate workflow events
- completed task reopen driving `completed -> active`
- persisted event counts remaining singular for each workflow transition

## Workflow Validation Procedure

Use this when validating the workflow layer outside PHPUnit:

1. Start the Docker runtime with `docker compose up --build -d`.
2. Run `php artisan migrate --force`.
3. Run `php artisan db:seed --class=ClientPortalReadModelSeeder --force`.
4. Create a session for `browser-user-123` through `App\Services\Session\SessionService`.
5. Verify `GET /api/v1/client/projects` returns the owned Atlas project.
6. Patch `project-bc1e1ebe-atlas-task-scope` to `done` with `expectedVersion=1` and a fresh idempotency key.
7. Replay the same PATCH request with the same idempotency key and verify the response is unchanged.
8. Patch `project-bc1e1ebe-atlas-task-sso` to `todo` with a new idempotency key and verify the project reopens.
9. Inspect `client_mutation_events` or `ClientMutationEvent` counts to confirm the workflow events were persisted once.

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

## Supporting Docs

- `docs/backend-implementation-plan.md`
- `docs/read-model-persistence-foundation.md`
- `docs/workflow-coordination-foundation.md`
- `docs/postgresql-portability-audit.md`
