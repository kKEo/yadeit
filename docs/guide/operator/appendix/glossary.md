# Glossary

The words this guide uses, with what they mean in Yadeit. Words people use in
the web app are also in the user guide's [glossary](../../user/glossary.md).

**Agent.** A member of a workspace that is software, not a person
(`users.type = agent`). It acts through access tokens or a connected app and
is owned by a person.

**Announcement.** An email an operator sends to many people from the Operator
area. Sent in batches by the scheduler.

**APP_KEY.** The secret that encrypts sessions, workspace AI keys and webhook
secrets. Losing it makes those unreadable.

**Audit log.** The record of changes to access, sharing and settings,
deletions and sign-ins (`audit_events`).

**Connected app.** An MCP client that signed in over OAuth and acts as one of
the person's agents. Its tokens work at `/mcp` only.

**Cycle.** A fixed period (1 to 8 weeks) of planned work in a project.
Advanced daily by `cycles:advance`.

**Decay.** Returning open items that nobody touched for a project's chosen
number of days to the backlog (`items:decay`).

**Entrypoint.** `docker/prod/entrypoint.sh`: what each container runs before
its command (checks, migrations on `app`, caches).

**Health report.** The seven checks of `App\Support\Health`, read in the
Operator area, at `/health` and with `app:health`.

**Heartbeat.** A timestamp the scheduler writes every minute; its age tells
the health report whether the scheduler runs. The backup job has its own
heartbeat file, `last-success`.

**MCP.** Model Context Protocol: how AI agents and assistants call tools. Yadeit
serves it at `/mcp`.

**Operator.** A person who runs the installation (`users.is_operator`).
Granted only with `php artisan operator:grant`.

**Plan.** What a workspace may use (people, projects, AI tokens), from
`backend/config/plans.php`. Enforced only with `PLAN_LIMITS=true`.

**Queue.** Work done after a request, by the worker: emails, webhooks,
realtime signals. Stored in the `jobs` table.

**Realtime.** Signals with identifiers only, sent over WebSocket by Reverb, that
tell open browsers to re-read.

**Reverb.** Laravel's WebSocket server, the optional `reverb` service.

**Scheduler.** The process that runs timed jobs (`schedule:work`).

**Suspension.** An operator closing a workspace or an account without
deleting anything.

**Token scope.** What an access token may do: read-only or read-and-write,
optionally limited to some projects, optionally expiring.

**Traffic lab.** Simulated teams that use an installation to measure it. Off
unless `SIMULATOR_ENABLED=true`.

**Webhook.** A signed HTTP `POST` Yadeit sends to a workspace's receiver when
items change.

**Worker.** The process that runs the queue (`queue:work`).

**Workspace.** The unit of tenancy: people, projects, items and settings
belong to one workspace.
