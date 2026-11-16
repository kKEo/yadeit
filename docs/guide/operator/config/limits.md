# Limits and plans

The fixed numbers that shape what people can do: plan limits, sizes, counts
and rate limits. Most of them live in code, not in environment variables, so
changing one means changing the file named and rebuilding.

## Plans

Defined in `backend/config/plans.php`. Applied only with `PLAN_LIMITS=true`;
without it nothing is held back, which is right for a self-hosted
installation without paid plans.

| Plan | People | Projects | Built-in AI tokens a month | Price |
|---|---|---|---|---|
| `free` (default) | 5 | 3 | 200,000 | – |
| `team` | 50 | no limit | 2,000,000 | `STRIPE_PRICE_TEAM` |

- Agents are free and never count as people. Open invitations do count.
- The AI limit of a plan applies only when the workspace uses the
  installation's key; a workspace with its own key pays its own tokens.
- A request over a limit gets `402 plan_limit_reached`, with the limit and
  current usage in `error.details`.

To add or change a plan, edit `plans.php` and rebuild. Keep the `free` key:
it is the default for new workspaces.

## Sizes and counts

| What | Limit | Where |
|---|---|---|
| One uploaded file | 20 MB | `AttachmentController::MAX_KILOBYTES` (and `docker/prod/php.ini`: 25 MB upload, 30 MB request) |
| Text sent to AI in one request | 300,000 characters | `AiController::MAX_CHARACTERS`; longer is refused, never cut |
| One AI answer | 16,000 tokens | `AI_MAX_OUTPUT_TOKENS` |
| Tokens per agent | 20 | `AgentController::MAX_TOKENS_PER_AGENT` |
| Webhooks per workspace | 20 | `WebhookController::MAX_PER_WORKSPACE` |
| Words in a search | 6 (more are ignored) | `SearchController::MAX_WORDS` |
| Whiteboard elements | 5,000 per board | `WhiteboardController` |

## Rate limits

Per minute, from `backend/app/Providers/AppServiceProvider.php`:

| Limiter | Limit | Applies to |
|---|---|---|
| `api` | 300 | each access token, or each signed-in person |
| `mcp` | 120 | each access token on `/mcp` |
| `auth` | 10 per address and 20 per email | sign-in, sign-up, password reset, email confirmation |
| `public-feedback` | 5 per address | the public feedback form |
| `/health`, `/llms.txt`, `/docs/*` | 60 per address | |

A request over the limit gets `429` with a `Retry-After` header. Traffic lab
requests get a larger allowance in proportion to the run's speed.

## Webhooks

From `backend/config/webhooks.php`: 10 s timeout per delivery, retries after
10 s, 1 min, 5 min and 30 min, and automatic disabling after 10 events in a
row failed for good.

## Retention

| What | Kept | By |
|---|---|---|
| Sent email records | 90 days | `model:prune` at 00:30 |
| Traffic lab runs | `SIMULATOR_RETENTION_DAYS` (14) | `sim:prune` at 00:40 |
| Backups | `BACKUP_KEEP_DAYS` (14) | `backup.sh` |
| OAuth refresh tokens | 30 days unused | Passport |
| Everything else (items, history, audit log) | until deleted | – |
