import type { DocPageSummary, Feedback, Item, Me, Member as WorkspaceMember, Project } from '../../frontend/src/api/types.ts'
import { ACTIONS, type ActionName } from './catalogue.ts'
import type { MemberPlan, Pack } from './control.ts'
import type { Recorder } from './metrics.ts'
import { PERSONAS, weightsFor } from './personas.ts'
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
  /** The other teams this runner plays. */
  others: () => TeamContext[]
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

  constructor(plan: MemberPlan, team: TeamContext, session: Session, recorder: Recorder, runSeed: number, intensity: number) {
    this.plan = plan
    this.team = team
    this.session = session
    this.recorder = recorder
    const place = placeOf(plan)
    this.planRng = new Rng(seedFor(runSeed, place, 1))
    this.dataRng = new Rng(seedFor(runSeed, place, 2))
    this.intensity = intensity
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

  /** The next thing this person does and the pause before the one after, in simulated seconds. */
  next(): Step {
    const persona = PERSONAS[this.plan.persona] ?? PERSONAS.developer!
    return {
      action: this.planRng.weighted(weightsFor(persona, this.plan.role)),
      pause: Math.max(2, this.planRng.pause(persona.pause / this.intensity)),
    }
  }

  /** Runs one action, timed and recorded. A failure is noted and the person carries on. */
  async act(action: ActionName): Promise<void> {
    if (selfTestPending && this.signedIn && this.canWrite) {
      selfTestPending = false
      action = 'flowSearch'
    }

    const started = performance.now()
    const name = this.signedIn ? action : 'signIn'

    try {
      if (!this.signedIn) await ACTIONS.signIn(this)
      else await ACTIONS[action](this)
      this.recorder.action(name, performance.now() - started, true)
    } catch (error) {
      this.recorder.action(name, performance.now() - started, false)
      this.recorder.finding({ flow: name, step: null, severity: 'error', message: error instanceof Error ? error.message : String(error), team_id: this.team.id, member_id: this.plan.id })
      // A session that ended is signed in again on the next turn, as a person would.
      if (error instanceof ApiFailure && error.status === 401) {
        this.signedIn = false
        this.session.forget()
      }
    }
  }
}
