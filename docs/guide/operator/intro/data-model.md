# Data model

The tables an operator needs to recognise, and where files live outside the
database. The migrations in `backend/database/migrations/` are the source of
truth; this page is the map.

## Tenancy

Everything belongs to a **workspace**. A person (`users`) joins a workspace
through `workspace_memberships` with a role: `owner`, `admin`, `member` or
`guest`. Agents are rows in `users` too, with `type = agent`, owned by a
person and member of exactly one workspace. Queries are scoped to the current
workspace by `App\Http\Middleware\ResolveWorkspace` and the
`BelongsToWorkspace` model concern, so a request can never read another
workspace's rows by guessing an id.

## Main tables

| Area | Tables | Notes |
|---|---|---|
| People and access | `users`, `workspaces`, `workspace_memberships`, `workspace_invitations`, `personal_access_tokens` | `users.is_operator` marks operators; `suspended_at` on users and workspaces. |
| Work | `projects`, `states`, `work_items`, `comments`, `checklist_items`, `work_item_links`, `work_item_relations`, `labels`, `label_work_item`, `attachments`, `item_whiteboards` | Items are addressed by key (`WEB-143`). `work_items.version` supports `expected_version` (412 on conflict). |
| Planning | `cycles` | One open cycle per project when cycles are on. |
| Views, inbox and history | `saved_views`, `inbox_items`, `activities`, `subscriptions` | `activities` is the history of every change, with actor and token name. `subscriptions` are people following an item, not billing. |
| Agents | `agent_runs`, `agent_run_entries`, `mcp_connections` | The run log people read under **Agent runs**. |
| Docs and feedback | `pages`, `feedback` | Published pages may have a public link. |
| Integrations | `webhooks`, `webhook_deliveries` | Secrets are encrypted with `APP_KEY`. |
| AI | `ai_usage` | Tokens in and out per call, per workspace. Workspace keys are encrypted. |
| Billing | `plan_subscriptions`, `plan_subscription_items` (Cashier) | Only used with Stripe. Named `plan_*` because `subscriptions` already means following an item. |
| Operations | `audit_events`, `outgoing_emails`, `broadcasts` | Audit log, email record, announcements. |
| OAuth | `oauth_*` (Passport) | Clients, codes, access and refresh tokens. |
| Framework | `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens`, `idempotency_keys` | The database drivers for session, cache and queue. |
| Traffic lab | `sim_*` | Runs and their measurements. Pruned after `SIMULATOR_RETENTION_DAYS`. |

## Files

Uploaded attachments go to the `local` disk under
`storage/app/private/attachments/<workspace id>/<item id>/<uuid>` and are
served only through the API, after a permission check. The upload limit is
20 MB per file (`AttachmentController::MAX_KILOBYTES`); `docker/prod/php.ini`
allows 25 MB uploads and 30 MB requests to leave room around it.

The OAuth signing keys live in `storage/oauth-private.key` and
`storage/oauth-public.key` unless `PASSPORT_PRIVATE_KEY` and
`PASSPORT_PUBLIC_KEY` are set.

In Docker the whole `storage/` folder is the `storage` volume. The `backup`
service archives it without `framework/` and `logs/`.

## What is encrypted

Values encrypted with `APP_KEY`: workspace AI keys (`workspaces.ai_api_key`),
webhook secrets, and the session payload when `SESSION_ENCRYPT=true`. **Losing
`APP_KEY` makes these unreadable.** To rotate it, put the old key in
`APP_PREVIOUS_KEYS` (comma separated) while setting the new one; Laravel then
decrypts with either.

## Databases

PostgreSQL 15+ and MariaDB 10.11+ are both supported. The code uses no
database-specific SQL, and CI runs the whole test suite on PostgreSQL 17 and
MariaDB 11.8. Production tooling (`compose.prod.yaml`, `backup.sh`) is written
for PostgreSQL; see [Install without Docker](../install/without-docker.md#mariadb)
for MariaDB.
