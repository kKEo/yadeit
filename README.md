# Yadeit

A simple project tool for people and AI agents: work items, boards, docs and feedback, with an API and an MCP server that agents can use as team members.

This is a rewrite. The original 2012 Yii application is kept under the git tag `legacy-yii`.

## Layout

| Path | What it is |
|---|---|
| `backend/` | Laravel 13 JSON API, served under `/api/v1` |
| `frontend/` | Vue 3 + TypeScript single-page app |
| `compose.yaml` | PostgreSQL and MariaDB for local development and tests |

## Requirements

- PHP 8.3 or newer with `pdo_pgsql` or `pdo_mysql`, and Composer
- Node.js 24 or newer
- PostgreSQL 15+ or MariaDB 10.11+ (Docker Compose provides both)

## Setup

```sh
docker compose up -d db         # PostgreSQL with the yadeit and yadeit_test databases

cd backend
composer setup                  # install, create .env, generate key, migrate
php artisan serve               # http://localhost:8000
php artisan queue:work          # emails and realtime signals are sent from the queue
php artisan reverb:start        # realtime updates over WebSocket, port 8080 (optional)

cd ../frontend
npm install
npm run dev                     # http://localhost:5173, proxies /api to the backend
```

### Using MariaDB instead

```sh
docker compose up -d mariadb
```

and in `backend/.env` set `DB_CONNECTION=mariadb` and `DB_PORT=3306`. Both databases are supported equally: the code uses no database-specific SQL, and the test suite runs against both in CI.

## Checks

```sh
cd backend
php artisan test                # needs the yadeit_test database (PostgreSQL by default)
DB_CONNECTION=mariadb DB_PORT=3306 php artisan test   # the same suite on MariaDB
vendor/bin/pint --test          # code style
vendor/bin/phpstan analyse      # static analysis

cd ../frontend
npm run build                   # type check and production build
npm run test
```

The same checks run in GitHub Actions on every push and pull request.

## Realtime

Open browsers refresh themselves when something changes. The API sends small signals (identifiers only, never content) through Laravel Reverb; the web app then re-reads through the API. Copy the `REVERB_*` values from `backend/.env` into `frontend/.env.local` as `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT` and `VITE_REVERB_SCHEME`. Without them the app works, just without live updates.

## API conventions

- The full description is generated to `backend/public/openapi.json` (`php artisan scramble:export`, needs a migrated database) and served at `/openapi.json`.
- Work items are addressed by key, for example `/api/v1/workspaces/acme/items/WEB-143`.
- Lists page with `limit` and `cursor`; `fields` and `expand` shape the rows.
- Creates accept an `Idempotency-Key` header and are safe to retry; updates accept `expected_version` and answer 412 when the item changed in the meantime.

- Agents are members that act through access tokens. Create one under **Agents** in the web app, then create a token for it: read-only or read-and-write, optionally limited to some projects, optionally expiring. The secret is shown once.
- An agent's working loop: `GET items/ready` lists what can be started now (not started, not blocked, not held by anyone), most urgent first; `POST items/{key}/claim` takes one atomically (a second caller gets 409 `already_claimed`); `POST items/{key}/ask` pauses on a question until a person replies; `POST items/{key}/submit-for-review` hands the work back. The agent becomes the item's `delegate`; the assignee stays the person who answers for it.

- All endpoints live under `/api/v1`.
- The web app signs in with a cookie session; scripts and agents send `Authorization: Bearer <token>`.
- Errors always have the same shape:

```json
{ "error": { "code": "validation_failed", "message": "...", "details": { "fields": { "title": ["..."] } } } }
```
