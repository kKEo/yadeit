# Install without Docker

The same parts as the Docker setup, assembled by hand. Use this when your
platform does not run containers, or as a reference for what the image does.
`docker/prod/Caddyfile` and `docker/prod/entrypoint.sh` are the exact routing
and start-up steps.

## Requirements

- PHP 8.5 with the `pdo_pgsql` (or `pdo_mysql`), `intl`, `zip`, `bcmath`, `pcntl` and `opcache` extensions, and Composer 2.
- Node.js 24 to build the web app (not needed at runtime).
- PostgreSQL 15+ or MariaDB 10.11+.
- A web server that runs PHP: FrankenPHP, Caddy or nginx with PHP-FPM.
- A process manager (systemd, supervisord) for the worker and, if used, Reverb.

Use `docker/prod/php.ini` as the PHP settings: 25 MB uploads, 30 MB requests,
256 MB memory, OPcache without timestamp checks (reload PHP after each release).

## Build a release

```bash
git pull
cd backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan event:cache && php artisan view:cache

cd ../frontend
npm ci
VITE_OPERATOR_NAME="Example Ltd" VITE_OPERATOR_EMAIL=privacy@example.com npm run build   # writes frontend/dist
```

The first time, also run `php artisan key:generate` and `php artisan passport:keys`
in `backend/`, and make `backend/storage` and `backend/bootstrap/cache`
writable by the PHP user.

## Routing

One host serves both halves. Send these paths to `backend/public/index.php`:

```
/api/*  /oauth/*  /mcp  /.well-known/oauth-*  /sanctum/*  /stripe/*
/llms.txt  /docs/*  /openapi.json  /up  /health  /storage/*
```

Serve `frontend/dist` for everything else, falling back to `index.html` for
unknown paths (the web app routes in the browser). Files under `/assets/` have
a hash in their name and can be cached for a year. When realtime is on,
proxy `/app/*` and `/apps/*` to Reverb.

## Processes

```ini
# /etc/systemd/system/yadeit-worker.service
[Service]
User=www-data
WorkingDirectory=/srv/yadeit/backend
ExecStart=/usr/bin/php artisan queue:work --tries=3 --backoff=10 --max-time=3600 --sleep=1
Restart=always
```

```bash
# crontab of the PHP user: the scheduler
* * * * * cd /srv/yadeit/backend && php artisan schedule:run >> /dev/null 2>&1
```

Optional: a third unit for `php artisan reverb:start --host=0.0.0.0 --port=8080`.

After each release, restart the worker (`php artisan queue:restart` makes
running workers exit after their current job) and reload PHP.

## Backups

`docker/prod/backup.sh` is portable shell: run it from cron with `once`,
with `PGHOST`, `PGUSER`, `PGPASSWORD` and `PGDATABASE` set, and with
`/backups` and `/storage` pointing at your folders (edit the two paths). Set
`BACKUP_HEARTBEAT` to the `last-success` file so the health report watches it.

## MariaDB

Yadeit runs on MariaDB 11.8 as well; CI runs the full test suite against it.
Set:

```bash
DB_CONNECTION=mariadb
DB_PORT=3306
```

With Docker, replace the `db` service in `compose.prod.yaml` with a
`mariadb:11.8` image, and in `docker/prod/backup.sh` replace `pg_dump` and
`pg_restore` with `mariadb-dump` and `mariadb`, and the `postgres` image of
the `backup` service with the MariaDB one.
