import { hostname } from 'node:os'
import type { ActionName } from './catalogue.ts'
import { SimClock } from './clock.ts'
import { Control, isActive, type Registration, type RunState, type TeamPlan } from './control.ts'
import { Member, type TeamContext } from './member.ts'
import { Recorder } from './metrics.ts'
import { signUpTeam } from './onboarding.ts'
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
  const scheduler = new Scheduler<ActionName>(clock, registration.run.config.day_mode, (ms) => recorder.lag(ms))
  const known = new Set<number>()
  const contexts: TeamContext[] = []
  const pending: Promise<void>[] = []
  let state = registration.run
  let members = 0
  let rampDone = false

  options.log(`Runner ${registration.runner_id} joined run ${state.id}: ${state.speed}x, ${state.config.day_mode} days, ${state.config.duration_minutes} min.`)

  const adopt = (team: TeamPlan, position: number): void => {
    known.add(team.id)
    const context: TeamContext = {
      id: team.id, name: team.name, slug: team.workspace, pack: registration.pack, members: [],
      visitor: new Session(options.target, registration.origin, team.visitor_header, recorder),
      others: () => contexts.filter((other) => other !== context),
    }
    contexts.push(context)
    const people = team.members
      // Agents join in a later phase; until then only people are played.
      .filter((plan) => plan.kind !== 'agent')
      .map((plan) => new Member(plan, context, new Session(options.target, registration.origin, plan.header, recorder), recorder, state.seed, state.config))
    context.members = people
    members += people.length

    // Teams join one after another over the ramp-up, each member a little after the one before.
    // A team added while the run is going on joins at once.
    const delay = rampDone ? 0 : (state.config.ramp_up_seconds * position) / Math.max(1, state.config.teams)
    const enter = (): void => people.forEach((member, index) => scheduler.add(member, clock.now() + index * 5))

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
        for (const context of contexts) for (const member of context.members) activity[member.plan.id] = member.current
        const answer = await control.poll(registration.runner_id, recorder.takeLag(), activity)
        state = answer.run
        // A paused run stands still: simulated time stops and nobody acts.
        clock.setSpeed(state.status === 'running' ? state.speed : 0)
        answer.teams.filter((team) => !known.has(team.id)).forEach((team) => adopt(team, known.size))
        rampDone = true

        // Teams the coordinator no longer lists were removed from the run, or went to another runner.
        const listed = new Set(answer.teams.map((team) => team.id))
        for (const context of contexts.filter((c) => !listed.has(c.id) && c.members.length > 0)) {
          scheduler.remove((actor) => context.members.includes(actor as Member))
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

    scheduler.tick()
    await sleep(TICK_MS, stop)
  }

  // Let what is under way finish, but not for long.
  const deadline = Date.now() + 10_000
  while (scheduler.busy > 0 && Date.now() < deadline) await sleep(100)
  await send()

  return { run: state, recorder, skipped: scheduler.skipped, teams: known.size, members }
}

function sleep(ms: number, stop?: AbortSignal): Promise<void> {
  return new Promise((resolve) => {
    const timer = setTimeout(resolve, ms)
    stop?.addEventListener('abort', () => { clearTimeout(timer); resolve() }, { once: true })
  })
}
