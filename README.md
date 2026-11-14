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

## API conventions

- All endpoints live under `/api/v1`.
- The web app signs in with a cookie session; scripts and agents send `Authorization: Bearer <token>`.
- Errors always have the same shape:

```json
{ "error": { "code": "validation_failed", "message": "...", "details": { "fields": { "title": ["..."] } } } }
```
