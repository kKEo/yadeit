# Deploy with Docker Compose

The supported way to run Yadeit for real use: one image, started with
PostgreSQL, a queue worker, a scheduler and a backup job by
`compose.prod.yaml`. From an empty server to a signed-in operator takes about
twenty minutes, most of it waiting for the image to build.

## Before you start

- A Linux machine with Docker Engine and the Compose plugin. The image builds
  the web app with Node, so give the build some memory; for sizing under real
  load, measure with the [Traffic lab](https://github.com/kKEo/yadeit/blob/master/docs/traffic-lab.md)
  on a copy of the installation.
- A domain name whose DNS points at the machine.
- An SMTP account for outgoing email. People confirm their address by email
  when they sign up: **without working email nobody can get in**.
- A clone of the repository on the machine.

## 1. Fill in the settings

```bash
cp .env.production.example .env.production
```

Every line marked `REQUIRED` needs a value. The essentials:

```bash
APP_URL=https://yadeit.example.com
FRONTEND_URL=https://yadeit.example.com      # the same address
SANCTUM_STATEFUL_DOMAINS=yadeit.example.com  # host part only (with port if not 80/443)
SESSION_SECURE_COOKIE=true
DB_PASSWORD=<a long random value>            # openssl rand -hex 24
MAIL_HOST=smtp.example.com
MAIL_USERNAME=…
MAIL_PASSWORD=…
MAIL_FROM_ADDRESS=hello@yadeit.example.com
HEALTH_TOKEN=<a long random value>
OPERATOR_NAME="Example Ltd"                  # shown on the terms and privacy pages
OPERATOR_EMAIL=privacy@example.com
```

Every variable is described in [Environment variables](../config/environment.md).

## 2. Build the image and create the application key

```bash
docker compose --env-file .env.production -f compose.prod.yaml build
docker run --rm --entrypoint php yadeit artisan key:generate --show
```

Put the printed `base64:…` value into `APP_KEY`. The containers refuse to
start without it.

> **Warning.** `APP_KEY` encrypts workspace AI keys and webhook secrets. Keep a
> copy of `.env.production` somewhere safe outside the server: it is **not**
> in the backups.

## 3. Start

```bash
docker compose --env-file .env.production -f compose.prod.yaml up -d
```

On start the `app` container (`docker/prod/entrypoint.sh`):

1. checks that `APP_KEY` is set;
2. creates the storage folders;
3. runs `php artisan migrate --force`;
4. creates the OAuth signing keys in `storage/` if none exist and `PASSPORT_PRIVATE_KEY` is unset;
5. caches config, routes, events and views;
6. starts FrankenPHP.

`worker` and `scheduler` wait until `app` reports healthy (`GET /up`), so they
never run against an unmigrated database.

From here on the guide uses this alias:

```bash
alias dc='docker compose --env-file .env.production -f compose.prod.yaml'
dc ps        # all services "running", app "healthy"
```

## 4. Create the first operator

Open the address, sign up, confirm your email, then:

```bash
dc exec app php artisan operator:grant you@example.com
```

**Operator area** appears in your account menu. Its first screen is the
health report: every check should be `ok` within a few minutes. The backup
check turns `ok` after the first backup, which runs right away.

## 5. Check before opening it to the public

- [ ] Send yourself a password reset and an invitation: email arrives and the links use your address.
- [ ] `curl -H "Authorization: Bearer $HEALTH_TOKEN" https://yadeit.example.com/health` answers 200.
- [ ] Point an uptime monitor at `/health` ([The health report](../monitoring/health.md)).
- [ ] Copy the `backups` volume off the machine on a schedule ([Backup and restore](../operations/backup.md)).
- [ ] Restore one backup on another machine.
- [ ] Have the terms and privacy policy reviewed: the shipped texts are a template, not legal advice.
- [ ] If you used the installation for a trial, change `DB_PASSWORD` and `APP_KEY` (with `APP_PREVIOUS_KEYS`).

## Settings that most often go wrong

| Symptom | Cause |
|---|---|
| Sign-in seems to work, then every request answers 401 | `SANCTUM_STATEFUL_DOMAINS` does not match the host in the browser. |
| Sign-in loops back to the sign-in page over HTTP | `SESSION_SECURE_COOKIE=true` without HTTPS: the browser drops the cookie. |
| Links in emails point at `localhost` | `APP_URL` or `FRONTEND_URL` not set to the public address. |
| Terms page still says it is a template | `OPERATOR_NAME`/`OPERATOR_EMAIL` changed without rebuilding the image. |
| Rate limits hit everyone at once | Behind a proxy without `TRUSTED_PROXIES`. |

## Volumes

| Volume | Holds | In backups |
|---|---|---|
| `db` | the PostgreSQL data directory | as a dump |
| `storage` | uploads, OAuth keys, framework caches | uploads and keys |
| `backups` | the dumps and archives, and `last-success` | n/a: copy it elsewhere |
| `caddy` | certificates when the container does HTTPS itself | no (they are re-issued) |

`docker compose down` keeps the volumes; `down --volumes` deletes all data.
