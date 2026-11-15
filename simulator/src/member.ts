import type { DocPageSummary, Feedback, Item, Me, Member as WorkspaceMember, Project } from '../../frontend/src/api/types.ts'
import { ACTIONS, type ActionName } from './catalogue.ts'
import type { MemberPlan, Pack, RealtimeConfig, RunConfig } from './control.ts'
import type { Socket } from './realtime.ts'
import type { Recorder } from './metrics.ts'
import { personaFor, weightsFor } from './personas.ts'
import { Rng, seedFor } from './rng.ts'
import { ApiFailure, Session } from './session.ts'

/** What the members of one team share: their workspace and the text they write with. */
export interface TeamContext {
  id: number
  name: string
  /** The workspace's web address; unknown until a team that signs up for real has created it. */
  slug: string | null
  pack: Pack
  /** Everyone in the team who is played, in the order of the plan. */
  members: Member[]
  /** A browser nobody is signed in to: what the public sees. */
  visitor: Session
  /** A fresh browser of someone else who is not signed in. */
  newVisitor: () => Session
  /** The other teams this runner plays. */
  others: () => TeamContext[]
  /** Where members open their websocket; null when realtime is off. */
  realtime: RealtimeConfig | null
  /** The numeric id of the workspace, which realtime channels are named after. */
  workspaceId: number | null
  /** Agents the lead created, with the token each works with. */
  agents: { id: number; memberId: number; name: string; token: string; header: string }[]
  /** Set once the lead has opened the public feedback form of a project. */
  feedbackToken: string | null
  /** Links of pages shared with the public. */
  publicPages: string[]
  /** Where the team's webhook sends to. */
  sinkUrl: string
  /** Asks the model what an explorer does next. */
  decide: (memberId: number, options: string[], history: string[], seen: string) => Promise<{ action: string; stuck: string | null }>
  /** How much longer explorers pause when the run is faster than they can think. */
  explorerSlowdown: () => number
}

/**
 * A member's place in the run: team number and position in the team, read
 * from the address ("r12-t3-m2@…"). Unlike the database id it is the same in
 * every run, so the same seed plays the same people the same way.
 */
export function placeOf(plan: MemberPlan): number {
  const match = /-t(\d+)-m(\d+)@/.exec(plan.email)
  return match ? Number(match[1]) * 100 + Number(match[2]) : plan.id
}

/** With SIM_SELF_TEST=1 the first member who can write runs the search flow at once, where one check is made to fail. */
let selfTestPending = process.env.SIM_SELF_TEST === '1'

export interface Step { action: ActionName; pause: number }

/**
 * One simulated person. What they do next and how long they wait is drawn
 * from `planRng` alone, so the sequence of a member is the same on every run
 * with the same seed; what they type and which item they pick comes from
 * `dataRng`, which may depend on what the server answered.
 */
export class Member {
  readonly plan: MemberPlan
  readonly team: TeamContext
  readonly session: Session
  readonly recorder: Recorder
  readonly dataRng: Rng
  private readonly planRng: Rng
  private readonly intensity: number
  private readonly weights: Partial<Record<ActionName, number>>
  private readonly pause: number
  /** What this person is doing right now, for the live screen. */
  current = 'idle'

  signedIn = false
  busy = false
  /** Simulated moment of the next action. */
  due = 0
  me: Me | null = null
  items: Item[] = []
  projects: Project[] = []
  people: WorkspaceMember[] = []
  pages: DocPageSummary[] = []
  feedback: Feedback[] = []
  /** The websocket the web app would hold open, and the item changes it has announced. */
  socket: Socket | null = null
  readonly heard: { key: string; at: number }[] = []
  /** Steps the director gave this person for today, done before anything drawn at random. */
  readonly directed: { action: ActionName; text: string | null }[] = []
  /** The text the director wants used by the action under way: an item title, a comment. */
  note: string | null = null
  /** What an explorer did lately and how it went, which the model is told. */
  private readonly history: string[] = []

  constructor(plan: MemberPlan, team: TeamContext, session: Session, recorder: Recorder, runSeed: number, config: Pick<RunConfig, 'intensity' | 'disabled_actions' | 'personas'>) {
    this.plan = plan
    this.team = team
    this.session = session
    this.recorder = recorder
    const place = placeOf(plan)
    this.planRng = new Rng(seedFor(runSeed, place, 1))
    this.dataRng = new Rng(seedFor(runSeed, place, 2))
    this.intensity = config.intensity
    const persona = personaFor(plan.persona, config.personas)
    this.weights = weightsFor(persona, plan.role, config.disabled_actions)
    this.pause = persona.pause
  }

  /** The workspace part of every address. */
  get R(): string {
    return `/workspaces/${this.team.slug}`
  }

  get canWrite(): boolean {
    return this.plan.role !== 'guest'
  }

  get isAdmin(): boolean {
    return this.plan.role === 'owner' || this.plan.role === 'admin'
  }

  /** Whether this person's role and scenario let them do this at all. */
  allows(action: ActionName): boolean {
    return action in this.weights
  }

  /** The next thing this person does and the pause before the one after, in simulated seconds. */
  next(): Step {
    // Both are always drawn, so that a directed day does not shift what the same seed gives afterwards.
    const drawn = this.planRng.weighted(this.weights)
    const pause = Math.max(2, this.planRng.pause(this.pause / this.intensity))
    const step = this.directed.shift()
    this.note = step?.text ?? null

    return {
      action: step?.action ?? drawn,
      pause: this.plan.kind === 'ai' ? pause * this.team.explorerSlowdown() : pause,
    }
  }

  /**
   * An explorer asks the model what to do instead of following the draw, and
   * passes on what the model found strange. When the model cannot be asked
   * (budget spent, provider down) the explorer carries on as a scripted member.
   */
  private async explore(drawn: ActionName): Promise<ActionName> {
    const options = Object.keys(this.weights).filter((name) => !name.startsWith('flow') && name !== 'delegate')
    const seen = [
      `${this.items.length} items in the list` + (this.items.length ? `, for example: ${this.items.slice(0, 5).map((i) => `${i.key} "${i.title}" (${i.state.name})`).join('; ')}` : ''),
      `${this.pages.length} docs known, ${this.feedback.length} pieces of feedback waiting, ${this.people.length} people in the workspace`,
    ].join('\n')

    try {
      const choice = await this.team.decide(this.plan.id, options, this.history, seen)
      if (choice.stuck) this.recorder.finding({ flow: 'explorer', step: null, severity: 'note', message: choice.stuck, team_id: this.team.id, member_id: this.plan.id })
      return options.includes(choice.action) ? (choice.action as ActionName) : drawn
    } catch {
      return drawn
    }
  }

  private remember(line: string): void {
    this.history.push(line.slice(0, 280))
    if (this.history.length > 10) this.history.shift()
  }

  /** Runs one action, timed and recorded. A failure is noted and the person carries on. */
  async act(action: ActionName): Promise<void> {
    if (selfTestPending && this.signedIn && this.canWrite) {
      selfTestPending = false
      action = 'flowSearch'
    }

    if (this.plan.kind === 'ai' && this.signedIn) action = await this.explore(action)

    const started = performance.now()
    const name = this.signedIn ? action : 'signIn'
    this.current = name

    try {
      if (!this.signedIn) await ACTIONS.signIn(this)
      else await ACTIONS[action](this)
      this.recorder.action(name, performance.now() - started, true)
      this.remember(`${name}: ok`)
    } catch (error) {
      this.recorder.action(name, performance.now() - started, false)
      this.remember(`${name}: failed, ${error instanceof Error ? error.message : String(error)}`)
      this.recorder.finding({ flow: name, step: null, severity: 'error', message: error instanceof Error ? error.message : String(error), team_id: this.team.id, member_id: this.plan.id })
      // A session that ended is signed in again on the next turn, as a person would.
      if (error instanceof ApiFailure && error.status === 401) {
        this.signedIn = false
        this.session.forget()
      }
    } finally {
      this.current = 'idle'
    }
  }
}
