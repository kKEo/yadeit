# The health report

One report answers "is the installation working?". It is built by
`App\Support\Health` and shown in three places. Each check is `ok`,
`warning` (look at it soon) or `failing` (people are affected now). The
report's overall status is the worst of its checks.

## Reading it

**In the browser.** Operator area, first screen.

**From a monitor.** `GET /health` with the bearer token:

```bash
curl -s -H "Authorization: Bearer $HEALTH_TOKEN" https://yadeit.example.com/health
```

```json
{
  "status": "ok",
  "checked_at": "2026-11-16T09:00:00+00:00",
  "checks": {
    "database":    {"status": "ok", "detail": "Answers."},
    "queue":       {"status": "ok", "detail": "Nothing waits."},
    "failed_jobs": {"status": "ok", "detail": "None in the last day."},
    "scheduler":   {"status": "ok", "detail": "Ran within the last 5 minutes."},
    "storage":     {"status": "ok", "detail": "Writable."},
    "mail":        {"status": "ok", "detail": "No failed messages."},
    "backup":      {"status": "ok", "detail": "Last successful backup: 2026-11-16T00:00:04+00:00."}
  }
}
```

| Answer | Meaning |
|---|---|
| `200` | nothing is failing (warnings are allowed) |
| `503` | at least one check is failing |
| `401` | wrong or missing token |
| `404` | `HEALTH_TOKEN` is not set: the address is switched off |
| `429` | more than 60 requests a minute from one address |

Point your uptime monitor (Uptime Kuma, Better Stack, Pingdom…) at it every
one to five minutes and alert on anything but 200. To alert on warnings too,
match `"status":"ok"` in the body.

**In a shell.**

```bash
dc exec app php artisan app:health      # exits non-zero when something is failing
```

**Liveness only.** `GET /up` answers 200 when the application boots. Docker's
health check uses it (every 30 s, 3 retries, 60 s start period). It says
nothing about the worker, the scheduler or backups; use `/health` for alerts.

## The checks

| Check | Failing / warning when | What to do |
|---|---|---|
| `database` | failing: the query `select 1` does not answer | `dc ps db`, `dc logs db`; check disk space and `DB_*` settings. |
| `queue` | failing: a job has waited longer than `HEALTH_QUEUE_MAX_WAIT` (10 min) | The worker is down or stuck: `dc ps worker`, `dc logs worker`, `dc restart worker`. Notifications and webhooks are delayed until it runs. |
| `failed_jobs` | warning: jobs failed in the last 24 hours | `dc exec app php artisan queue:failed`, read the exception, then `queue:retry all` or `queue:flush`. |
| `scheduler` | failing: no heartbeat for over 5 minutes | `dc ps scheduler`, `dc restart scheduler`. Also check that `app` and `scheduler` share the cache (`CACHE_STORE=database`). |
| `storage` | failing: `storage/app` is missing or not writable | Volume not mounted or wrong owner. In Docker the files must belong to `www-data`. |
| `mail` | warning: announcement messages ended as failed | **Operator area → Email**: read the error, fix SMTP, send again. |
| `backup` | failing: `BACKUP_HEARTBEAT` file older than `BACKUP_MAX_AGE_HOURS` (36 h) or missing | `dc logs backup`; run one by hand (`dc exec backup /bin/sh /backup.sh once`). Shows `ok` with "not watched" when `BACKUP_HEARTBEAT` is unset. |

A check that throws is reported as `failing` with the exception's class name,
without hiding the other checks.

## What the report does not cover

- Whether **email delivery** works for account emails (confirmations, resets). Only announcements are tracked. Test with a password reset after any SMTP change.
- **Reverb.** Realtime failing is not a health failure; the app works without it.
- **Disk space**, CPU and memory of the host. Watch those with your host monitoring.
- **Backups leaving the machine.** The check sees only the local `last-success` file.
- **TLS certificate expiry** when your own proxy terminates HTTPS.
