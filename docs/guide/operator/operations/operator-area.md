# The Operator area

The part of the web app for the people who run the installation, at `/admin`
(**Operator area** in the account menu). It is visible only to operators.
Its API lives under `/api/v1/admin` and checks the role on every request
(`EnsureOperator`).

## Becoming an operator

```bash
dc exec app php artisan operator:grant you@example.com           # grant
dc exec app php artisan operator:grant someone@example.com --revoke
```

There is no way to grant the role from the web app, so a compromised account
cannot hand it out. Keep at least two operators, and remember that the last
operator cannot delete their own account.

## What is there

| Screen | What you can do |
|---|---|
| **Health** | The [health report](../monitoring/health.md). |
| **Workspaces** | Every workspace in outline: name, member and item counts, plan, dates. Never content. Search, filter suspended or simulated ones, suspend or lift a suspension, set the plan by hand. |
| **People** | Every human account in outline (agents are listed with their workspace, not here). Search, filter, suspend or lift a suspension. |
| **Announcements** | Write Markdown, choose an audience, preview the recipient count, send. |
| **Email** | Every email the installation sent, with status. Resend or delete waiting and failed announcement messages. |
| **Audit log** | Everything the audit trail recorded, across workspaces, with addresses. |
| **Traffic lab** | Only when `SIMULATOR_ENABLED=true`. |

## Suspension

Suspending is the reversible answer to abuse, a dispute or a security
incident. Nothing is deleted.

- **A workspace:** closed to its members and agents; its realtime channel refuses listeners.
- **A person:** signed out everywhere; their access tokens are revoked; their agents stop; they cannot sign in.

Deleting data is left to the people it belongs to (account and workspace
deletion in the web app), so an operator never destroys a team's work by a
click.

## Plans by hand

On an installation with `PLAN_LIMITS=true`, an operator can put a workspace
on any plan from `backend/config/plans.php`, for example for a customer who
pays by invoice. A workspace with a Stripe subscription follows Stripe; a
change by hand is overwritten by the next subscription event.

## Announcements

1. Write the text in Markdown.
2. Choose the audience: everyone, workspace admins and owners, or owners only; optionally only workspaces on one plan.
3. Tick **Service notice** only for things people must know (an outage, a change of terms). It also reaches people who turned email notifications off.
4. Preview: the screen shows how many people it reaches.
5. Send. If the audience changed since the preview, the send is refused (`409 recipients_changed`) and nothing goes out; preview again.

Agents, suspended accounts, unconfirmed addresses and simulated people never
receive announcements. Sending happens in batches of 100 a minute through the
scheduler (`mail:send-waiting`); a 5,000-person announcement takes about 50
minutes.

## Email record

Every email is recorded: recipient, kind, subject, time and status. The text
of sign-in, reset and notification emails is **not** kept, because those carry
links that open an account. Sent records are deleted after 90 days.

## Audit log

Recorded: changes to access (roles, invitations, tokens, agents), sharing
(public links, public forms), settings and webhooks, deletions, and every
sign-in and failed sign-in, with the caller's address. Never recorded:
secrets and long texts.

The list of recorded requests is in
`backend/app/Http/Middleware/AuditTrail.php`; add a route there to record it.
