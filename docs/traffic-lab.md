# Traffic lab: a built-in simulator of organic team traffic

## Context

Yadeit has functional tests and a query-count test (`backend/tests/Feature/PerformanceTest.php`), but nothing that shows how the whole installation behaves when many teams use it at once, and nothing that walks complete flows (sign-up to invited teammate, item from creation to done, feedback to work item, agent run) continuously. The app also has no metrics: no Pulse, no request log, no slow-query log.

The goal is a built-in "Traffic lab": an operator starts a run from the admin panel, the coordinator spawns a chosen number of teams (1-10 members each, in different roles), and those members use the real HTTP API like people do, at 1x, 2x, 5x, 10x, 50x or 100x organic speed. The run reports performance (latency, errors, queue lag) and flow health (which journeys completed, which broke, and where).

Decisions taken with the user: AI is used in a hybrid way; the simulator is off by default and guarded in production; the speed-up compresses user behaviour only, not the app clock; the runner language should be chosen for ease of extension and maintenance.

The earlier rebuild plan stays in `docs/plan.md`. This plan is copied to `docs/traffic-lab.md` in step 1.1.

## Decisions, with pros and cons

### Where the simulated users run: a TypeScript runner, coordinated by the Laravel app

| Option | Pros | Cons |
|---|---|---|
| **TypeScript runner in `simulator/` (chosen)** | A persona is a plain `async` function, so adding a behaviour is a few lines. Thousands of idle users cost nothing (event loop). Request and response types are imported from `frontend/src/api/types.ts`, so API changes break the simulator at compile time instead of silently. `pusher-js` (already used by the SPA) covers Reverb. The team already maintains TypeScript and Vitest. Real browsers (Playwright) can be added later in the same runner. | A second runtime and a second small image. The runner must stay in step with what the SPA really requests. |
| PHP runner in the backend | One image, one language. | Concurrency needs fibers or an extra async library; persona code becomes harder to read; no type sharing with the SPA; the generator would compete with the app for the same PHP workers. |
| Go or k6 | Highest and most accurate load per machine. | Stateful team stories, role behaviour and AI members are awkward; scenarios live in a third language nobody on the project uses; hardest to manage from the admin panel. |
| Python (Locust) | Easy scripting. | Third language, no shared types, own web UI that duplicates the admin panel. |

The split: the **coordinator** (configuration, provisioning, safety, metrics store, admin API) is part of the Laravel app; the **runner** is a stateless Node process that talks to the installation only over HTTP. That keeps load generation out of the app's own workers and lets a runner sit on another machine, which matters because a generator on the same host distorts the numbers.

### How AI is used: hybrid

| Layer | What it does | Pros | Cons | Default |
|---|---|---|---|---|
| Scripted personas | Weighted behaviour per role, seeded random numbers | Repeatable, free, follows 100x | Only walks paths someone scripted | On, produces the load |
| AI content packs | Claude writes a themed backlog once (items, comments, docs, feedback); runs replay it | Realistic text sizes and search terms, paid once, still repeatable | Static | A hand-written pack ships in the repo; AI packs optional |
| AI director | Every simulated day, plans each team's storyline as structured intents the scripted engine executes; stored so a run can be replayed | Coherent multi-person stories for a few calls per team | Needs a key; a new storyline differs from the last unless replayed | Off, per scenario |
| AI explorer members | An LLM picks every next action from the action catalogue and reports where it got stuck | Finds confusing or broken flows nobody scripted | Seconds per step, cost per step, not repeatable | 0 per team, max 3 per run, speed capped at 5x |

All AI calls go through the coordinator (`AiProvider`), so the runner never holds the key and one token budget per run stops everything when it is spent.

### Other choices

- **Speed-up compresses behaviour only.** Think times and the working-day rhythm shrink; the app clock stays real. Cycle rollover and stale decay are triggered by the coordinator calling the existing `cycles:advance` and `items:decay` logic for simulated workspaces at simulated midnight. Pro: nothing in the app has to lie about time. Con: timestamps of a 100x run are minutes apart, not days.
- **Actions are scheduled by intended time, not by "previous response + pause".** When the server slows down, a naive simulator slows down too and hides the problem. The runner records how late each action started; a rising lag marks the result as "runner saturated" instead of reporting flattering numbers.
- **Simulated data is flagged, not separated.** `sim_run_id` on users and workspaces. Pro: the real code paths, limits and tenancy scope are exercised. Con: every operator statistic, mailing and billing path must exclude flagged rows (listed in Phase 1).
- **Live admin screens poll every 2 seconds** instead of using Reverb, because realtime is one of the things under test and may be the part that fails.

## Overview

```
Admin panel (Vue, /admin/traffic)          Runner(s): simulator/ (Node 24, TypeScript)
        |  operator API                          |  control API (runner token): assignments, speed,
        v                                        |  mailbox, AI decisions, metric batches
Coordinator: backend/app/Simulator  <------------+
  scenarios, runs, teams, members                |  user traffic: the public API, exactly like the SPA
  provisioning, safety, AI, reports              v  (cookie session + XSRF, agent tokens, /mcp, websockets)
        |                                  The installation under test (same or another host)
        v
  sim_* tables (config, 10-second metric buckets, findings)
```

### What the exploration found that shapes the design

| Finding | Where | Consequence |
|---|---|---|
| `auth` limiter: 10/min per IP; public feedback: 5/min per IP | `backend/app/Providers/AppServiceProvider.php:62-103` | Requests carrying a valid signed simulator header are keyed per virtual user instead of per IP. Per-user limits stay as they are; hitting one is a finding. |
| A cookie session exists only when `Origin` matches `SANCTUM_STATEFUL_DOMAINS` | `config/sanctum.php:21` | The runner sends the SPA's `Origin`, the XSRF header and keeps a cookie jar per member. |
| Verification and invitation mail is sent synchronously; the mail log stores no body or link; nothing can suppress mail | `app/Listeners/LogSentMail.php`, `InvitationController.php` | A `MessageSending` listener diverts mail for simulated recipients (`@sim.invalid`) into a `sim_mailbox` table and cancels delivery. The runner reads its links from there, so sign-up and invitation run as real flows and no mail can leave. |
| Queue, cache, sessions and rate-limit counters all use the database | `config/queue.php`, `cache.php`, `session.php` | Likely the first bottleneck; the report shows queue lag and session/cache load explicitly. Metric writes are batched so the simulator adds little of its own. |
| Faker and factories are missing from the production image | `Dockerfile:24-26` | Provisioning uses `App\Actions\ProvisionWorkspace`, `App\Actions\CreateProject` and `WorkItemWriter::create`; text comes from content packs, not Faker. |
| No metrics anywhere | section 7 of the exploration | Simulator-tagged requests get a `Server-Timing` header (app time, database time, query count) that the runner aggregates; the app writes nothing per request. |
| Admin screens use plain refs, `usePagedList`, `useApiForm`, `api/operator.ts`; no chart library; no polling | `frontend/src/views/admin/*` | New screens follow the same pattern; add `uPlot` (small, loaded only with the admin chunk) and `useIntervalFn` from `@vueuse/core`. |
| The SPA's requests per screen are known | section 6 of the frontend exploration | Each simulated action replays that recipe, including what the 5-second cache would skip. |
| Agents: `ready` → `claim` → `log`/`ask` → `submit-for-review`, 14 MCP tools at `/mcp` | `routes/api.php:202-221`, `app/Mcp/` | Agent members are a persona that uses real agent tokens over MCP. |
| Webhooks refuse private addresses | `app/Support/WebhookTarget.php` | Simulated workspaces may only register a built-in sink endpoint, allowed explicitly. |

### Teams, roles and behaviour

- One team is one workspace. A template per size decides the mix: 1 = owner; 2-4 = owner plus members; 5-10 = owner, admin, members, one or two guests, zero to two agents. Templates are editable.
- Personas: **lead** (owner/admin: projects, cycles, invitations, triage, assigning), **developer** (member: picks work, moves states, comments, links, attachments), **designer** (member: design items, images, docs), **client** (guest: reads, comments, sends feedback), **agent** (agent token over MCP), **visitor** (signed out: landing page, public doc link, public feedback form).
- Each persona is a weighted set of actions with think-time distributions and a daily rhythm. Read actions dominate, roughly ten to one. Defaults live in `simulator/src/personas/`; the admin panel overrides weights, intensity and working hours per scenario.
- Day modes: realistic (nights are quiet), working hours only, continuous.
- Starting data: empty, small, medium or large backlog per workspace, seeded from the content pack, because list and search speed depend on volume. A chosen share of teams signs up through the real flow; the rest are provisioned directly to reach steady state quickly.

### Flows and checks

Each flow records started, completed, failed, the failing step and its duration. Checks run inside the flows:

| Flow | Checks |
|---|---|
| Sign-up → verify email → create workspace → invite → accept | Link arrives in the mailbox; invited member sees the workspace |
| Item: create → started → in review → done | State read back equals state written; 412 on stale version is retried correctly |
| Comment → teammate's inbox | Inbox entry appears within a limit (queue lag as users feel it) |
| Change → teammate's websocket event | Event arrives within a limit (when Reverb is on) |
| Create → search | New item is found within a limit |
| Feedback (public form or in-app) → triage → item | Demand count rises |
| Doc: draft → publish → public link | Signed-out read works; unpublish closes it |
| Agent: ready → claim → log → ask → answer → submit for review | Second claim gets 409; agent cannot close the item |
| Isolation canary | A member asking for another team's item gets 404; a guest write gets 403 |

A failed check becomes a finding with the request, the response and the member who saw it.

### Safety

- `SIMULATOR_ENABLED=false` by default: routes answer 404 and the admin tab is hidden. With `APP_ENV=production` a second flag `SIMULATOR_ALLOW_PRODUCTION=true` is required, the screen shows a warning, and hard caps from config apply (teams, speed, duration).
- Operator only (`operator` middleware). Runners authenticate with a per-run token; the header that relaxes IP limits is an HMAC that is valid only while that run is active.
- Simulated accounts: `@sim.invalid` addresses, mail diverted, excluded from announcements, operator counts and lists (shown under a filter), never billed, plan limits not applied, built-in AI help answered by a canned provider unless the scenario asks for the real one, webhooks only to the sink.
- Automatic stop: maximum duration, error rate above a threshold for a minute, runner heartbeat lost, AI budget spent. Stop button always available.
- Purge per run removes workspaces, users, attachments, mailbox and metrics; a scheduled prune removes runs older than the retention period.

### Admin panel: a "Traffic lab" tab in the operator area

- **Live run**: status, simulated clock, speed selector (1x-100x, takes effect within 2 seconds), add or remove teams, pause, stop; charts of requests per second, p50/p95/p99, errors, queue lag, runner lag; team grid showing each member's persona, kind (scripted, AI, agent) and current action; flow table; findings feed; runner list.
- **Scenarios**: teams, size range, role templates, persona weights and intensity, day mode, speed, duration, ramp-up, share of real sign-ups, starting data, which flow families are on, AI settings (pack, director, explorers, model, token budget), pass/fail thresholds, random seed, cleanup rule.
- **Runs**: history with verdict, report page, comparison of two runs (per-endpoint p95 change), JSON export, purge.
- **Content packs**: built-in pack, generate with AI from a theme, preview.

## Notes on the format

Written for the plan-progress mod: each `Phase N` heading is a phase and each top-level checkbox a step. Every phase ends with a verification step. Work stops after each phase for review, as in the rebuild.

## Phase 1: Coordinator foundation and safety

Goal: the app can hold simulated teams safely, and nothing simulated can leak out.

- [ ] Copy this plan to `docs/traffic-lab.md` and add `config/simulator.php`
  - Flags, caps, mail domain, retention, AI defaults; documented in `.env.example` and `.env.production.example`
- [ ] Add the data model
  - Migrations: `sim_scenarios`, `sim_runs`, `sim_teams`, `sim_members`, `sim_runners`, `sim_mailbox`, `sim_metric_buckets`, `sim_findings`, `sim_content_packs`, `sim_storylines`; `sim_run_id` on `users` and `workspaces`
  - Models and enums under `backend/app/Simulator/`
- [ ] Mark and isolate simulated traffic
  - Middleware `SimTraffic` verifies the signed header, tags the request and adds `Server-Timing`
  - Limiters in `AppServiceProvider.php:62-103` take their key from the virtual user when the tag is present
  - `MessageSending` listener diverts mail into `sim_mailbox`
- [ ] Exclude simulated rows everywhere they would do harm
  - `OperatorController` overview and lists, `Outbox` audiences, `Plans::ensureRoom`, `Billing`, `WebhookTarget` (sink only), `AiService` (canned provider)
- [ ] Build provisioning and purge
  - `ProvisionSimTeam` on top of `ProvisionWorkspace`, `CreateProject`, `WorkItemWriter::create`; backlog seeding from a pack; `PurgeSimRun`; scheduled prune
  - Built-in pack in `backend/resources/simulator/packs/default.json`
- [ ] Verify the foundation: Pest tests prove that routes are 404 when disabled, production needs the second flag, simulated users never receive mail or appear in announcements and counts, a forged header does not relax limits, and purge leaves no rows or files

## Phase 2: Runner and scripted personas

Goal: scripted teams produce organic traffic at any speed from a terminal.

- [ ] Create the `simulator/` package
  - Node 24, TypeScript, Vitest; types imported from `frontend/src/api/types.ts`; own image stage in `Dockerfile`; compose service under profile `sim`
- [ ] Build the control API and the runner loop
  - Backend `sim/runner/*`: register, poll (assignments, speed, status), mailbox, metric and finding batches
  - Runner: heartbeat, leases on teams, graceful stop
- [ ] Build the session client and the action catalogue
  - Cookie jar, `Origin`, XSRF, `Idempotency-Key`, `expected_version`; one action per SPA recipe (open items, open item, create, change state, comment, upload, inbox, docs, feedback, search, members, settings)
- [ ] Build the scheduler and time model
  - Simulated clock, speed changes while running, intended-time scheduling with lag, day modes, ramp-up, seeded randomness
- [ ] Build the personas and team templates
  - Lead, developer, designer, client, visitor; real sign-up and invitation flow through the mailbox
- [ ] Add Makefile targets
  - `sim-up`, `sim-down`, `sim-logs`, `sim-smoke` (short headless run with thresholds, non-zero exit on failure), listed by `make help`
- [ ] Verify the runner: `make sim-smoke` against the local Docker stack runs 3 teams for two minutes at 10x with no failed flows; the same seed gives the same action sequence; unit tests cover the scheduler and the catalogue

## Phase 3: Metrics, flows and reports

Goal: a run produces numbers and findings that can be trusted and compared.

- [ ] Aggregate metrics in the runner and store them in buckets
  - Mergeable latency histograms per action and endpoint, status classes, bytes, server time and query count from `Server-Timing`, runner lag
  - Server samples with each batch: queue depth, oldest job age, failed jobs, session count
- [ ] Implement flows and checks
  - The table in "Flows and checks"; findings with request and response samples
- [ ] Build the run report and verdict
  - Summary, slowest endpoints, flow success, thresholds, "runner saturated" warning, comparison with a baseline run, JSON export
- [ ] Add the automatic stop rules
- [ ] Verify measurement: a deliberately slowed endpoint and a deliberately broken flow both show up in the report and fail the verdict; histogram merging is unit-tested

## Phase 4: Admin panel

Goal: everything is configured, started, watched and cleaned up from the operator area.

- [ ] Add the operator API for scenarios, runs, live control, packs and purge
  - `backend/app/Simulator/Http/`, routes in the `admin` group of `routes/api.php`
- [ ] Build the scenario and content pack screens
  - `frontend/src/views/admin/sim/`, `frontend/src/api/simulator.ts`, new tab in `layouts/AdminLayout.vue`, strings in `en.json` and `pl.json`
- [ ] Build the live run screen
  - Polling with `useIntervalFn`, `uPlot` charts in a small `SimChart.vue`, speed selector, team grid, findings feed
- [ ] Build run history, report and comparison screens
- [ ] Verify the panel: view tests in the existing style (`AdminWorkspacesView.test.ts`), and a browser walk-through that starts a run, changes speed, adds a team, stops and purges

## Phase 5: Agents, realtime and public traffic

Goal: the simulation covers the parts of the product that are not a person clicking in the SPA.

- [ ] Add the agent persona over MCP
  - Real agents and tokens created by the lead; scripted loop over the `/mcp` tools; guardrail checks
- [ ] Add websocket members
  - `pusher-js` against Reverb with `/broadcasting/auth`; delivery-time check; skipped when broadcasting is off
- [ ] Add the visitor persona and the webhook sink
  - Landing page and assets, public doc link, public feedback form; sink endpoint with signature check and delivery timing
- [ ] Trigger cycle rollover and decay at simulated midnight
- [ ] Verify coverage: one run shows agent, realtime, public and webhook flows completing, with the realtime stack profile on

## Phase 6: AI layers

Goal: more realistic content and stories, and exploratory members, inside a fixed budget.

- [ ] Add the simulator AI gateway and budget
  - Uses `AiProvider` directly with a per-run token budget and its own model setting; `AiPrompt` gains an optional model
- [ ] Add AI content packs
  - Generated from a theme with a JSON schema, validated, stored, previewable
- [ ] Add the AI director
  - Storyline per team and simulated day as structured intents; stored for replay
- [ ] Add AI explorer members
  - Decision endpoint returning the next action from the catalogue (or MCP tool for agents); speed cap; "got stuck" notes become findings
- [ ] Verify AI: with the test provider every layer works and replays identically; with a real key a small run stays inside its budget and stops when it is spent; no AI call happens when the scenario has AI off

## Phase 7: Documentation and CI

Goal: others can use and extend it.

- [ ] Document the Traffic lab
  - `docs/traffic-lab.md` (how it works, safety, how to add an action, a persona, a flow); section in `docs/deployment.md`; README pointer
- [ ] Add CI jobs
  - Type check and unit tests for `simulator/`; optional smoke run on the Docker stack
- [ ] Verify the whole: fresh `make app-up` then `make sim-smoke`, and a 100x run of 10 teams of 10 from the admin panel that ends with a report and a clean purge

## Notes: risks and limits

- A runner on the same host as the app competes for CPU; for numbers that matter, run it elsewhere. The report states where the runner ran.
- The action catalogue copies what the SPA requests today. Shared types catch shape changes, not a screen that starts making a new call; a later Playwright member can close that gap.
- A 100x run of 100 members is several hundred requests per second, which the database-backed queue, cache and sessions may not sustain. That is a finding to expect, not a simulator fault.
- Out of scope here: real browsers, moving the app clock, testing Stripe, multi-region runners.
