# Upgrade and release

How to move an installation to a newer version, what happens during the
upgrade, and what to do when it goes wrong. An upgrade without migrations
takes a minute or two of rebuild and a few seconds of downtime.

## Upgrade

```bash
cd /srv/yadeit
git fetch && git log --oneline HEAD..origin/master   # read what changes
dc exec backup /bin/sh /backup.sh once                 # always, before a migration
git pull
dc up -d --build
dc exec app php artisan app:health
```

What happens:

1. The image is rebuilt: web app, PHP dependencies, application.
2. `app` is recreated. Its entrypoint runs `php artisan migrate --force`
   **before** it starts serving, then caches config and routes.
3. `worker` and `scheduler` are recreated once `app` reports healthy, so they
   never run new code against an old schema.

Requests during the switch (a few seconds) get connection errors from the
proxy. To show a maintenance page instead, configure one in your proxy for
503 and 502 answers.

To see pending migrations before you upgrade:

```bash
dc run --rm --entrypoint php app artisan migrate:status
```

## When something goes wrong

**The new `app` does not become healthy.** `dc logs app`. A failed migration
stops the container before it serves; the error is in the log. Fix the cause
(often disk space or a database permission), then `dc up -d` again.

**Roll back.** Migrations are written to run forward; rolling back code
without rolling back the schema is not supported. To return to the previous
version:

```bash
git checkout <previous tag or commit>
dc up -d --build
# then restore the backup taken before the upgrade (Backup and restore)
```

## Rebuild-only changes

These take effect only after `dc up -d --build`, because they are built into
the web app: `OPERATOR_NAME`, `OPERATOR_EMAIL` and every `VITE_REVERB_*`.

All other settings take effect after `dc up -d`, which recreates containers
whose environment changed.

## Rotating secrets

| Secret | How |
|---|---|
| `APP_KEY` | Generate a new one; set `APP_PREVIOUS_KEYS=<old key>` and `APP_KEY=<new key>`; `dc up -d`. Remove the old key later only if nothing encrypted with it remains (workspace AI keys and webhook secrets are re-encrypted only when saved again). |
| `DB_PASSWORD` | `dc exec db psql -U yadeit -c "ALTER USER yadeit PASSWORD '<new>'"`, then update `.env.production` and `dc up -d`. |
| `HEALTH_TOKEN` | Change it in `.env.production` and in the monitor; `dc up -d`. |
| `REVERB_APP_SECRET` | Change it and `dc --profile realtime up -d`. Changing `REVERB_APP_KEY` also needs a rebuild (`VITE_REVERB_APP_KEY`). |
| OAuth keys | Delete `storage/oauth-*.key` and restart `app` (or set new `PASSPORT_*`). **Every connected app is disconnected.** |
| `ANTHROPIC_API_KEY` | Create a new key in the Anthropic console, set it, `dc up -d`, then revoke the old one. |

## Releasing (for maintainers)

The project has no version numbers yet: `master` is the release. A change is
releasable when CI (`.github/workflows/ci.yml`) is green: code style and
static analysis, the test suite on PostgreSQL and MariaDB, the web app's type
check, build and tests, the simulator's checks, and the documentation build.
On `master`, CI also runs a Traffic lab smoke run against the built image.
Mention new migrations, new required settings and rebuild-only changes in the
pull request so operators know what an upgrade involves.
