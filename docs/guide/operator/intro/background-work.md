# Background work

Two processes do work outside of requests: the **queue worker** and the
**scheduler**. If either stops, the web app still answers, but emails,
webhooks, cycles and announcements quietly stop too. The
[health report](../monitoring/health.md) notices both within minutes.

## The queue

The queue uses the `database` driver (`QUEUE_CONNECTION=database`): jobs are
rows in `jobs`, failures land in `failed_jobs`. The worker in
`compose.prod.yaml` runs:

```bash
php artisan queue:work --tries=3 --backoff=10 --max-time=3600 --sleep=1
```

- `--tries=3 --backoff=10`: a failing job is tried three times, ten seconds apart, then moved to `failed_jobs`.
- `--max-time=3600`: the worker exits after an hour and Docker restarts it, which keeps memory from creeping up.
- `DB_QUEUE_RETRY_AFTER` (default 90 s): a job held longer than this by a worker that died is handed out again.

What goes through the queue:

| Job | Sent by | Notes |
|---|---|---|
| Notification emails (`ItemActivity`) | `ActivityFanout`, after a change commits | Only to people who follow the item and have email notifications on. |
| Account emails (confirm address, reset password, invitations) | Sign-up, invitations | Without a worker nobody can confirm an address. |
| `SendWebhook` | `WebhookDispatcher` | Its own retry schedule, see below. |
| Realtime broadcasts (`WorkspaceChanged`, `InboxChanged`) | `ActivityFanout` | Reach browsers only when `BROADCAST_CONNECTION=reverb` and the `reverb` service runs. |

### Webhook retries

A delivery that fails (no answer within 10 s, or a non-2xx status) is retried
after 10 s, 1 min, 5 min and 30 min (`backend/config/webhooks.php`). Every
attempt is a row in `webhook_deliveries`. After **10 events in a row** fail for
good, the webhook switches itself off and the workspace admin sees it as
disabled. Receivers on private or loopback addresses are refused unless
`WEBHOOKS_ALLOW_PRIVATE_TARGETS=true`.

## The scheduler

`php artisan schedule:work` runs the schedule in `backend/routes/console.php`.
On a host without Docker, use cron instead:

```bash
* * * * * cd /srv/yadeit/backend && php artisan schedule:run >> /dev/null 2>&1
```

| When | Job | What it does |
|---|---|---|
| every minute | `health-heartbeat` | Writes a timestamp to the cache. The health report fails the scheduler check when it is older than 5 minutes. |
| every minute | `mail:send-waiting` | Sends up to 100 waiting announcement emails per run, never two runs at once. |
| every minute | `sim:watch` | Stops a Traffic lab run when a stop rule applies. Does nothing when the lab is off. |
| 00:10 daily | `cycles:advance` | Closes ended cycles, opens the next ones, moves unfinished items along. |
| 00:20 daily | `items:decay` | Returns open items nobody touched for the project's N days to the backlog (when the project enabled it). |
| 00:30 daily | `model:prune` (`OutgoingEmail`) | Deletes sent email records older than 90 days. |
| 00:40 daily | `sim:prune` | Removes Traffic lab runs older than `SIMULATOR_RETENTION_DAYS`, with everything they created. |

Times are in the application's time zone, UTC by default.

## Announcements

Operators write announcements in the Operator area. Sending one creates one
`outgoing_emails` row per recipient with status `waiting`; `mail:send-waiting`
then sends them in batches so a large audience does not flood the mail
server. A message that fails three times stays `failed` until an operator
resends it from **Operator area → Email**. Failed messages turn the `mail`
health check into a warning.

## The backup job

The `backup` service is not part of Laravel. It is a shell loop
(`docker/prod/backup.sh`) in a `postgres:17-alpine` container that dumps the
database and archives `storage/` every `BACKUP_INTERVAL_HOURS`, then touches
`/backups/last-success`. The app reads that file's age through
`BACKUP_HEARTBEAT`. See [Backup and restore](../operations/backup.md).
