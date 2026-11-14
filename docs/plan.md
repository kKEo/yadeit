# Yadeit reincarnation: a simple, agent-friendly project tool in the direction of Plane

## Context

Yadeit is a 2012 Yii 1.1 ticket tracker that can no longer run (framework outside the repo, PHP 5 era code, no tests, serious security holes). Instead of modernising it one to one, it is reborn as a public product in the direction of [Plane](https://plane.so): simple, pleasant project management that also tracks UX and feedback work, with AI agents as first-class participants.

Decisions made with the user:

- Rewrite on Laravel with a Vue 3 SPA; the Yii code is a behaviour reference only and is removed from the working tree.
- Public product / SaaS: self-service sign-up, workspaces, invitations, later plans and billing.
- Keep the old non-ticket features: docs (the old articles), event log, mailing queue with broadcasts.
- Empty database at start; no import of old data.
- Agents are first-class members, and the app has built-in AI features.
- "Simple and pleasant UX" and "tracking UX work" are both goals.

Scope warning: this is a multi-month product, not a port. The work is ordered so that a usable tracker exists after Phase 4 and the agent story after Phase 5; everything later is additive.

## Findings: state-of-the-art analysis

### What Plane gets right, and where it leaves room

| Finding | Consequence for Yadeit |
|---|---|
| Hierarchy Workspace → Project → Work item, with keys like `WEB-143` | Copy as is |
| Custom state names mapped to 5 fixed groups (backlog, unstarted, started, completed, cancelled) plus a triage state; all logic hangs off the group | Copy; the best single idea |
| List, board, table, calendar, timeline as layouts over one filter/group/sort engine; saved views | Ship list and board first on one engine |
| Cmd+K palette, single-key shortcuts, side "peek" panel, quick add | Copy; cheap and gives the fast feel |
| Intake with accept / decline / duplicate / snooze | Copy and extend into feedback tracking |
| API: tokens, cursor pagination, `fields`/`expand`, OpenAPI, llms.txt, signed webhooks with diffs | Copy the conventions |
| MCP server went from 177 one-operation tools to about 30 | Start with about 13 workflow-level tools |
| Self-hosting needs about 13 containers and 4-8 GB RAM | Differentiator: one app, one database, two worker processes |
| Main user complaint is open-core paywalls (SSO, AI, agents, epics, custom types are paid; community edition lags) and concept churn | Differentiator: small fixed concept set, nothing essential withheld |
| Code is AGPL-3.0 | Re-implement ideas only; copy no code, assets or text |

### UX lessons from Linear, GitHub Projects, Shortcut, Basecamp, Fizzy, Jira

- Opinionated defaults beat configuration: preset workflow, fixed priority scale, title is the only required field.
- One keyboard grammar everywhere (`C` create, `S` status, `P` priority, `A` assign, `G`+letter to navigate, `J/K` to move, `?` for help).
- A view is a saved query plus a layout; filter chips, URL parameters and saved views are the same data.
- Optimistic updates with rollback and realtime cache patching give most of the "instant" feel; a local-first sync engine is not worth its cost here.
- Triage keeps outside input out of the backlog; one unified inbox with snooze handles notifications.
- Cycles should run themselves and roll unfinished work forward.
- Stale items should decay to backlog automatically (Fizzy).
- Deliberately left out: custom fields, per-project workflow schemes, story points, time tracking, Gantt, initiatives/epics/teamspaces, multiple assignees, automation builder, swimlanes, burndown, chat.

### How trackers became agent-friendly (Linear, GitHub, Atlassian, Plane, Beads, Backlog.md)

- Agents are their own non-billable members with their own credentials; a human stays accountable (Linear's assignee vs delegate).
- Each delegation is a run with typed activities (thought, action, question, response, error) and an explicit "awaiting input" state.
- Agent-native tools add dependency graphs, a "ready work" query, atomic claim, and acceptance criteria and plan stored on the item.
- MCP guidance: few workflow tools, human-readable ids, compact responses, cursor pagination, actionable error results, read-only/destructive annotations, idempotent writes. Bloated servers cost 17-24k tokens just to connect.
- Security is the hard part: issue text is untrusted input (GitHub's 2025 "toxic agent flow"). Least-privilege per-agent tokens, no destructive tools, human approval for "done", full audit.
- The current MCP revision (2026-07-28) is stateless with OAuth 2.1; Laravel has an official `laravel/mcp` package. Whether it already implements that revision could not be confirmed and is checked in Phase 5.

## Overview: product definition

**Concepts (the whole list):** workspace, member (human or agent), project, work item, state, priority, label, comment, cycle, view, doc, feedback, inbox.

| Area | What it is |
|---|---|
| Work item | Key (`WEB-143`), title, Markdown description, state, priority (none/low/medium/high/urgent), one assignee, optional agent delegate, labels, kind (task, bug, feature, design, research; fixed list, no per-kind workflow), due date, parent item, acceptance-criteria checklist, links (PR, branch, design file, URL), attachments, `blocks`/`relates`/`duplicate` relations, activity history |
| UX and feedback work | Feedback inbox per project fed by an in-app form, a public form and agents; triage actions accept, decline, merge as duplicate, snooze; many feedback entries link to one work item and count as demand; design links and image attachments preview inline |
| Docs | Project and workspace pages in Markdown with draft/published state and optional public link (successor of the old articles) |
| Agents | Agent members owned by a human, scoped tokens, ready-work query, claim, runs with activity timeline, "needs input" state, MCP server, REST API with OpenAPI, signed webhooks, `llms.txt` |
| Built-in AI | Draft a work item from one sentence, summarise a thread, suggest labels/priority/duplicates in triage, split an item into sub-items; per-workspace switch and usage metering |
| Operator | Instance admin area: workspaces, users, audit event log, outgoing mail queue (waiting/sent, details, send now, delete), announcement broadcasts to users |

## Overview: architecture

```
yadeit/
  backend/    Laravel 13, PHP 8.5; JSON API /api/v1; MCP endpoint; queue, scheduler, Reverb
  frontend/   Vue 3 + TypeScript + Vite; shadcn-vue, Pinia + Pinia Colada, Tiptap, vue-i18n
  compose.yaml   app, PostgreSQL, queue worker, Reverb
```

- **Database:** PostgreSQL in all environments (full-text search now, vector search possible later). Queue and cache on the database driver to keep the footprint small.
- **Tenancy:** every tenant table carries `workspace_id`; a global scope plus policies enforce isolation; roles are owner, admin, member, guest.
- **Auth:** Sanctum cookie sessions for the SPA; Sanctum tokens with abilities (project scope, read/write, expiry) for API clients and agents; OAuth via Passport for remote MCP clients in a later step.
- **API conventions (set once in Phase 3):** human keys accepted everywhere, cursor pagination, `fields`/`expand`, `Idempotency-Key` on creates, version check on updates, rate-limit headers, uniform error body, generated OpenAPI.
- **Content:** Markdown stored as Markdown (readable by API, agents and humans), edited with Tiptap, rendered sanitised.
- **Realtime:** Laravel Reverb broadcasting per project; the SPA patches its query cache.
- **AI:** one `AiService` behind an interface, defaulting to Claude models; provider key per instance or per workspace.
- **Front-end start:** `frontend-scaffold:scaffold-frontend`, then its API client is switched from bearer tokens to cookie sessions.
- **Languages:** English and Polish.

## Notes on the format

Written for the plan-progress mod: every `Phase N` heading is one phase, and every top-level checkbox under it is one step (`N.M`). Indented bullets are details of the step above and are not counted. Each phase ends with its own verification step.

## Phase 1: Foundation

Goal: an empty, tested, runnable skeleton of both applications.

- [x] Tag the last Yii commit and remove the legacy code
  - Tag `legacy-yii`; delete Yii code, assets and the committed SQLite database from the working tree
- [x] Create the Laravel backend skeleton
  - `backend/` with Sanctum, Pest, Pint, Larastan; `/api/v1` routing and uniform JSON errors
- [x] Add Docker Compose with PostgreSQL
- [x] Scaffold the Vue frontend
  - `frontend-scaffold:scaffold-frontend`; add Pinia Colada, vue-i18n (en, pl), Vitest; dev proxy to Laravel
- [x] Set up CI and the README
  - GitHub Actions: lint, backend tests, type check, frontend tests, build
- [ ] Verify the skeleton: both test suites and the build pass, CI is green

## Phase 2: Accounts and workspaces

Goal: people can sign up and work together in isolated workspaces.

- [x] Build registration, email verification, login and password reset
- [x] Build workspaces, memberships and roles
  - Roles: owner, admin, member, guest; workspace switcher
- [x] Build email invitations
- [x] Enforce tenant scoping with policies
  - `workspace_id` global scope; isolation tests for every resource
- [x] Build onboarding with a seeded sample project
  - First workspace wizard, helpful empty states
- [ ] Verify accounts: sign-up to invited teammate works in the browser, cross-workspace access returns 404

## Phase 3: Work item core

Goal: the data model and API that everything else builds on.

- [x] Build projects, states and labels
  - Project key prefix; states in the five fixed groups plus triage; preset workflow
- [x] Build work items
  - Sequence keys (`WEB-143`), priority, assignee, kind, due date, parent, checklist, links, relations
- [x] Build comments, activity history and subscriptions
  - Activity stores field diffs
- [x] Build attachments on private storage
  - Authorised download, size and type limits, image previews
- [x] Apply the API conventions and generate OpenAPI
  - Cursor pagination, `fields`/`expand`, idempotency keys, version checks, rate-limit headers
- [x] Verify the core API: feature tests cover every endpoint and the OpenAPI document validates

## Phase 4: Fast, friendly interface

Goal: the experience that makes the tool pleasant; first usable release.

- [x] Build list and board layouts on one engine
  - Group, sort, drag to change state
- [x] Build filters and saved views
  - Filter chips ↔ URL ↔ saved view; personal and shared; built-in views (all, assigned to me, created by me, subscribed)
- [x] Build the peek panel and inline property editing
  - One reusable property picker shared by mouse, keyboard and palette
- [x] Build the command palette, shortcuts and quick capture
  - `Cmd+K`, single-key grammar, `?` overlay, "create more"
- [x] Add optimistic updates and realtime sync
  - Pinia Colada mutations with rollback; Reverb events patch the cache
- [x] Build the inbox and email notifications
  - Unread, snooze, mark all read
- [ ] Verify the interface: create, move, filter and save a view using only the keyboard, with two browsers staying in sync

## Phase 5: Agent platform

Goal: agents become real team members.

- [x] Build agent members and scoped tokens
  - Agent owned by a human; token abilities by project and read/write; expiry; management screen
- [x] Build delegation, ready-work query and atomic claim
  - Delegate field; `needs input` and `in review` handling
- [x] Build agent runs with an activity timeline
  - Typed activities: thought, action, question, response, error
- [x] Build the MCP server
  - Check `laravel/mcp` against the current MCP revision first
  - Tools: `whoami`, `list_projects`, `search_items`, `get_item`, `ready_items`, `claim_item`, `create_item`, `update_item`, `add_comment`, `set_acceptance`, `link`, `submit_for_review`, `list_activity`
  - Agent-workflow guide as a resource
- [x] Build signed webhooks with a delivery log
  - HMAC signature, retries, auto-disable after repeated failures
- [x] Publish `llms.txt` and Markdown API docs
- [x] Enforce the agent guardrails
  - No delete, membership change or "done" for agents; user content marked untrusted in tool output; per-token rate limits; all actions attributed in the audit trail
- [x] Verify agents: Claude Code claims an item over MCP, posts a plan, asks a question, links a PR and submits for review; forbidden actions are refused; tool definitions stay under 5k tokens

## Phase 6: Planning and docs

Goal: time-boxed planning and written knowledge next to the work.

- [ ] Build cycles with automatic schedule and rollover
- [ ] Build the sub-item view and blocked indicators
- [ ] Build docs
  - Markdown pages per project and workspace, draft/published, optional public link
- [ ] Build full-text search
  - Items, comments and docs
- [ ] Add the stale-item decay setting
- [ ] Verify planning: a cycle rolls unfinished items over, a published doc opens signed out, search finds all three content types

## Phase 7: Feedback and UX work

Goal: collect outside input and track design and research work.

- [ ] Build the feedback inbox with triage actions
  - Accept, decline, merge as duplicate, snooze; keyboard shortcuts
- [ ] Build in-app and public feedback forms
  - Spam protection on the public form
- [ ] Link feedback to work items with demand counts
  - Sort and filter by demand
- [ ] Add design-work support
  - Kind filter, design link previews, image galleries
- [ ] Verify feedback: a public submission is triaged into a work item and raises its demand count

## Phase 8: Built-in AI

Goal: helpful AI inside the app, always as confirmable suggestions.

- [ ] Build the AI service, provider settings and usage metering
  - Per-workspace switch and limits
- [ ] Add drafting a work item from one sentence
- [ ] Add splitting an item into sub-items
- [ ] Add thread and doc summaries
- [ ] Add triage suggestions
  - Duplicates, labels, priority
- [ ] Verify AI: features work with a test key, and no provider call is made when the workspace switch is off

## Phase 9: Operator and SaaS

Goal: run it as a public service.

- [ ] Build the instance admin area
  - Workspaces, users, suspension
- [ ] Build the audit event log
  - Views for workspace admins and for the operator
- [ ] Build the outgoing mail log and queue screen
  - Waiting/sent, details, send now, delete
- [ ] Build announcement broadcasts
  - Recipient filter, preview, confirmation
- [ ] Add plans, limits and Stripe billing
  - Laravel Cashier; agent seats free
- [ ] Add the landing page, legal pages, data export and account deletion
- [ ] Verify operations: a broadcast reaches the mail log, a plan limit blocks correctly, an export downloads

## Phase 10: Hardening and launch

Goal: safe and ready to deploy.

- [ ] Run a security review
  - Tenancy, tokens, uploads, Markdown rendering, prompt-injection paths
- [ ] Add OAuth for remote MCP clients
  - Passport with PKCE and audience validation
- [ ] Do a performance pass
  - Query counts, list virtualisation
- [ ] Set up backups and monitoring
- [ ] Write the production deployment and its docs
- [ ] Verify launch readiness: a fresh production-like deployment passes the full walk-through

## Notes: outside the code

- The old SMTP password and password salt are in the public git history on GitHub; change the SMTP password. Rewriting history is optional and not planned here.
- Choose a licence for the new code before the repository gets outside contributors.
