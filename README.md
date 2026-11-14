# Yadeit

A simple project tool for people and AI agents: work items, boards, docs and feedback, with an API and an MCP server that agents can use as team members.

This is a rewrite. The original 2012 Yii application is kept under the git tag `legacy-yii`.

## Layout

| Path | What it is |
|---|---|
| `backend/` | Laravel 13 JSON API, served under `/api/v1` |
| `frontend/` | Vue 3 + TypeScript single-page app |
| `compose.yaml` | PostgreSQL and MariaDB for local development and tests |

## Requirements

- PHP 8.3 or newer with `pdo_pgsql` or `pdo_mysql`, and Composer
- Node.js 24 or newer
- PostgreSQL 15+ or MariaDB 10.11+ (Docker Compose provides both)

## Setup

```sh
docker compose up -d db         # PostgreSQL with the yadeit and yadeit_test databases

cd backend
composer setup                  # install, create .env, generate key, migrate
php artisan serve               # http://localhost:8000
php artisan queue:work          # emails and realtime signals are sent from the queue
php artisan schedule:work       # daily housekeeping: cycles roll over, stale items return to the backlog
php artisan reverb:start        # realtime updates over WebSocket, port 8080 (optional)

cd ../frontend
npm install
npm run dev                     # http://localhost:5173, proxies /api to the backend
```

### Using MariaDB instead

```sh
docker compose up -d mariadb
```

and in `backend/.env` set `DB_CONNECTION=mariadb` and `DB_PORT=3306`. Both databases are supported equally: the code uses no database-specific SQL, and the test suite runs against both in CI.

## Checks

```sh
cd backend
php artisan test                # needs the yadeit_test database (PostgreSQL by default)
DB_CONNECTION=mariadb DB_PORT=3306 php artisan test   # the same suite on MariaDB
vendor/bin/pint --test          # code style
vendor/bin/phpstan analyse      # static analysis

cd ../frontend
npm run build                   # type check and production build
npm run test
```

The same checks run in GitHub Actions on every push and pull request.

## Planning, docs and search

- **Cycles:** switch them on per project (Projects → Planning) with a length of 1 to 8 weeks. The first cycle starts on the Monday of that week; when a cycle ends the next one opens and unfinished items move along. Work that starts joins the current cycle.
- **Stale items:** optionally, open items nobody touched for N days return to the backlog. Items waiting for an answer are left alone.
- **Docs:** Markdown pages for the workspace or a project, as drafts or published. A published page can get a link that works without signing in.
- **Search:** `Cmd/Ctrl+K` finds items, comments and pages that contain every word typed. It is plain substring matching, the same on PostgreSQL and MariaDB.

## Feedback

- **Inbox:** anyone in the workspace, guests and agents included, can send feedback for a project. Members decide what happens to it under **Feedback**: accept it as a new item, add it to an existing item, decline it, mark it as a duplicate, or snooze it (`1`, `2`, `3` and `H` on the keyboard).
- **Demand:** every piece of feedback linked to an item counts as one more voice for it. Items show the count and can be sorted and filtered by it.
- **Design work:** a link's kind is read off its address (a Figma link is a design, a pull request a PR). Figma links offer a preview that loads only when asked for. Image attachments form a gallery with a lightbox. The command palette has "Show design work".
- **Public form:** an admin can switch on a form per project that works without an account (`/f/<token>`). It is rate-limited and has a hidden field that catches simple spam robots. Switching it off kills the link for good.

## Realtime

Open browsers refresh themselves when something changes. The API sends small signals (identifiers only, never content) through Laravel Reverb; the web app then re-reads through the API. Copy the `REVERB_*` values from `backend/.env` into `frontend/.env.local` as `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT` and `VITE_REVERB_SCHEME`. Without them the app works, just without live updates.

## MCP server for agents

Agents can use Yadeit through the Model Context Protocol at `/mcp` (protocol revision 2026-07-28; the older handshake is accepted too). Create an agent and a token in the web app, then point the client at it, for example:

```sh
claude mcp add --transport http yadeit http://localhost:8000/mcp --header "Authorization: Bearer <token>"
```

There are 14 tools covering the working loop (`whoami`, `ready_items`, `claim_item`, `get_item`, `log_progress`, `add_comment`, `link`, `set_acceptance`, `submit_for_review`, and `search_items`, `list_projects`, `create_item`, `update_item`, `list_activity`), plus the guide `yadeit://guide/agent-workflow`. Every tool goes through the JSON API as the agent, so token limits and permissions are the same in both.

## Webhooks

Workspace admins can add webhooks under **Settings**. Yadeit then sends a JSON `POST` to your address when items change (`item.created`, `item.updated`, `item.commented`, `item.claimed`, `item.input_requested`, `item.submitted_for_review` and others).

- Every request is signed: the `X-Yadeit-Signature` header is `t=<unix time>,v1=<hex>`, where `v1` is HMAC-SHA256 of `<t>.<raw body>` with the webhook's secret. Reject requests whose `t` is old.
- `X-Yadeit-Delivery` (also `id` in the body) stays the same across retries of one event; use it to ignore repeats.
- Failed deliveries are retried after 10 s, 1 min, 5 min and 30 min. After 10 events in a row fail for good, the webhook switches itself off.
- Addresses must be `https` and public. For local development set `WEBHOOKS_ALLOW_PRIVATE_TARGETS=true` in `backend/.env`.
- Deliveries are sent from the queue, so `php artisan queue:work` must be running.

## Documentation for agents

The backend serves its own documentation as Markdown, without a sign-in: `/llms.txt` is the index, and `/docs/agents.md`, `/docs/api.md`, `/docs/reference.md` (generated from the OpenAPI file) and `/docs/webhooks.md` are the pages.

## API conventions

- The full description is generated to `backend/public/openapi.json` (`php artisan scramble:export`, needs a migrated database) and served at `/openapi.json`.
- Work items are addressed by key, for example `/api/v1/workspaces/acme/items/WEB-143`.
- Lists page with `limit` and `cursor`; `fields` and `expand` shape the rows.
- Creates accept an `Idempotency-Key` header and are safe to retry; updates accept `expected_version` and answer 412 when the item changed in the meantime.

- Agents are members that act through access tokens. Create one under **Agents** in the web app, then create a token for it: read-only or read-and-write, optionally limited to some projects, optionally expiring. The secret is shown once.
- An agent's working loop: `GET items/ready` lists what can be started now (not started, not blocked, not held by anyone), most urgent first; `POST items/{key}/claim` takes one atomically (a second caller gets 409 `already_claimed`); `POST items/{key}/ask` pauses on a question until a person replies; `POST items/{key}/submit-for-review` hands the work back. While it works, `POST items/{key}/log` (`thought`, `action` or `error`) keeps a run log that people read on the item under **Agent runs**. The agent becomes the item's `delegate`; the assignee stays the person who answers for it.
- What agents may not do, whatever their token allows: delete items, attachments or labels; move an item to a completed or cancelled state (they submit for review and a person closes it); choose the assignee or hand work to another agent; create or change projects; manage members, agents, tokens or webhooks.
- A JSON field an endpoint does not know is refused with the list of accepted fields, so a misspelt field never passes silently.
- Every history entry records who made the change and, for changes made with an access token, the token's name. Each token has its own rate limit.

- All endpoints live under `/api/v1`.
- The web app signs in with a cookie session; scripts and agents send `Authorization: Bearer <token>`.
- Errors always have the same shape:

```json
{ "error": { "code": "validation_failed", "message": "...", "details": { "fields": { "title": ["..."] } } } }
```
