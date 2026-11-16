# Logs, metrics and alerts

Where Yadeit writes what it does, what deserves an alert, and what to watch
over weeks. There is no built-in metrics endpoint or error tracker; this page
says what to use instead.

## Logs

In the image, `LOG_CHANNEL=stderr`: every service writes to its container's
output.

```bash
dc logs -f --tail=100 app worker scheduler
dc logs --since=1h backup
```

| Service | Writes |
|---|---|
| `app` | PHP errors and exceptions, Caddy's request errors, migrations at start |
| `worker` | one line per job processed or failed (`DONE`, `FAIL`) and exceptions |
| `scheduler` | each timed job as it runs |
| `backup` | `backup <time> done: <sizes>` or `backup FAILED` |
| `db` | PostgreSQL's own log |

Set `LOG_LEVEL=info` (the production example) or `warning` to reduce noise.
For a log platform that parses JSON, set
`LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter`.

Docker keeps container logs in its `json-file` driver without a size limit by
default. Configure rotation in `/etc/docker/daemon.json`
(`"log-opts": {"max-size": "20m", "max-file": "5"}`) or ship the logs to
Loki, Elasticsearch, CloudWatch or similar.

> **Note.** Logs can contain email addresses and IP addresses. Treat them like
> the database: limit who can read them and how long they are kept.

## Error tracking

None is built in. To get alerts with stack traces, add an error tracker such
as Sentry (`sentry/sentry-laravel`) to the backend and rebuild the image.

## What to alert on

| Alert | Source | Urgency |
|---|---|---|
| `/health` answers anything but 200 for 2 checks in a row | uptime monitor | page someone |
| `/up` unreachable | uptime monitor | page someone |
| `backup FAILED` in the `backup` logs, or the `backup` check failing | logs / `/health` | same day |
| Disk over 80 % on the volume holding `db`, `storage` or `backups` | host monitoring | same day |
| Off-site backup copy older than a day | your copy job | same day |
| TLS certificate expires in under 14 days | uptime monitor | this week |
| `failed_jobs` warning | `/health` body | this week |

## What to watch over time

These come from the database; run them in `dc exec db psql -U yadeit yadeit`
or turn them into dashboard queries.

```sql
-- Size of the database and of the largest tables
SELECT pg_size_pretty(pg_database_size(current_database()));
SELECT relname, pg_size_pretty(pg_total_relation_size(relid))
  FROM pg_catalog.pg_statio_user_tables ORDER BY pg_total_relation_size(relid) DESC LIMIT 10;

-- Queue backlog and failures
SELECT count(*) FROM jobs;
SELECT count(*) FROM failed_jobs WHERE failed_at > now() - interval '7 days';

-- AI tokens this month, per workspace
SELECT w.slug, sum(u.input_tokens + u.output_tokens) AS tokens
  FROM ai_usage u JOIN workspaces w ON w.id = u.workspace_id
 WHERE u.created_at >= date_trunc('month', now())
 GROUP BY w.slug ORDER BY tokens DESC;

-- Webhook deliveries that failed in the last day
SELECT count(*) FROM webhook_deliveries WHERE created_at > now() - interval '1 day' AND NOT succeeded;
```

The uploads volume grows with attachments: `dc exec app du -sh storage/app`.

## Load and performance

The query count of each read endpoint is held flat by
`backend/tests/Feature/PerformanceTest.php`, so a list does not get slower as
it grows. To measure the whole installation under load, use the
[Traffic lab](https://github.com/kKEo/yadeit/blob/master/docs/traffic-lab.md)
on a copy: it reports response times, error rates and failed flows per run
(`php artisan sim:report`).
