# Commands

Every command an operator uses, in one place. In Docker, run `artisan`
commands as `dc exec app php artisan <command>` (see the
[`dc` alias](../README.md#conventions)); on a host, as `php artisan
<command>` in `backend/`.

## Yadeit's own commands

| Command | What it does |
|---|---|
| `operator:grant {email} [--revoke]` | Make a person an operator, or take the role away. |
| `app:health` | Print the health report; exits with an error when a check is failing. |
| `cycles:advance` | Close ended cycles, open the next ones, move unfinished items. Scheduled at 00:10. |
| `items:decay` | Return untouched open items to the backlog, where projects enabled it. Scheduled at 00:20. |
| `mail:send-waiting [--limit=100]` | Send waiting announcement emails. Scheduled every minute. |

## Traffic lab commands

Only useful with `SIMULATOR_ENABLED=true`. Details in
[docs/traffic-lab.md](https://github.com/kKEo/yadeit/blob/master/docs/traffic-lab.md).

| Command | What it does |
|---|---|
| `sim:start [--teams= --size= --speed= --minutes= --seed= …] [--token-only]` | Start a run and print the token its runners sign in with. |
| `sim:stop [run] [--purge]` | Stop a run; `--purge` removes everything it created. |
| `sim:report [run] [--json] [--baseline=]` | Show what a run measured; exits with 1 when it failed its thresholds. |
| `sim:watch` | Stop a run when a stop rule applies. Scheduled every minute. |
| `sim:prune` | Remove runs older than `SIMULATOR_RETENTION_DAYS`. Scheduled at 00:40. |

## Framework commands worth knowing

| Command | When |
|---|---|
| `migrate --force` | Runs on every `app` start; by hand only without Docker. |
| `migrate:status` | Before an upgrade: which migrations are pending. |
| `queue:failed` | List failed jobs with their exception. |
| `queue:retry all` / `queue:retry <id>` | Run failed jobs again after fixing the cause. |
| `queue:flush` | Delete all failed jobs. |
| `queue:restart` | Make running workers exit after their current job (host installs, after a release). |
| `schedule:list` | Show the schedule and the next run of each job. |
| `config:show <file>` | Show the effective configuration, for example `config:show ai`. Secrets are shown in full: do not paste the output anywhere. |
| `config:clear` | Drop the cached configuration (host installs, after editing `.env`). |
| `down` / `up` | Maintenance mode: the API answers 503 until `up`. |
| `passport:keys` | Create OAuth signing keys (host installs; Docker does it on first start). |
| `key:generate --show` | Print a new `APP_KEY` without writing it. |
| `scramble:export` | Regenerate `public/openapi.json`. |
| `tinker` | A PHP shell inside the application. Powerful and unaudited: use it read-only unless you know exactly what you change. |

## Backup script

```bash
dc exec backup /bin/sh /backup.sh once                    # back up now
dc exec backup /bin/sh /backup.sh list                    # list backups
dc exec backup /bin/sh /backup.sh restore DB [FILES]      # restore (replaces the database)
```

## Make targets

Run `make help` for the current list.

| Target | What it does |
|---|---|
| `app-up` / `app-down` / `app-reset` | Start, stop, or stop and delete the local production-like stack (`yadeit-local`, `.env.docker`). |
| `app-logs` / `app-ps` / `app-shell` | Follow logs, show services, open a shell in `app`. |
| `app-operator EMAIL=…` | Grant the operator role in the local stack. |
| `db-up` / `db-down` | Only the development database (`DB=mariadb` for MariaDB). |
| `sim-up` / `sim-run` / `sim-smoke` / `sim-report` / `sim-stop` / `sim-down` / `sim-logs` | The Traffic lab on the local stack. |
| `docs-build` / `docs-site` | Build the documentation into `site/`, or build and serve it on port 8000. |
