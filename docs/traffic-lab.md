# Traffic lab

The Traffic lab plays simulated teams against a Yadeit installation. Its members sign in and work
through the same HTTP API, MCP server and websocket the real clients use, at one to a hundred
times organic speed. A run answers two questions:

- **Performance:** how fast does the installation answer, where is the time spent, and what
  falls behind first?
- **Flows:** do whole journeys still work, from sign-up to an invited teammate, from a new item
  to "done", from public feedback to a work item?

It is off unless switched on, and everything a run creates is marked, never emailed or billed,
and can be removed. The design decisions and the build plan are in
[traffic-lab-plan.md](traffic-lab-plan.md).

## Try it locally

```bash
make sim-up        # the local Docker stack with the lab on, realtime on, and a runner standing by
make sim-smoke     # 3 teams, 2 minutes, 10x; fails when actions, flows or the verdict fail
```

After `make sim-up`, sign in as an operator (`make app-operator EMAIL=you@example.com`) and open
**Operator area → Traffic lab**. Start a run with the default settings; the standing runner
plays it and the page shows it live.

| Command | What it does |
|---|---|
| `make sim-up` | Builds and starts the app with the lab on, plus `reverb` and a standing `runner` |
| `make sim-run TEAMS=5 SIZE=3-8 SPEED=50 MINUTES=15 SEED=1` | Starts a run and plays it in the foreground |
| `make sim-smoke` | The short check, with clean-up. `SELF_TEST=1` breaks one check on purpose |
| `make sim-report` | The report of the latest run; exits with 1 when it failed |
| `make sim-stop` | Stops the run and removes what it created |
| `make sim-down` | Switches the lab off again; the app keeps running |

`sim-run` and `sim-smoke` stop the standing runner to play the run themselves. Run `make sim-up`
again before starting runs from the panel.

## How it is built

```
Operator area > Traffic lab                Runner(s): simulator/ (Node 24, TypeScript, no dependencies)
        |  /api/v1/admin/sim/*                   |  /api/v1/sim/runner/*: teams to play, speed, mail,
        v                                        |  AI decisions, measurements
Coordinator: backend/app/Simulator  <------------+
  scenarios, runs, teams, members                |  the traffic itself: the public API with a cookie session,
  provisioning, safety, AI, reports              v  /mcp with agent tokens, the websocket, public pages
        |                                  The installation under test
        v
  sim_* tables: configuration, ten-second measurements, findings
```

- The **coordinator** is part of the Laravel app. It creates the teams, knows what is simulated,
  stores what runners measure and builds the report.
- A **runner** is a separate process that talks to the installation only over HTTP. Run it on
  another machine when the numbers matter: on the same host it competes with the app for the
  processor. Several runners share the teams of a run; a runner that goes silent has its teams
  taken over.

### Teams and members

One team is one workspace. Its size (1-10) decides who is in it (`backend/config/simulator.php`,
`templates`):

| Persona | Role | What they do |
|---|---|---|
| lead | owner or admin | Plans, assigns, triages feedback, hands items to agents, manages members and settings |
| developer | member | Picks up items, moves them along, comments, attaches files, searches |
| designer | member | Like a developer, with more docs and attachments |
| client | guest | Reads, comments, sends feedback |
| agent | member, over MCP | Takes ready items through claim, plan, question, link and submit for review |
| visitor | signed out | Loads the front page, reads a shared page, fills in the public feedback form |

A share of the teams signs up for real: the lead registers, confirms the address from the email,
creates the workspace and invites the others, who follow their invitations. The rest are created
directly, with a starting backlog, so the run reaches a steady state quickly.

Before a team starts, its lead sets the workspace up through the API: the agents and a token
for each, a webhook to the lab's own receiver, the public feedback form, one publicly shared
page, and one-week cycles.

### Time and speed

The speed-up compresses what people do, not the server's clock.

- Each member pauses between actions (a mean per persona, in simulated seconds). At 50x those
  pauses are fifty times shorter in real time.
- The next action is planned from when the previous one **was meant to** start, not from when
  the server answered. A slow server therefore shows up as actions starting late, instead of
  quietly producing less traffic. When that lateness is large, the report says the runner could
  not keep up and that good numbers may flatter the installation.
- Days: "realistic" (quiet nights and weekends), "office hours only" (the night is skipped, not
  waited for) or "around the clock".
- At each simulated midnight the coordinator runs what the scheduler runs at night, for the
  run's workspaces and on the run's calendar: cycle rollover and stale items going back to the
  backlog.
- The same seed gives the same teams, and each member the same sequence of actions and pauses.

**Rate limits and speed.** Limits count by the server's clock. A run at 50x packs fifty minutes
of one person's work into one minute, so for requests signed by a run each limit is multiplied
by the run's speed. At 1x the limits are the real ones. A fast run can therefore not show that a
real user would hit a limit; use 1x for that.

### What is measured

- **Requests**, per endpoint and ten seconds: count, failures, and a latency histogram from which
  the median, 95% and 99% are read. For requests of a run the server adds a `Server-Timing`
  header with its own time, database time and number of queries.
- **Actions**: one thing a member does, usually several requests (opening an item is four).
- **Flows**: journeys with checks. A failed check becomes a finding that names the step.
- **Server samples** every ten seconds: queue jobs waiting, age of the oldest, failed jobs, open
  sessions.

| Flow | What must hold |
|---|---|
| Sign-up to invited teammate | The emails arrive (in the lab's mailbox), the invited member is in |
| Item from creation to done | Every state read back is the one written; a change on an old version is refused |
| Assignment reaches the inbox | The teammate's inbox shows it within 15 s |
| New item is found by search | Within 10 s |
| Feedback becomes an item | The item is linked and counts one request |
| Public link of a doc | Opens signed out; stops opening when withdrawn |
| Public feedback form | What a visitor sends reaches the feedback inbox, marked as public |
| Change reaches an open browser | A teammate's websocket announces it within 10 s |
| Webhook arrives and verifies | The signature matches one of the run's webhooks |
| Agent takes an item to review | A second agent's claim is refused; an agent cannot move an item to Done |
| Teams cannot see each other | Another team's workspace answers 404; a guest's write answers 403 |
| Night | Cycle rollover and stale-item decay ran for the simulated date |

### Report and verdict

The report has totals, every endpoint, action and flow, a timeline, the findings and a verdict
against thresholds (defaults: 95% of requests within 1000 ms, at most 1% failed requests, at
least 99% of flows completing; a scenario can set its own). It is kept on the run after a purge.

```bash
php artisan sim:report            # the latest run; exit status 1 when it failed
php artisan sim:report 12 --json  # everything, for another tool
php artisan sim:report 12 --baseline=9   # change of each endpoint's 95% against run 9
```

A run ends by itself when its time is up, when more than half of the requests fail over a
minute, when no runner has been heard for 90 seconds, or when its AI budget is spent.

## AI

All AI is off unless a scenario switches a layer on. Runners never hold the provider key: they
ask the coordinator, which uses the installation's `ANTHROPIC_API_KEY` and counts every call of
a run against the run's token budget.

| Layer | What it does | When to use it |
|---|---|---|
| Content packs | The model writes the names and text of a simulation once, for a theme | Realistic text and search terms at no cost per run |
| Director | Writes each team's day as steps the scripted members play first | Coherent stories; a few calls per team and day. Stored, so a run can be replayed |
| Explorers | The model chooses every step of up to three members and reports what seems broken | Finding confusing or broken paths nobody scripted. Slow, costs per step, not repeatable |

Built-in AI help inside simulated workspaces is answered without a model unless the scenario
sets `ai.real_help`.

## Safety

- **Two switches.** `SIMULATOR_ENABLED=true` turns the lab on. With `APP_ENV=production` it also
  needs `SIMULATOR_ALLOW_PRODUCTION=true`, and the panel shows a warning: a run slows the
  service down for real users. While off, every address of the lab answers 404.
- **Operators only**, and hard caps from the environment (`SIMULATOR_MAX_TEAMS`,
  `SIMULATOR_MAX_SPEED`, `SIMULATOR_MAX_DURATION_MINUTES`).
- **Marked data.** Simulated accounts and workspaces carry the id of their run. They are left
  out of the operator's numbers, lists and announcements, are not held to plan limits and never
  reach checkout.
- **No mail leaves.** Simulated people have addresses under `sim.invalid`. Mail to them is never
  sent; confirmation, invitation and reset links are kept for the runner to follow.
- **No calls out.** A simulated workspace can send webhooks only to the lab's own receiver.
- **A signed header.** A request counts as simulated only with a signature of a run that is
  going on. A forged or old one changes nothing.
- **Clean-up.** Removing a run deletes its workspaces, accounts, sessions, tokens, files and
  their traces in the mail and audit logs. Runs older than `SIMULATOR_RETENTION_DAYS` are removed
  nightly.

## Running it against another installation

On the installation: set `SIMULATOR_ENABLED=true` (and the production switch if it applies) and
a long random `SIMULATOR_RUNNER_TOKEN`. On the machine that generates the load:

```bash
cd simulator
SIM_TARGET=https://staging.example.com SIM_TOKEN='standby|<the runner token>' node src/main.ts --standby
```

Then start runs from the operator area. Without a standing runner, starting a run shows a
one-time token; pass it as `SIM_TOKEN` to `node src/main.ts`.

If the queue worker cannot reach the app under `APP_URL`, set `SIMULATOR_SINK_URL` to an address
of `/api/v1/sim/sink` that it can reach. For websockets the runner connects to `REVERB_HOST`.

## Extending it

All of this is in `simulator/src/`. Types of requests and answers come from
`frontend/src/api/types.ts`, so a change to the API fails `npm run typecheck` here.

**A new action** (one thing a person does) goes into `actions.ts` and gets a weight in
`personas.ts`:

```ts
// actions.ts: send the requests the web app sends for it, in the same order
async archiveDone(m: Member) {
  const done = m.items.filter((i) => i.state.group === 'completed')
  const item = m.dataRng.pick(done)
  if (item) await m.session.patch(`${m.R}/items/${item.key}`, { archived: true, expected_version: item.version }, { allow: [412] })
},

// personas.ts
lead: { pause: 40, weights: { /* … */ archiveDone: 1 } },
```

Add the name to `NOT_FOR_GUESTS` in `personas.ts` if guests may not do it, and to
`SimAi::ACTIONS` in the backend if the director and explorers may choose it.

**A new flow** (a journey with checks) goes into `flows.ts`:

```ts
flowArchive: (m: Member) => flow(m, 'archiveHidesItem', async (step) => {
  step('create')
  const item = await createItem(m, `Archive check ${tag()}`)
  step('archive')
  await m.session.patch(`${m.R}/items/${item.key}`, { archived: true, expected_version: item.version })
  step('gone from the list')
  const list = await m.session.get<ListResponse<Item>>(`${m.R}/items?limit=50`)
  if (list.data.data.some((i) => i.key === item.key)) throw new CheckFailure('An archived item is still listed', { item: item.key })
}),
```

Give it a weight in a persona, a name under `sim.flowName` in both locale files, and, if a
scenario should be able to switch it off, an entry in `FLOWS` of `SimScenarioDialog.vue`.

**A new persona** is an entry in `PERSONAS` (a pause and weights) and a place in the team
templates of `backend/config/simulator.php`.

A scenario can also change pauses and weights without code, as JSON in its "Persona overrides".

## Limits

- The actions copy what the web app requests today. Shared types catch a changed shape, not a
  screen that starts making a new request.
- The live report is rebuilt from all stored measurements (cached for five seconds); it has not
  been measured on runs of several hours.
- Real browsers, moving the server's clock, Stripe and runners in several regions are not part
  of it.
