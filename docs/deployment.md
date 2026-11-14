# Running Yadeit in production

This is the supported way to run Yadeit for real use: one Docker image for the
whole application, PostgreSQL, a queue worker, a scheduler and a backup job,
started together with Docker Compose.

## What runs

| Service | What it does |
|---|---|
| `app` | The web server. Serves the web app and the API from one address, migrates the database when it starts. |
| `worker` | Sends notifications, emails and webhooks from the queue. |
| `scheduler` | Runs what happens on a clock: cycles, announcement emails, clean-up, and the heartbeat the health report looks for. |
| `db` | PostgreSQL 17. |
| `backup` | Dumps the database and archives the uploads on a schedule. |
| `reverb` | Realtime updates in the browser. Optional. |

Everything is reached through one address. The web app and the API must share
it: sign-in cookies and the OAuth flow for connected apps depend on that.

## Before you start

You need a machine with Docker and Docker Compose, a domain name pointing at
it, and an SMTP account for outgoing email. People confirm their email address
when they sign up, so without working email nobody can get in.

## First start

1. Copy the settings file and fill it in. Every line marked `REQUIRED` needs a value.

   ```sh
   cp .env.production.example .env.production
   ```

2. Build the image and generate the application key. Put the key into `APP_KEY`.

   ```sh
   docker compose --env-file .env.production -f compose.prod.yaml build
   docker run --rm --entrypoint php yadeit artisan key:generate --show
   ```

3. Start everything.

   ```sh
   docker compose --env-file .env.production -f compose.prod.yaml up -d
   ```

4. Open the address, create your account, and make yourself an operator.

   ```sh
   docker compose --env-file .env.production -f compose.prod.yaml exec app php artisan operator:grant you@example.com
   ```

   The Operator area then appears in your account menu. Its first screen shows the
   health of the installation.

The settings that most often go wrong:

- `APP_URL` and `FRONTEND_URL` are the same public address, with `https://`.
- `SANCTUM_STATEFUL_DOMAINS` is the host part of that address. If it is wrong, sign-in appears to work and every following request is refused.
- `SESSION_SECURE_COOKIE=true` needs HTTPS. Over plain HTTP the browser drops the cookie.
- `OPERATOR_NAME` and `OPERATOR_EMAIL` are built into the web app. Rebuild the image after changing them.

## HTTPS

Choose one of two ways.

**Behind your own proxy or load balancer (the default).** Leave `SERVER_NAME=:8080`.
The container speaks plain HTTP on the port given by `HTTP_PORT`; your proxy
terminates HTTPS and forwards to it. Set `TRUSTED_PROXIES` to the proxy's
address (or `*` when the container can only be reached through it), otherwise
every request appears to come from the proxy and rate limits and the audit log
are wrong.

**Let the container do it.** Set `SERVER_NAME` to your domain and publish ports
80 and 443 instead of `HTTP_PORT` (see the commented lines in
`compose.prod.yaml`). The server obtains and renews a certificate by itself.
Leave `TRUSTED_PROXIES` empty.

## Updating

```sh
git pull
docker compose --env-file .env.production -f compose.prod.yaml up -d --build
```

The new `app` container migrates the database before it serves requests; the
worker and scheduler wait for it. Take a backup first (below) when the release
notes mention a migration.

## Backups

The `backup` service writes to the `backups` volume every `BACKUP_INTERVAL_HOURS`
(24 by default) and keeps `BACKUP_KEEP_DAYS` (14):

- `db-<time>.dump`: the database, in `pg_dump` custom format
- `files-<time>.tar.gz`: uploaded files and the OAuth signing keys

**These backups are on the same machine as the data.** Copy the volume
somewhere else on a schedule (object storage, another server). A backup that
dies with the machine is not a backup.

```sh
# A backup right now
docker compose --env-file .env.production -f compose.prod.yaml exec backup /bin/sh /backup.sh once

# What is there
docker compose --env-file .env.production -f compose.prod.yaml exec backup /bin/sh /backup.sh list
```

### Restoring

Restoring replaces everything in the database with the contents of the dump.

```sh
docker compose --env-file .env.production -f compose.prod.yaml exec backup \
  /bin/sh /backup.sh restore /backups/db-20260101-000000.dump /backups/files-20260101-000000.tar.gz
docker compose --env-file .env.production -f compose.prod.yaml restart app worker scheduler
```

Practise this once before you need it. A restore on a spare machine is the only
proof that the backups work.

`.env.production` is not in the backup. Keep a copy of it somewhere safe:
without `APP_KEY`, encrypted values (workspace AI keys, webhook secrets) cannot
be read again.

## Monitoring

**The health report.** It checks the database, the queue worker, failed jobs,
the scheduler, the uploads folder, failed announcement emails and the age of
the last backup. Each check is `ok`, `warning` (look at it soon) or `failing`
(people are affected now).

- Operators see it in the Operator area.
- `GET /health` with `Authorization: Bearer <HEALTH_TOKEN>` answers 200 while nothing is failing and 503 otherwise. Point an uptime monitor at it. Without `HEALTH_TOKEN` the address does not exist.
- `php artisan app:health` prints it in a shell and exits with an error when something is failing.

```sh
curl -H "Authorization: Bearer $HEALTH_TOKEN" https://yadeit.example.com/health
```

**Liveness.** `GET /up` answers 200 when the application can start at all.
Docker uses it for the `app` container's health status.

**Logs.** Everything is written to the containers' output:

```sh
docker compose --env-file .env.production -f compose.prod.yaml logs -f app worker scheduler
```

Ship these to wherever you keep logs. For error tracking with alerts, add a
service such as Sentry; none is built in.

**Failed jobs.** `php artisan queue:failed` lists them, `queue:retry all` runs
them again.

## Optional features

| Feature | What to set |
|---|---|
| Built-in AI | `ANTHROPIC_API_KEY`. Workspaces switch AI on themselves and can bring their own key. |
| Paid plans | `PLAN_LIMITS=true` and the four `STRIPE_` settings. Point a Stripe webhook at `/stripe/webhook` for the `customer.subscription.*` events. Without `STRIPE_WEBHOOK_SECRET` that address refuses everything. |
| Realtime updates | The `REVERB_` and `VITE_REVERB_` settings, `BROADCAST_CONNECTION=reverb`, and start with `--profile realtime`. Rebuild the image: the `VITE_` values are built into the web app. |
| Apps connecting over OAuth | Nothing. Signing keys are created on first start and kept in the `storage` volume. |

## Before opening it to the public

- Have the terms and the privacy policy reviewed. The texts shipped are a template, not legal advice.
- Send yourself a password reset and an invitation to check that email arrives and the links point at your address.
- Confirm the health report is all `ok`, including backups.
- Restore a backup on another machine.
- Change the database password and `APP_KEY` from anything used during a trial.

## MariaDB instead of PostgreSQL

Yadeit runs on MariaDB 11.8 as well, and the test suite covers both. The
production Compose file and the backup script are written for PostgreSQL. To
use MariaDB, replace the `db` service, set `DB_CONNECTION=mariadb` and
`DB_PORT=3306`, and replace `pg_dump`/`pg_restore` in `docker/prod/backup.sh`
with `mariadb-dump` and `mariadb`.

## Without Docker

The same parts, by hand: PHP 8.5 with the `pdo_pgsql` (or `pdo_mysql`), `intl`,
`zip`, `bcmath` and `pcntl` extensions; a web server that sends `/api`, `/oauth`,
`/mcp`, `/.well-known/oauth-*`, `/sanctum`, `/stripe`, `/llms.txt`, `/docs`,
`/openapi.json`, `/up`, `/health` and `/storage` to `backend/public/index.php` and
serves `frontend/dist` for everything else, falling back to `index.html`; a
process manager running `php artisan queue:work` and `php artisan schedule:work`;
and on each release `composer install --no-dev`, `npm ci && npm run build`,
`php artisan migrate --force`, `php artisan config:cache route:cache event:cache view:cache`.
`docker/prod/Caddyfile` and `docker/prod/entrypoint.sh` show the exact routing and
start-up steps.
