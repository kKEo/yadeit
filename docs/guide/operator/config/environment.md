# Environment variables

The settings an installation reads, grouped by area. "Default" is what the
code uses when the variable is unset; `.env.production.example` sets
production values for some of them. **Required** means the installation does
not work properly without a value.

## The address

| Variable | Default | Meaning |
|---|---|---|
| `APP_URL` | `http://localhost` | **Required.** The public address with scheme, for example `https://yadeit.example.com`. Used in emails, OAuth metadata and file links. |
| `FRONTEND_URL` | `http://localhost:5173` | **Required.** The web app's address. In production the same as `APP_URL`. Also the only CORS origin allowed. |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost`, `127.0.0.1` and the `APP_URL` host | **Required.** Host (and port, if not 80/443) whose browser requests get a session. Wrong: sign-in seems to work, then everything is 401. |
| `SESSION_SECURE_COOKIE` | unset (false) | `true` in production. Over plain HTTP it must be `false`, or the browser drops the cookie. |
| `SESSION_DOMAIN` | `null` | Leave unset: the cookie belongs to the exact host. |
| `TRUSTED_PROXIES` | unset | Comma-separated proxy addresses, or `*`. Needed behind a proxy for correct client addresses. |
| `SERVER_NAME` | `:8080` | Docker only. `:8080` for plain HTTP behind a proxy, or a domain for automatic HTTPS. |
| `HTTP_PORT` | `8080` | Docker only. Host port mapped to the container. |

## Application

| Variable | Default | Meaning |
|---|---|---|
| `APP_KEY` | none | **Required.** `base64:` + 32 random bytes. Encrypts sessions, workspace AI keys and webhook secrets. Never change it without `APP_PREVIOUS_KEYS`. |
| `APP_PREVIOUS_KEYS` | empty | Old keys, comma separated, still accepted for decryption while you rotate. |
| `APP_NAME` | `Laravel` (`Yadeit` in the examples) | Shown in emails and the session cookie name. |
| `APP_ENV` | `production` | `production` in the image. `local` enables development behaviour. |
| `APP_DEBUG` | `false` | Never `true` on a public installation: error pages would show internals. |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` | `en` | Default language; people can choose Polish (`pl`) in their profile. |
| `APP_MAINTENANCE_DRIVER` | `file` | Where `php artisan down` keeps its flag. |
| `BCRYPT_ROUNDS` | `12` | Password hashing cost. |

## Database

| Variable | Default | Meaning |
|---|---|---|
| `DB_CONNECTION` | `pgsql` (examples) | `pgsql` or `mariadb`. Set to `pgsql` by `compose.prod.yaml`. |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `5432` | Set to `db` / `5432` by `compose.prod.yaml`. |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `yadeit` / `yadeit` / – | **Required** in production. In Docker, the database is created with these on first start; changing them later does not change the existing database. |

## Sessions, cache, queue

| Variable | Default | Meaning |
|---|---|---|
| `SESSION_DRIVER` | `database` | Sessions in the `sessions` table. |
| `SESSION_LIFETIME` | `120` | Minutes of inactivity before a browser session ends. |
| `SESSION_ENCRYPT` | `false` | Encrypt session payloads with `APP_KEY`. |
| `CACHE_STORE` | `database` | The health heartbeat lives in the cache; a cache that is not shared between `app` and `scheduler` (such as `array` or `file` on separate hosts) makes the scheduler look dead. |
| `QUEUE_CONNECTION` | `database` | The health report's queue check reads the `jobs` table, so keep `database`. |
| `DB_QUEUE_RETRY_AFTER` | `90` | Seconds before a job held by a dead worker is handed out again. |

## Email

| Variable | Default | Meaning |
|---|---|---|
| `MAIL_MAILER` | `log` | `smtp` in production. `log` writes emails to the log (local trials). |
| `MAIL_HOST` / `MAIL_PORT` | `127.0.0.1` / `2525` | Your SMTP server. 587 for STARTTLS, 465 with `MAIL_SCHEME=smtps`. |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | – | SMTP credentials. |
| `MAIL_SCHEME` | auto | `smtp` or `smtps`. |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | `hello@example.com` / `APP_NAME` | **Required.** The sender. Use a domain with SPF and DKIM for your SMTP provider, or mail lands in spam. |
| `MAIL_EHLO_DOMAIN` | `APP_URL` host | The name the server greets SMTP with. |

## Logging

| Variable | Default | Meaning |
|---|---|---|
| `LOG_CHANNEL` | `stderr` in the image, `stack` otherwise | `stderr` sends logs to the container output. |
| `LOG_LEVEL` | `debug` (`info` in the production example) | Minimum level written. |
| `LOG_STDERR_FORMATTER` | unset | Set to `Monolog\Formatter\JsonFormatter` for one JSON object per line. |

## Monitoring and backups

| Variable | Default | Meaning |
|---|---|---|
| `HEALTH_TOKEN` | unset | Bearer token for `GET /health`. Unset: the address answers 404. |
| `HEALTH_QUEUE_MAX_WAIT` | `10` | Minutes a job may wait before the queue check fails. |
| `BACKUP_HEARTBEAT` | unset (`/backups/last-success` in Docker) | File whose age the backup check reads. Unset: backups are not checked. |
| `BACKUP_MAX_AGE_HOURS` | `36` | Older heartbeat: the backup check fails. |
| `BACKUP_INTERVAL_HOURS` | `24` | Docker `backup` service: hours between backups. |
| `BACKUP_KEEP_DAYS` | `14` | Docker `backup` service: older backups are deleted. |

## Who runs it (built into the web app)

| Variable | Default | Meaning |
|---|---|---|
| `OPERATOR_NAME` (`VITE_OPERATOR_NAME` without Docker) | empty | Shown on the terms and privacy pages. Until both are set, the pages say they are a template. |
| `OPERATOR_EMAIL` (`VITE_OPERATOR_EMAIL`) | empty | Contact address on those pages. |

## Built-in AI

| Variable | Default | Meaning |
|---|---|---|
| `ANTHROPIC_API_KEY` | unset | The installation's key, used by workspaces without their own. Unset and no workspace key: AI cannot be switched on. |
| `AI_MODEL` | `claude-opus-5-5` | The Claude model for every built-in AI feature. |
| `AI_EFFORT` | `low` | `low`, `medium`, `high`, `xhigh` or `max`. The features are short drafting tasks; `low` is enough. |
| `AI_MAX_OUTPUT_TOKENS` | `16000` | Upper bound per answer. |
| `AI_MONTHLY_TOKEN_LIMIT` | `2000000` | Tokens (in + out) per workspace per calendar month on the installation's key. |

## Plans and billing

| Variable | Default | Meaning |
|---|---|---|
| `PLAN_LIMITS` | `false` | `true` applies the limits in `backend/config/plans.php`. |
| `STRIPE_KEY` / `STRIPE_SECRET` | unset | Stripe API keys. |
| `STRIPE_WEBHOOK_SECRET` | unset | Signing secret of the Stripe webhook. Unset: `/stripe/webhook` refuses everything. |
| `STRIPE_PRICE_TEAM` | unset | The `price_…` id of the Team plan's recurring price. |

## Realtime

| Variable | Default | Meaning |
|---|---|---|
| `BROADCAST_CONNECTION` | `null` | `reverb` to send realtime signals. |
| `REVERB_APP_ID` / `REVERB_APP_KEY` / `REVERB_APP_SECRET` | – | Credentials shared by the app and Reverb. Use long random values in production. |
| `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` | – / `443` / `https` | Where the **app** reaches Reverb. In Docker: `reverb`, `8080`, `http`. |
| `REVERB_SERVER_HOST` / `REVERB_SERVER_PORT` | `0.0.0.0` / `8080` | Where Reverb listens. |
| `REVERB_UPSTREAM` | `reverb:8080` | Docker only: where Caddy proxies `/app/*`. |
| `VITE_REVERB_APP_KEY` | empty | Built in. Must equal `REVERB_APP_KEY`; empty switches realtime off in the browser. |
| `VITE_REVERB_HOST` / `VITE_REVERB_PORT` / `VITE_REVERB_SCHEME` | – | Built in. Where the **browser** connects: the public host, `443`, `https`. |

## OAuth apps and MCP

| Variable | Default | Meaning |
|---|---|---|
| `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY` | unset | OAuth signing keys as PEM text. Unset: keys from `storage/oauth-*.key` (created on first start in Docker). |

## Webhooks

| Variable | Default | Meaning |
|---|---|---|
| `WEBHOOKS_ALLOW_PRIVATE_TARGETS` | `false` | `true` lets webhooks call private and loopback addresses. Local development only. |

## Traffic lab

Off by default. On `APP_ENV=production` both switches are needed. See
[Optional features](features.md#traffic-lab).

| Variable | Default | Meaning |
|---|---|---|
| `SIMULATOR_ENABLED` | `false` | Switches the lab on. |
| `SIMULATOR_ALLOW_PRODUCTION` | `false` | Second switch, required in production. |
| `SIMULATOR_RUNNER_TOKEN` | empty | Shared secret of a standing runner. |
| `SIMULATOR_SINK_URL` | `APP_URL/api/v1/sim/sink` | Where simulated webhooks go (`http://app:8080/api/v1/sim/sink` in Docker). |
| `SIMULATOR_MAIL_DOMAIN` | `sim.invalid` | Domain of simulated people's addresses. Mail to it is never sent. |
| `SIMULATOR_MAX_TEAMS` | `50` | Cap on teams per run. |
| `SIMULATOR_MAX_SPEED` | `100` | Cap on time speed-up. |
| `SIMULATOR_MAX_DURATION_MINUTES` | `240` | Cap on run length. |
| `SIMULATOR_RETENTION_DAYS` | `14` | Finished runs are removed after this. |
| `SIMULATOR_AI_MODEL` | `claude-haiku-5-5` | Model for AI-driven simulated members. Uses `ANTHROPIC_API_KEY`. |
| `SIMULATOR_AI_TOKEN_BUDGET` | `500000` | Tokens one run may spend on AI. |

## Settings that are not variables

Some values are fixed in `backend/config/` and change only with a code
change: webhook retries and timeout (`webhooks.php`), plan limits
(`plans.php`), rate limits (`AppServiceProvider.php`), the upload limit
(`AttachmentController.php`). They are listed in [Limits and plans](limits.md).
