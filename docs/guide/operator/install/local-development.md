# Local development

Two ways to run Yadeit on your machine: the whole production stack in Docker
with one command, or the backend and frontend on the host with live reload.

## The whole stack: `make app-up`

```bash
make help                                # every command
make app-up                              # build and start; http://localhost:8080
make app-operator EMAIL=you@example.com  # after signing up
make app-logs                            # email is only logged: the confirmation link is here
make app-down                            # stop, keep the data
make app-reset                           # stop and DELETE database, uploads and backups
```

The first `make app-up` writes `.env.docker` with fresh secrets and
`MAIL_MAILER=log` (not committed). Delete it to start over, for example with
another `HTTP_PORT`. The stack runs as a separate Compose project
(`yadeit-local`), so it does not touch the development database.

## Backend and frontend on the host

Requirements: PHP 8.3+ with `pdo_pgsql` or `pdo_mysql`, Composer, Node.js 24.

```bash
make db-up                      # PostgreSQL with the yadeit and yadeit_test databases (DB=mariadb for MariaDB)

cd backend
composer setup                  # install, create .env, generate key, migrate
php artisan serve               # http://localhost:8000
php artisan queue:work          # emails and realtime signals
php artisan schedule:work       # timed jobs
php artisan reverb:start        # realtime on port 8080 (optional)

cd ../frontend
npm install
npm run dev                     # http://localhost:5173, proxies /api, /oauth, /.well-known and /mcp to :8000
```

`backend/.env.example` is set up for this: `APP_URL=http://localhost:8000`,
`FRONTEND_URL=http://localhost:5173`, `SANCTUM_STATEFUL_DOMAINS=localhost:5173`,
mail to the log. For realtime, copy the `REVERB_*` values into
`frontend/.env.local` as `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`,
`VITE_REVERB_PORT` and `VITE_REVERB_SCHEME`.

For webhooks to a local receiver, set `WEBHOOKS_ALLOW_PRIVATE_TARGETS=true`.
Never in production.

## Checks before a pull request

```bash
cd backend
php artisan test                # PostgreSQL by default
DB_CONNECTION=mariadb DB_PORT=3306 php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse

cd ../frontend
npm run build                   # type check and build
npm run test
```

CI (`.github/workflows/ci.yml`) runs the same, plus the simulator's checks,
a Traffic lab smoke run on `master`, and the documentation build.

## Regenerating the API description

```bash
cd backend
php artisan scramble:export     # writes public/openapi.json; needs a migrated database
```
