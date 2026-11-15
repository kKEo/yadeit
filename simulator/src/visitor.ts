import type { Feedback, ListResponse } from '../../frontend/src/api/types.ts'
import { CheckFailure, flow, SKIP, tag } from './flows.ts'
import type { TeamContext } from './member.ts'
import type { Recorder } from './metrics.ts'
import { Rng, seedFor } from './rng.ts'
import type { Session } from './session.ts'

type VisitorAction = 'landing' | 'publicPage' | 'publicFeedback'

/**
 * Someone who is not signed in: opens the web app's front page with its
 * files, reads a page a team shared by link, and now and then fills in a
 * project's public feedback form.
 */
export class Visitor {
  due = 0
  busy = false
  current = 'idle'
  readonly plan: { id: number }
  readonly team: TeamContext
  readonly recorder: Recorder
  private session: Session
  private readonly rng: Rng
  private readonly pause: number

  constructor(team: TeamContext, recorder: Recorder, runSeed: number, intensity: number) {
    this.plan = { id: -team.id }
    this.team = team
    this.session = team.visitor
    this.recorder = recorder
    this.rng = new Rng(seedFor(runSeed, team.id, 4))
    this.pause = 240 / intensity
  }

  next(): { action: VisitorAction; pause: number } {
    return { action: this.rng.weighted<VisitorAction>({ landing: 5, publicPage: 3, publicFeedback: 1 }), pause: Math.max(5, this.rng.pause(this.pause)) }
  }

  async act(action: VisitorAction): Promise<void> {
    const started = performance.now()
    this.current = action
    this.session = this.team.newVisitor()
    let ok = true

    try {
      await this[action]()
    } catch (error) {
      ok = false
      this.recorder.finding({ flow: action, step: null, severity: 'error', team_id: this.team.id, message: error instanceof Error ? error.message : String(error) })
    }

    this.recorder.action(action, performance.now() - started, ok)
    this.current = 'idle'
  }

  /** The front page and the files it names, as a browser with an empty cache fetches them. */
  private async landing(): Promise<void> {
    const html = (await this.session.page('/')).data
    const files = [...String(html).matchAll(/(?:src|href)="(\/assets\/[^"]+)"/g)].map((match) => match[1]!).slice(0, 8)
    await Promise.all(files.map((file) => this.session.page(file)))
  }

  private async publicPage(): Promise<void> {
    const token = this.rng.pick(this.team.publicPages)
    if (token) await this.session.get(`/public/pages/${token}`)
  }

  /** The public form, filled in by a stranger; the team's lead must then find it in the feedback inbox. */
  private publicFeedback(): Promise<void> {
    return flow(this, 'publicFeedback', async (step) => {
      const token = this.team.feedbackToken
      const lead = this.team.members.find((member) => member.signedIn && member.isAdmin)
      const source = this.rng.pick(this.team.pack.feedback)
      if (!token || !lead || !source) return SKIP

      step('open the form')
      await this.session.get(`/public/feedback/${token}`)

      step('send')
      const title = `${source.title} ${tag()}`
      await this.session.post(`/public/feedback/${token}`, { title, body: source.body, name: 'A visitor', email: null, website: '' })

      step('arrives')
      const waiting = (await lead.session.get<ListResponse<Feedback>>(`${lead.R}/feedback?limit=100&status=new`)).data.data
      const entry = waiting.find((f) => f.title === title)
      if (!entry) throw new CheckFailure('Feedback from the public form did not reach the feedback inbox', { title })
      if (entry.source !== 'public') throw new CheckFailure(`Feedback from the public form is marked as "${entry.source}"`, { feedback: entry.id })
    })
  }
}
