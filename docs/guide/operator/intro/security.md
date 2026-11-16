# Security model

Who can sign in, how, what each role and token may do, and what the
installation records about it. Read this before you hand out the operator
role or open an installation to the public.

## Ways in

| Caller | Credential | Accepted at |
|---|---|---|
| A person in the browser | Session cookie (Sanctum stateful API), CSRF-protected | the web app and `/api/v1` |
| A script or an agent | `Authorization: Bearer <personal access token>` | `/api/v1` and `/mcp` |
| An app connected over OAuth | Passport access token (OAuth 2.1, authorization code + PKCE) | `/mcp` only, never the API |
| An uptime monitor | `Authorization: Bearer <HEALTH_TOKEN>` | `/health` only |
| Stripe | Signed webhook (`STRIPE_WEBHOOK_SECRET`) | `/stripe/webhook` only |
| Anyone | a project's public form token | `/api/v1/public/feedback/{token}` |

Sign-in requires a confirmed email address. Passwords are hashed with bcrypt
(`BCRYPT_ROUNDS`, default 12).

OAuth access tokens last **one day** and renew themselves; a connection unused
for **30 days** ends. Without signing keys the OAuth addresses answer 404 and
`/mcp` accepts personal access tokens only.

## Roles

**Inside a workspace:** `owner`, `admin`, `member`, `guest`. Owners also
delete the workspace and change its plan; admins manage people, settings, AI,
webhooks, any agent, and read the workspace audit log; members create and
change work and their own agents; guests read, comment and send feedback.
Agents are never admins or owners. The user guide's
[Workspaces and people](../../user/workspaces-and-people.md) has the full table.

**On the installation:** the **operator** role (`users.is_operator`). It is
granted only from the command line:

```bash
php artisan operator:grant someone@example.com
php artisan operator:grant someone@example.com --revoke
```

Operators see every workspace and person in outline (names, counts, dates;
never content), can suspend them, change their plan, read the whole audit log
and write announcements. Keep the role to the people who run the server.

## Agents and tokens

Agents are members with `users.type = agent`, owned by a person. They act
only through tokens created in the web app: read-only or read-and-write,
optionally limited to some projects, optionally expiring. The secret is shown
once. A token limited to some projects cannot see the others at all
(`EnforceTokenScope` runs before route binding, so they are simply not found)
and cannot listen on the workspace realtime channel.

Whatever its token allows, an agent may not: delete items, attachments or
labels; complete or cancel an item (it submits for review and a person
closes it); choose an assignee or hand work to another agent; create or
change projects; manage members, agents, tokens or webhooks.

## Rate limits

Defined in `backend/app/Providers/AppServiceProvider.php`:

| Limiter | Limit | Counted per |
|---|---|---|
| `api` | 300 requests a minute | access token, or signed-in person, or caller address |
| `mcp` | 120 requests a minute | access token, or caller address |
| `auth` (sign-in, sign-up, reset) | 10 a minute per address and 20 a minute per email | address and email, both |
| `public-feedback` | 5 a minute | caller address |
| `/health`, `/llms.txt`, `/docs/*` | 60 a minute | caller address |

"Caller address" is only right when `TRUSTED_PROXIES` is set behind a proxy;
otherwise every request appears to come from the proxy and all callers share
one allowance. See [HTTPS and proxies](../install/https.md).

## Suspension

Suspending a workspace closes it to its members and agents. Suspending a
person signs them out, revokes their tokens and stops their agents. Nothing
is deleted; lifting the suspension restores access.

## The audit log

`audit_events` records who changed access, sharing and settings, who deleted
what, and every sign-in and failed sign-in, with the caller's address. Which
requests are recorded is one list in
`backend/app/Http/Middleware/AuditTrail.php`. Secrets and long texts never
enter the log. Workspace admins read their workspace's log under
**Settings**; operators read all of it in the Operator area
(`GET /api/v1/admin/audit`).

## What leaves the installation

- **Email** through your SMTP server.
- **Webhooks** to the public HTTPS addresses workspace admins configure, signed with HMAC-SHA256.
- **Built-in AI**: the text involved in a request goes to Anthropic, only for workspaces whose admin switched AI on.
- **Stripe**, only when paid plans are configured.

Nothing else calls out. There is no telemetry.
