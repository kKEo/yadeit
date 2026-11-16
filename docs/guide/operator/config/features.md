# Optional features

Features that are off until the operator sets them up. Each section says what
to set, how to check that it works, and what the people using the
installation then see.

## Built-in AI

Drafting an item from one sentence, proposing sub-items, summarising a
discussion or a page, and suggesting how to triage feedback, with a Claude
model. A person confirms every result.

**Set:**

```bash
ANTHROPIC_API_KEY=sk-ant-…        # from console.anthropic.com → API keys
AI_MODEL=claude-opus-5-5          # optional
AI_EFFORT=low                     # optional
AI_MONTHLY_TOKEN_LIMIT=2000000    # optional, per workspace per month
```

Then `dc up -d` to recreate the containers with the new value.

**Then:** a workspace admin switches AI on under **Settings** (see the user
guide's [Built-in AI help](../../user/ai-help.md)). A workspace may store its
**own key** there; it is encrypted, never shown again, and
**takes precedence over the installation's key**
(`App\Ai\AiService::key()`). Saving an empty key returns the workspace to
the installation's key.

**Which key is used and how much:**

| Workspace has… | Key used | Monthly allowance |
|---|---|---|
| no own key | `ANTHROPIC_API_KEY` | the lower of its own limit and `AI_MONTHLY_TOKEN_LIMIT`, further capped by the plan when `PLAN_LIMITS=true` |
| its own key | the workspace key | its own limit, or `AI_MONTHLY_TOKEN_LIMIT` when it set none |

Usage is metered per call in `ai_usage` (input + output tokens) and resets on
the first of each month.

**Check:** open an item, choose an AI action. If it fails, the message names the cause:

| Message (code) | Meaning |
|---|---|
| "The AI provider rejected the API key…" (`ai_key_rejected`) | Anthropic answered 401 or 403. Test the key with `curl` (below); check whether a workspace key overrides the installation's key; check the model is available to the key. |
| "…rate limiting requests…" (`ai_busy`) | Anthropic answered 429. Wait, or raise the key's limits. |
| "…could not be reached" (`ai_unreachable`) | No outbound HTTPS to `api.anthropic.com` from the `app` container. |
| "…declined this request" (`ai_declined`) | The model refused; do it by hand. |

```bash
# Test a key directly, outside Yadeit
curl https://api.anthropic.com/v1/messages \
  -H "x-api-key: $ANTHROPIC_API_KEY" -H "anthropic-version: 2023-06-01" -H "content-type: application/json" \
  -d '{"model":"claude-haiku-5-5","max_tokens":16,"messages":[{"role":"user","content":"hi"}]}'
```

401 means the key is wrong or revoked; 403 means the key's account has no
access to that model or workspace.

> **Note.** Using AI sends the text involved to Anthropic. That is why it is off
> per workspace until an admin switches it on.

## Paid plans (Stripe)

**Set:**

```bash
PLAN_LIMITS=true
STRIPE_KEY=pk_live_…
STRIPE_SECRET=sk_live_…
STRIPE_WEBHOOK_SECRET=whsec_…
STRIPE_PRICE_TEAM=price_…        # a recurring price: one per workspace, not per seat
```

In Stripe, add a webhook endpoint at `https://<your address>/stripe/webhook`
for the `customer.subscription.created`, `customer.subscription.updated` and
`customer.subscription.deleted` events. Without `STRIPE_WEBHOOK_SECRET` that
address refuses every request.

**Then:** owners see **Move to Team** and **Manage billing** under
**Settings → Plan**. The workspace's plan follows the subscription as Stripe
reports it. Operators can also set a plan by hand in the Operator area, for
example for customers who pay by invoice. A workspace with a running paid
plan cannot be deleted until the plan is cancelled.

The limits themselves are in [Limits and plans](limits.md).

## Realtime updates

Open browsers refresh when something changes.

**Set** (generate the id, key and secret with `openssl rand -hex 16`):

```bash
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=…
REVERB_APP_KEY=…
REVERB_APP_SECRET=…
REVERB_HOST=reverb
REVERB_PORT=8080
REVERB_SCHEME=http
VITE_REVERB_APP_KEY=<same as REVERB_APP_KEY>
VITE_REVERB_HOST=yadeit.example.com
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

Then rebuild and start with the `realtime` profile:

```bash
dc --profile realtime up -d --build
```

**Check:** open the same board in two browsers and move an item in one; the
other updates within a second. In the browser's developer tools, a WebSocket
to `/app/<key>` stays open. If your proxy does not pass WebSocket upgrades,
see [HTTPS and proxies](../install/https.md).

## Apps connecting over OAuth (MCP)

Nothing to set in Docker: signing keys are created on first start and kept in
the `storage` volume (and in the backups). On a host without Docker, run
`php artisan passport:keys` once, or set `PASSPORT_PRIVATE_KEY` and
`PASSPORT_PUBLIC_KEY`.

**Check:** `curl https://<address>/.well-known/oauth-authorization-server`
returns JSON. Without keys, the OAuth addresses answer 404 and `/mcp` accepts
agent tokens only.

Allowed redirect domains and custom schemes for desktop clients are in
`backend/config/mcp.php` (`redirect_domains` defaults to `*`).

> **Warning.** The OAuth keys are what make issued tokens valid. Replacing them
> disconnects every connected app; losing them without a backup does the same.

## Webhooks

Workspace admins add webhooks under **Settings**. The operator sets nothing,
but the **worker must run**, and the `app` container needs outbound HTTPS.
Targets must be public `https` addresses unless
`WEBHOOKS_ALLOW_PRIVATE_TARGETS=true`. Retries and automatic disabling are
described in [Background work](../intro/background-work.md#webhook-retries).

## Traffic lab

Simulated teams that use the installation like real ones, to measure load and
check whole flows. It creates real workspaces and accounts, marks them as
simulated, and removes them after `SIMULATOR_RETENTION_DAYS`.

> **Warning.** A run slows the service down for real users. Prefer a copy of the
> installation. On `APP_ENV=production` it needs both
> `SIMULATOR_ENABLED=true` and `SIMULATOR_ALLOW_PRODUCTION=true`.

Locally: `make sim-up`, then **Operator area → Traffic lab**, or
`make sim-smoke` for a short pass/fail check. Everything else is in
[docs/traffic-lab.md](https://github.com/kKEo/yadeit/blob/master/docs/traffic-lab.md).
