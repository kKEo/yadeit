# Deploy on shared hosting (Zenbox)

How to run Yadeit on ordinary PHP hosting, without Docker and without root,
using [Zenbox](https://zenbox.pl) as the worked example. The steps carry over
to other hosts with SSH, cron and a MySQL-compatible database. Allow about an
hour the first time.

Shared hosting runs a smaller Yadeit than the [Docker setup](docker.md):
there is no realtime, the queue is worked by cron instead of a resident
worker, and backups are your cron job. Everything people do in the web app
works the same.

> **Note.** The Zenbox details on this page come from Zenbox's public help
> pages as of November 2026. Hosting offers change; where this page says
> "check", check in your account before you rely on it.

## 1. Check that your account can run it

Do this before anything else. The first row decides whether Yadeit can run
on the account at all.

| Need | Why | On Zenbox |
|---|---|---|
| **MariaDB 10.11+ or MySQL 8.0+** | Yadeit's tables use JSON columns, which MySQL 5.6 does not have; the migrations fail on it | Zenbox's help pages name only **MySQL 5.5 and 5.6**. **Ask Zenbox support** whether your server offers MariaDB or MySQL 8. If not, see [No suitable database](#no-suitable-database). |
| PHP 8.3 or newer (8.5 recommended) | `backend/composer.json` requires `^8.3`; the Docker image uses 8.5 | 8.3, 8.4 and 8.5 are available per domain |
| SSH | migrations, `artisan` commands | on all plans |
| Cron every minute | the scheduler and the queue | Panel Klienta → Zaawansowane → Zadania cykliczne (cron) |
| PHP extensions `pdo_mysql`, `intl`, `zip`, `bcmath`, `mbstring`, `openssl` | the application | check with `php85 -m` (below) |
| A process may run for about a minute | each cron run of the queue | 300 s on the smallest plan, more on larger ones |
| Outbound HTTPS | built-in AI, webhooks, Stripe | check with `curl` (below) |
| SMTP | sign-up confirmation emails | included: `smtp.zenbox.pl`, port 587, STARTTLS |
| HTTPS certificate | secure cookies, OAuth | free Let's Encrypt |

Node.js is **not** needed on the server: you build the web app on your own
machine.

Check the PHP side over SSH (log-in details are in Panel Klienta):

```bash
/usr/local/bin/php85 -v
/usr/local/bin/php85 -m | grep -Ei 'pdo_mysql|intl|zip|bcmath|mbstring|openssl'
curl -sI https://api.anthropic.com | head -1      # any HTTP answer means outbound HTTPS works
ls /usr/local/bin | grep -i composer               # Zenbox's own guide uses composer83
```

On Zenbox the `php` command in SSH may be an older version than the one your
domain uses. Always call `/usr/local/bin/php85` (or the version you chose) by
its full path, in SSH and in cron, and use the **same** version for the
domain. The rest of this page writes `php85`; substitute your version.

### What you give up

| Feature | On shared hosting |
|---|---|
| Realtime updates (Reverb) | **Not available**: it needs a resident WebSocket server. Browsers refresh on navigation instead. |
| Queue worker | Runs from cron once a minute, so emails and webhooks leave up to a minute later. |
| Automatic backups with health check | Your own cron job, described below. |
| Traffic lab | Do not run it on shared hosting. |
| Large announcements | Limited by the host's sending quota (on Zenbox 1,000 to 3,000 SMTP messages per mailbox a day, depending on the plan). |

## 2. Prepare the account

In **Panel Klienta**:

1. Add the domain (or a subdomain such as `yadeit.example.pl`) and point its
   DNS A record at the hosting.
2. **Zaawansowane → Zmiana wersji PHP**: choose PHP 8.5 (or 8.4) for the
   domain. On the same screen, raise `upload_max_filesize` to `25M`,
   `post_max_size` to `30M` and `memory_limit` to at least `256M`. On Zenbox
   these limits apply to every domain on that PHP version in the account.
3. **Domeny → Konfiguracja domen**: tick **Bezpieczny SSL**. Then
   **Zaawansowane → Certyfikat SSL**: choose the domain and **Darmowy
   certyfikat od Let's Encrypt**.
4. Create a **database** and a database user (MariaDB or MySQL 8, see step 1).
   Note the host, name, user and password.
5. Create a **mailbox** for outgoing mail, for example `yadeit@example.pl`.

## 3. Build a release on your machine

You need PHP, Composer and Node.js 24 locally. Build with the same PHP minor
version as the server's, or Composer may pick packages the server cannot run.

```bash
git clone https://github.com/kKEo/yadeit.git && cd yadeit

# The web app. Who runs the installation is built in.
cd frontend
npm ci
VITE_OPERATOR_NAME="Example Ltd" VITE_OPERATOR_EMAIL=privacy@example.pl npm run build
cd ..

# The backend with its production dependencies
cd backend
composer install --no-dev --optimize-autoloader
cd ..

# One folder to upload: the backend, with the web app inside its public folder
rm -rf release && mkdir -p release
# Leave out local secrets, local data and caches built with local paths
rsync -a --exclude .env --exclude /storage --exclude '/bootstrap/cache/*.php' \
      --exclude tests --exclude node_modules backend/ release/yadeit/
cp -R frontend/dist/. release/yadeit/public/
```

Then replace `release/yadeit/public/.htaccess` with the version below. It
does on Apache and LiteSpeed what `docker/prod/Caddyfile` does in Docker:
backend addresses go to Laravel, existing files are served as they are, and
every other path gets the web app.

```apacheconf
# Yadeit on shared hosting: one address for the web app and the API.
DirectoryIndex index.html

<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews -Indexes
    </IfModule>
    RewriteEngine On

    # Pass the bearer token and the XSRF header through to PHP
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
    RewriteCond %{HTTP:x-xsrf-token} .
    RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]

    # Backend addresses (the same list as docker/prod/Caddyfile)
    RewriteRule ^(api/|oauth/|mcp$|\.well-known/oauth-|sanctum/|stripe/|llms\.txt$|docs/|up$|health$) index.php [L]

    # Files that exist: the web app's assets, openapi.json, favicon
    RewriteCond %{REQUEST_FILENAME} -f
    RewriteRule ^ - [L]

    # Everything else is a page of the web app
    RewriteRule ^ index.html [L]
</IfModule>

<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>
```

Optionally, let browsers keep the built files for good (their names carry a
hash) with a second file, `release/yadeit/public/assets/.htaccess`:

```apacheconf
<IfModule mod_headers.c>
    Header set Cache-Control "public, max-age=31536000, immutable"
</IfModule>
```

Keep this file outside the repository (or in your own deployment notes): the
next release's `backend/public/.htaccess` would overwrite it.

## 4. Upload and switch the domain to it

On Zenbox each domain lives in `~/domains/<domain>/`, and the web server
serves its `public_html`. Put Yadeit next to it and make `public_html` a link
to Yadeit's `public` folder, as Zenbox's own Laravel guide does.

```bash
# From your machine (rsync over SSH; an SFTP client works too)
rsync -az release/yadeit/ <user>@<host>:domains/yadeit.example.pl/yadeit/
```

```bash
# On the server
cd ~/domains/yadeit.example.pl
mv public_html public_html.old            # or delete it if it is empty
ln -s yadeit/public public_html
mkdir -p yadeit/storage/app/private yadeit/storage/framework/{cache/data,sessions,views} yadeit/storage/logs yadeit/bootstrap/cache
```

## 5. Configure

Create `~/domains/yadeit.example.pl/yadeit/.env`:

```bash
APP_NAME=Yadeit
APP_ENV=production
APP_DEBUG=false
APP_KEY=
APP_URL=https://yadeit.example.pl
FRONTEND_URL=https://yadeit.example.pl
SANCTUM_STATEFUL_DOMAINS=yadeit.example.pl
SESSION_SECURE_COOKIE=true

# MariaDB: mariadb.  MySQL 8: mysql (not covered by the test suite; see below).
DB_CONNECTION=mariadb
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=<user>_yadeit
DB_USERNAME=<user>_yadeit
DB_PASSWORD=<password>

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
BROADCAST_CONNECTION=null
LOG_CHANNEL=daily
LOG_LEVEL=warning

MAIL_MAILER=smtp
MAIL_HOST=smtp.zenbox.pl
MAIL_PORT=587
MAIL_USERNAME=yadeit@example.pl
MAIL_PASSWORD=<mailbox password>
MAIL_FROM_ADDRESS=yadeit@example.pl
MAIL_FROM_NAME=Yadeit

HEALTH_TOKEN=<openssl rand -hex 24>
BACKUP_HEARTBEAT=/home/<user>/backups/yadeit/last-success

# Optional
ANTHROPIC_API_KEY=
```

Every variable is described in [Environment variables](../config/environment.md).
Then, on the server:

```bash
cd ~/domains/yadeit.example.pl/yadeit
chmod 600 .env
php85 artisan key:generate --force       # writes APP_KEY into .env
php85 artisan migrate --force
php85 artisan passport:keys              # signing keys for apps connecting over OAuth
php85 artisan config:cache && php85 artisan route:cache && php85 artisan event:cache && php85 artisan view:cache
```

`LOG_CHANNEL=daily` writes `storage/logs/laravel-<date>.log` and keeps 14
days (`LOG_DAILY_DAYS`).

> **Warning.** Copy `.env` to a safe place outside the server. Without its
> `APP_KEY`, workspace AI keys and webhook secrets in the database cannot be
> read.

## 6. Cron jobs

In **Panel Klienta → Zaawansowane → Zadania cykliczne (cron)**, add three
jobs. Leave the time fields at their defaults ("every minute") for the first
two.

```bash
# 1. The scheduler: cycles, announcements, clean-up, the health heartbeat (every minute)
/usr/local/bin/php85 /home/<user>/domains/yadeit.example.pl/yadeit/artisan schedule:run >/dev/null 2>&1

# 2. The queue: emails and webhooks. Works for at most 55 s, so runs never overlap (every minute)
/usr/local/bin/php85 /home/<user>/domains/yadeit.example.pl/yadeit/artisan queue:work --stop-when-empty --max-time=55 --tries=3 --backoff=10 >/dev/null 2>&1

# 3. The backup (once a day, at night)
/bin/sh /home/<user>/yadeit-backup.sh >/dev/null 2>&1
```

The queue line replaces the resident worker of the Docker setup. It stays well
inside Zenbox's per-process time limit (300 s on the smallest plan). The
health report's queue check fails only if a job waits more than 10 minutes,
so it notices when this cron job stops.

## 7. Backups

Create `~/yadeit-backup.sh`. It does what `docker/prod/backup.sh` does: a
database dump and an archive of the uploads and OAuth keys, kept 14 days, and
a heartbeat file the health report watches.

```bash
#!/bin/sh
set -eu
app=/home/<user>/domains/yadeit.example.pl/yadeit
dir=/home/<user>/backups/yadeit
keep=14
stamp=$(date -u +%Y%m%d-%H%M%S)
mkdir -p "$dir"

# The database. Credentials come from a protected option file, not the command line.
mysqldump --defaults-extra-file=/home/<user>/.yadeit-db.cnf --single-transaction --routines \
  <user>_yadeit | gzip > "$dir/db-$stamp.sql.gz.part"
mv "$dir/db-$stamp.sql.gz.part" "$dir/db-$stamp.sql.gz"

# Uploads and OAuth keys, without caches and logs
tar -czf "$dir/files-$stamp.tar.gz.part" --exclude=./framework --exclude=./logs -C "$app/storage" .
mv "$dir/files-$stamp.tar.gz.part" "$dir/files-$stamp.tar.gz"

find "$dir" -maxdepth 1 \( -name 'db-*.sql.gz' -o -name 'files-*.tar.gz' \) -mtime "+$keep" -delete
touch "$dir/last-success"
```

```ini
# /home/<user>/.yadeit-db.cnf   (chmod 600)
[client]
user=<user>_yadeit
password=<password>
host=localhost
```

Run it once by hand and check that the health report's backup check turns
`ok`. If `mysqldump` is not on the path, Zenbox's help has an article with its
exact location. Download the backup folder to another place regularly: the
backups are on the same account as the data.

To restore:

```bash
gunzip -c ~/backups/yadeit/db-<time>.sql.gz | mysql --defaults-extra-file=~/.yadeit-db.cnf <user>_yadeit
tar -xzf ~/backups/yadeit/files-<time>.tar.gz -C ~/domains/yadeit.example.pl/yadeit/storage
```

## 8. First operator and checks

Open `https://yadeit.example.pl`, sign up, confirm your address from the
email, then:

```bash
php85 ~/domains/yadeit.example.pl/yadeit/artisan operator:grant you@example.pl
php85 ~/domains/yadeit.example.pl/yadeit/artisan app:health
curl -s -H "Authorization: Bearer <HEALTH_TOKEN>" https://yadeit.example.pl/health
```

The scheduler check turns `ok` within two minutes of the cron jobs being in
place. Then go through the checklist in
[Deploy with Docker Compose](docker.md#5-check-before-opening-it-to-the-public),
and point an uptime monitor at `/health`.

## Updating

Build a new release on your machine as in step 3 (with your `.htaccess`),
then:

```bash
# On the server: take a backup, then go into maintenance mode
/bin/sh ~/yadeit-backup.sh
php85 ~/domains/yadeit.example.pl/yadeit/artisan down

# From your machine: upload, keeping .env and storage
rsync -az --delete --exclude .env --exclude 'storage/' release/yadeit/ <user>@<host>:domains/yadeit.example.pl/yadeit/

# On the server
cd ~/domains/yadeit.example.pl/yadeit
php85 artisan migrate --force
php85 artisan config:cache && php85 artisan route:cache && php85 artisan event:cache && php85 artisan view:cache
php85 artisan up
```

The next cron run of the queue picks up the new code by itself.

## No suitable database

If the account offers only MySQL 5.5 or 5.6, Yadeit cannot run on its
database. Options, best first:

1. Ask the host to move the account to a server with MariaDB 10.11+ or MySQL 8.
2. Use a managed database elsewhere (PostgreSQL or MariaDB), if the host
   allows outgoing connections to it. Set `DB_CONNECTION`, `DB_HOST` and
   `DB_PORT` accordingly and keep latency low: every request makes several
   queries. Backups then belong to the database provider.
3. Use a small VPS with the [Docker setup](docker.md) instead. It costs about
   the same as a larger shared hosting plan and has none of the limits above.

## About MySQL 8

Yadeit's test suite runs on PostgreSQL and MariaDB, not on MySQL. MySQL 8 is
very close to MariaDB for what Yadeit uses (`DB_CONNECTION=mysql`), but it is
not covered by CI. If you run on it, run the backend tests against a MySQL 8
database once before going live:

```bash
cd backend
DB_CONNECTION=mysql DB_PORT=3306 DB_DATABASE=yadeit_test php artisan test
```

## Troubleshooting on shared hosting

| Symptom | Cause | Fix |
|---|---|---|
| Migration fails on a `json` column | MySQL 5.6 | See [No suitable database](#no-suitable-database). |
| `500` on every page right after upload | PHP version of the domain is older than 8.3, or `storage/` is not writable | Set the version in Panel Klienta; `chmod -R u+rwX storage bootstrap/cache`. |
| `/` shows `{"name":"Yadeit","api":…}` | `index.html` is missing from `public/` or `DirectoryIndex` is not set | Copy `frontend/dist` into `public/`; use the `.htaccess` above. |
| Reloading a page such as `/terms` gives 404 | The fallback to `index.html` is not active | Check the `.htaccess`, and that `mod_rewrite` is on (it is on Zenbox). |
| Agents get 401 with a valid token | The `Authorization` header does not reach PHP | Keep the two `HTTP_AUTHORIZATION` lines in `.htaccess`. |
| `scheduler` health check failing | Cron job 1 missing or uses a PHP version without the extensions | Check the cron entry and the full path to `php85`. |
| Emails arrive late | They wait for the next queue cron run | Expected: up to a minute. |
| Emails stop in the afternoon | The mailbox's daily SMTP quota is used up | A larger plan, or an external SMTP service (`MAIL_HOST`). |
| Uploads over 2 MB fail | PHP limits not raised | Panel Klienta → Zmiana wersji PHP: `upload_max_filesize` and `post_max_size`. |

Sources for the Zenbox details: the
[hosting offer](https://zenbox.pl/hosting), and Zenbox's help articles on
[Laravel](https://zenbox.pl/pomoc/artykul/instalacja-laravel-na-serwerze-zenbox/),
[PHP versions](https://zenbox.pl/pomoc/artykul/jak-zmienic-wersje-php-dla-danej-domeny/),
[PHP from the command line](https://zenbox.pl/pomoc/artykul/wersja-php-poprzez-cli/),
[cron](https://zenbox.pl/pomoc/artykul/jak-dodac-crona-z-cli/),
[PHP limits](https://zenbox.pl/pomoc/artykul/zmiana-wartosci-widocznych-w-phpinfo/),
[MySQL versions](https://zenbox.pl/pomoc/artykul/obsluga-mysql-56/),
[mail settings](https://zenbox.pl/pomoc/artykul/konfiguracja-programow-pocztowych-w-zenbox/) and
[Let's Encrypt](https://zenbox.pl/pomoc/artykul/jak-moge-wlaczyc-certyfikat-lets-encrypt-dla-mojej-domeny/).
