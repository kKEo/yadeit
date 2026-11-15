import { hostname } from 'node:os'
import type { ActionName } from './catalogue.ts'
import { Agent } from './agent.ts'
import { dayOf, SimClock } from './clock.ts'
import { Control, isActive, type Registration, type RunState, type TeamPlan } from './control.ts'
import { Member, type TeamContext } from './member.ts'
import { Recorder } from './metrics.ts'
import { signUpTeam } from './onboarding.ts'
import { setUpTeam } from './setup.ts'
import { Visitor } from './visitor.ts'
import { Scheduler } from './scheduler.ts'
import { Session } from './session.ts'

export interface RunnerOptions { target: string; token: string; name: string; log: (line: string) => void }

export interface Outcome { run: RunState; recorder: Recorder; skipped: number; teams: number; members: number }

const POLL_MS = 2000
const TICK_MS = 100

/** Plays the teams the coordinator hands out until the run ends. */
export async function run(options: RunnerOptions, stop: AbortSignal): Promise<Outcome> {
  const control = new Control(options.target, options.token)
  const registration: Registration = await control.register(options.name, hostname())
  const recorder = new Recorder(registration.histogram_bounds, registration.step_seconds)
  const clock = new SimClock(registration.run.speed)
  const mode = registration.run.config.day_mode
  const scheduler = new Scheduler(clock, mode, (ms) => recorder.lag(ms))
  const known = new Set<number>()
  const contexts: TeamContext[] = []
  /** The agents and the visitor of each team, which are played but are not people of the plan. */
  const extras = new Map<TeamContext, { team: TeamContext; current?: string; plan?: { id: number } }[]>()
  const pending: Promise<void>[] = []
  let state = registration.run
  let members = 0
  let rampDone = false

  options.log(`Runner ${registration.runner_id} joined run ${state.id}: ${state.speed}x, ${state.config.day_mode} days, ${state.config.duration_minutes} min.`)

  const adopt = (team: TeamPlan, position: number): void => {
    known.add(team.id)
    let visits = 0
    const context: TeamContext = {
      id: team.id, name: team.name, slug: team.workspace, pack: registration.pack, members: [],
      visitor: new Session(options.target, registration.origin, team.visitor_headers[0] ?? '', recorder),
      // Every visit is someone else: a fresh browser under the next identity.
      newVisitor: () => new Session(options.target, registration.origin, team.visitor_headers[visits++ % Math.max(1, team.visitor_headers.length)] ?? '', recorder),
      others: () => contexts.filter((other) => other !== context),
      realtime: registration.realtime, workspaceId: null, agents: [], feedbackToken: null, publicPages: [], sinkUrl: registration.sink_url,
    }
    contexts.push(context)
    const agentPlans = team.members.filter((plan) => plan.kind === 'agent')
    const people = team.members
      .filter((plan) => plan.kind !== 'agent')
      .map((plan) => new Member(plan, context, new Session(options.target, registration.origin, plan.header, recorder), recorder, state.seed, state.config))
    context.members = people
    members += people.length

    // Teams join one after another over the ramp-up, each member a little after the one before.
    // A team added while the run is going on joins at once.
    const delay = rampDone ? 0 : (state.config.ramp_up_seconds * position) / Math.max(1, state.config.teams)
    const actors: { team: TeamContext }[] = []
    const enter = (): void => {
      people.forEach((member, index) => scheduler.add<ActionName>(member, clock.now() + index * 5))
      // The agents the lead created work over MCP with their own tokens; a visitor comes by signed out.
      const agents = context.agents.map((seat) => new Agent(seat, context, new Session(options.target, registration.origin, seat.header, recorder), recorder, state.seed, state.config.intensity))
      agents.forEach((agent, index) => scheduler.add(agent, clock.now() + 60 + index * 20))
      const visitor = new Visitor(context, recorder, state.seed, state.config.intensity)
      scheduler.add(visitor, clock.now() + 30)
      actors.push(...agents, visitor)
      extras.set(context, actors)
    }

    pending.push((async () => {
      await sleep(delay * 1000, stop)
      if (stop.aborted) return

      if (team.onboarding === 'real' && team.workspace === null) {
        const started = performance.now()
        try {
          await signUpTeam(control, people, team.id)
          recorder.flow('onboarding', performance.now() - started, true)
        } catch (error) {
          recorder.flow('onboarding', performance.now() - started, false)
          recorder.finding({ flow: 'onboarding', step: null, severity: 'error', team_id: team.id, message: error instanceof Error ? error.message : String(error) })
          return
        }
      }
      // The lead prepares the workspace (agents, webhook, public form and page) before anyone else starts.
      if (people[0]) await setUpTeam(people[0], agentPlans)
      enter()
    })())
  }

  let lastPoll = 0
  let lastReport = Date.now()
  const send = async (): Promise<void> => {
    const report = recorder.drain()
    if (report.buckets.length === 0 && report.findings.length === 0) return
    try {
      await control.metrics(report)
    } catch (error) {
      options.log(`Could not report measurements: ${error instanceof Error ? error.message : String(error)}`)
    }
  }
  while (!stop.aborted) {
    if (Date.now() - lastPoll >= POLL_MS) {
      lastPoll = Date.now()
      try {
        const activity: Record<number, string> = {}
        for (const context of contexts) {
          for (const member of context.members) activity[member.plan.id] = member.current
          for (const extra of extras.get(context) ?? []) if (extra.plan && extra.plan.id > 0) activity[extra.plan.id] = extra.current ?? 'idle'
        }
        const answer = await control.poll(registration.runner_id, recorder.takeLag(), activity, dayOf(clock.now()))
        state = answer.run
        // A paused run stands still: simulated time stops and nobody acts.
        clock.setSpeed(state.status === 'running' ? state.speed : 0)
        answer.teams.filter((team) => !known.has(team.id)).forEach((team) => adopt(team, known.size))
        rampDone = true

        // Teams the coordinator no longer lists were removed from the run, or went to another runner.
        const listed = new Set(answer.teams.map((team) => team.id))
        for (const context of contexts.filter((c) => !listed.has(c.id) && c.members.length > 0)) {
          scheduler.remove((actor) => (actor as unknown as { team?: TeamContext }).team === context)
          context.members.forEach((member) => member.socket?.close())
          context.members = []
        }
      } catch (error) {
        options.log(`Coordinator unreachable: ${error instanceof Error ? error.message : String(error)}`)
      }
      if (!isActive(state)) break
    }

    if (Date.now() - lastReport >= registration.step_seconds * 1000) {
      lastReport = Date.now()
      void send()
    }

    scheduler.skipNight()

    scheduler.tick()
    await sleep(TICK_MS, stop)
  }

  // Let what is under way finish, but not for long.
  const deadline = Date.now() + 10_000
  while (scheduler.busy > 0 && Date.now() < deadline) await sleep(100)
  await send()
  for (const context of contexts) context.members.forEach((member) => member.socket?.close())

  return { run: state, recorder, skipped: scheduler.skipped, teams: known.size, members }
}

function sleep(ms: number, stop?: AbortSignal): Promise<void> {
  return new Promise((resolve) => {
    const timer = setTimeout(resolve, ms)
    stop?.addEventListener('abort', () => { clearTimeout(timer); resolve() }, { once: true })
  })
}
