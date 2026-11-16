# Troubleshooting

Symptoms, their usual causes, and the fix. Start with the health report
(`dc exec app php artisan app:health`) and the logs
(`dc logs --tail=200 app worker scheduler`); most problems show up in one of
them.

## Signing in

| Symptom | Cause | Fix |
|---|---|---|
| Sign-in succeeds, then every request answers 401 | `SANCTUM_STATEFUL_DOMAINS` does not match the browser's host (and port) | Set it to the host part of `APP_URL`; `dc up -d`. |
| The page reloads to the sign-in screen after signing in | `SESSION_SECURE_COOKIE=true` over plain HTTP, or a proxy that drops `Set-Cookie` | Use HTTPS, or set it to `false` for a trial only. |
| `419` on sign-in or any change | CSRF cookie missing: the web app and the API are on different hosts | Serve both from one address. |
| Confirmation email never arrives | SMTP settings, the worker is down, or the mail is in spam | `dc logs worker`; check SPF/DKIM for `MAIL_FROM_ADDRESS`; try with `MAIL_MAILER=log` to see the message in the log. |
| `429` for everybody at once | Behind a proxy without `TRUSTED_PROXIES` | Set it to the proxy's address. |

## Background work

| Symptom | Cause | Fix |
|---|---|---|
| No notifications, webhooks not sent, `queue` check failing | Worker stopped or crashed | `dc ps worker`, `dc logs worker`, `dc restart worker`. |
| Cycles do not roll over, announcements stay waiting, `scheduler` check failing | Scheduler stopped | `dc restart scheduler`. |
| `scheduler` check failing though the scheduler runs | `app` and `scheduler` do not share the cache | Keep `CACHE_STORE=database`. |
| A webhook switched itself off | 10 events in a row failed for good | The admin fixes the receiver and switches it on under **Settings**; the deliveries show the receiver's status code or error. |
| Webhook refused when saving | Private, loopback or plain-`http` address | Use a public `https` address. |

## Built-in AI

| Message | Cause | Fix |
|---|---|---|
| "The AI provider rejected the API key…" | Anthropic answered 401/403 | The workspace's own key takes precedence over `ANTHROPIC_API_KEY`: check it first, or clear it to fall back. Test the key with `curl` ([Optional features](../config/features.md#built-in-ai)). Check `AI_MODEL` is available to the key. After changing `.env.production`, `dc up -d`. |
| "No AI provider key is configured…" (`ai_not_configured`) | No installation key and no workspace key | Set `ANTHROPIC_API_KEY` and `dc up -d`, or let the workspace add its own key. |
| "AI features are switched off for this workspace…" (`ai_disabled`) | The workspace has not switched AI on | A workspace admin switches it on under **Settings**. |
| "…could not be reached" | No outbound HTTPS from `app` | Allow `api.anthropic.com:443` in the firewall or proxy. |
| "This workspace has used its AI allowance for the month…" (`ai_limit_reached`) | Allowance used up | Raise the workspace limit (up to `AI_MONTHLY_TOKEN_LIMIT` on the installation's key), raise that variable, or wait for the 1st. |

## Realtime

| Symptom | Cause | Fix |
|---|---|---|
| Boards do not update by themselves | Realtime is off or misconfigured | Check `BROADCAST_CONNECTION=reverb`, the `reverb` service runs (`--profile realtime`), and the image was rebuilt with `VITE_REVERB_*`. |
| WebSocket to `/app/…` fails with 4001 | `VITE_REVERB_APP_KEY` differs from `REVERB_APP_KEY` | Make them equal and rebuild. |
| WebSocket closes every minute | The proxy times out idle connections | Raise the proxy's read timeout above 60 s. |

## Files and storage

| Symptom | Cause | Fix |
|---|---|---|
| Uploads fail with 413 | The proxy's body limit is below 30 MB | Raise it (`client_max_body_size 30m` in nginx). |
| Uploads fail with 422 | The file is over 20 MB | That is the application's limit. |
| `storage` check failing | Volume not mounted or not writable by `www-data` | `dc exec -u root app chown -R www-data:www-data /app/storage`. |
| Attachments missing after a restore | Only the database dump was restored | Restore the matching `files-…tar.gz` as well. |

## Connected apps (OAuth / MCP)

| Symptom | Cause | Fix |
|---|---|---|
| `/.well-known/oauth-authorization-server` answers 404 | No OAuth signing keys | `dc restart app` (keys are created on start) or set `PASSPORT_*`. |
| Every app disconnected after a move or restore | OAuth keys were not restored | Restore `files-…tar.gz`, or let people reconnect. |
| An agent's requests answer 403 `token_read_only` | The token is read-only | The agent's owner creates a read-and-write token. |

## Start-up

| Symptom | Cause | Fix |
|---|---|---|
| `app` exits with "APP_KEY is not set" | Missing key | Generate and set it ([Deploy](../install/docker.md)). |
| `app` restarts in a loop after an upgrade | A migration failed | `dc logs app`; fix the cause; restore the pre-upgrade backup if needed. |
| `db` will not start: "database files are incompatible" | The PostgreSQL major version changed | Restore a dump into a fresh volume of the new version. |
| "The MAC is invalid" in the log; workspace AI keys or webhooks stop working | `APP_KEY` changed without `APP_PREVIOUS_KEYS` | Put the old key back, or add it to `APP_PREVIOUS_KEYS`. |

## Getting help

Collect the health report (`app:health`), the last 200 lines of
`dc logs app worker`, and the output of `dc ps`, with secrets removed, and open
an issue at <https://github.com/kKEo/yadeit/issues>.
