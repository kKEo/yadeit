# System overview

What a Yadeit installation is made of, which process does what, and how a
request finds its way from a browser or an agent to the database.

## The parts

Yadeit is a Laravel 13 JSON API (`backend/`) and a Vue 3 single-page web app
(`frontend/`). In production both ship in **one Docker image** (`Dockerfile`),
built in three stages: the web app with Node 24, the PHP dependencies with
Composer, and the final image on FrankenPHP with PHP 8.5. The same image runs
every application process; only the command differs.

| Service | Command | What it does |
|---|---|---|
| `app` | `frankenphp run` | Serves the web app and every backend address from one port. Migrates the database on start. |
| `worker` | `php artisan queue:work --tries=3 --backoff=10 --max-time=3600 --sleep=1` | Sends notification emails and webhooks from the queue. |
| `scheduler` | `php artisan schedule:work` | Runs timed jobs: cycles, stale items, announcements, clean-up, the health heartbeat. |
| `db` | `postgres:17-alpine` | All data, plus sessions, cache and the queue (all `database` drivers). |
| `backup` | `docker/prod/backup.sh` | A database dump and an archive of the uploads every 24 hours. |
| `reverb` | `php artisan reverb:start` | Realtime signals over WebSocket. Optional (`--profile realtime`). |

The services are defined in `compose.prod.yaml`. There is no Redis: the
database holds the cache, the sessions and the queue, so PostgreSQL (or
MariaDB) is the only stateful dependency besides the uploads folder.

## One address

Everything is reached through one origin. `docker/prod/Caddyfile` routes by
path:

| Path | Goes to |
|---|---|
| `/api/*`, `/oauth/*`, `/mcp`, `/.well-known/oauth-*`, `/sanctum/*`, `/stripe/*`, `/llms.txt`, `/docs/*`, `/openapi.json`, `/up`, `/health`, `/storage/*` | PHP (`backend/public/index.php`) |
| `/app/*`, `/apps/*` | Reverb at `REVERB_UPSTREAM` (default `reverb:8080`) |
| anything else | the built web app in `/app/spa`, falling back to `index.html` |

The web app and the API must share the origin. The browser signs in with a
cookie session (Laravel Sanctum's stateful API), and the OAuth consent flow
for connected apps relies on that same session. Splitting them onto two
hosts breaks sign-in.

## How a request travels

1. **Caddy** terminates HTTPS (when `SERVER_NAME` is a domain), compresses the
   response and adds `X-Content-Type-Options`, `X-Frame-Options: DENY` and
   `Referrer-Policy` headers.
2. **Authentication.** The web app uses a session cookie; scripts and agents
   send `Authorization: Bearer <token>` (Sanctum personal access tokens). MCP
   clients may also use OAuth access tokens issued by Passport, which are
   accepted at `/mcp` only.
3. **Middleware** in this order for the API group (`backend/bootstrap/app.php`):
   rate limit (`throttle:api`), active account (`active`), token scope
   (`token.scope`, so out-of-scope projects are simply not found), workspace
   resolution (`workspace`), and the audit trail (`AuditTrail`).
4. **Controllers** write through services such as
   `App\Services\WorkItemWriter`. After the transaction commits,
   `App\Services\ActivityFanout` sends the realtime signal, creates inbox
   entries and queues emails, and `App\Services\WebhookDispatcher` queues one
   `SendWebhook` job per subscribed webhook.
5. **Errors** always come back as JSON with one shape,
   `{"error": {"code", "message", "details"}}`, for `/api/*` and `/mcp`.

## The MCP server

`/mcp` speaks the Model Context Protocol (`App\Mcp\YadeitServer`). Its 14 tools
are thin wrappers that call the JSON API in-process as the agent
(`App\Mcp\ApiBridge`), so permissions, token scopes and rate limits are the
same as over REST. The guide resource `yadeit://guide/agent-workflow` tells
agents how to work.

## Built-in AI

Optional drafting, splitting, summarising and triage suggestions from a Claude
model, through the official Anthropic PHP SDK (`App\Ai\AnthropicProvider`).
Each call is synchronous within the request, constrained to a JSON schema,
and metered per workspace in `ai_usage`. A person confirms every result;
the model never writes to the database. See
[Optional features](../config/features.md#built-in-ai).

## Realtime

When something changes, the API broadcasts a small signal with identifiers
only, never content, on `workspace.{id}` or `user.{id}`. The browser then
re-reads through the API, so permission checks always apply. Without Reverb
the app still works; browsers simply refresh on navigation.
