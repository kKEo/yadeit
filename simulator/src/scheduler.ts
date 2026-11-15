import { activity, type DayMode, nextMoment, nextOfficeStart, SimClock } from './clock.ts'

/** Something that acts at simulated moments: a member, in practice. */
export interface Actor<S> {
  due: number
  busy: boolean
  next(): { action: S; pause: number }
  act(action: S): Promise<void>
}

/** A member more than this many wall seconds behind stops trying to catch up; what was missed is counted, not replayed. */
const MAX_BACKLOG_SECONDS = 30

/**
 * Starts each actor's next action when its simulated moment comes. The next
 * moment is counted from when the action was meant to start, not from when
 * the server answered: a slow server therefore shows up as actions starting
 * late (lag) instead of quietly producing less traffic.
 */
export class Scheduler {
  readonly actors: Actor<unknown>[] = []
  skipped = 0
  private readonly clock: SimClock
  private readonly mode: DayMode
  private readonly onLag: (ms: number) => void

  constructor(clock: SimClock, mode: DayMode, onLag: (ms: number) => void) {
    this.clock = clock
    this.mode = mode
    this.onLag = onLag
  }

  add<S>(actor: Actor<S>, firstDue: number): void {
    actor.due = firstDue
    this.actors.push(actor as Actor<unknown>)
  }

  /** Takes actors out, for a team that left the run. What they are doing right now still finishes. */
  remove(gone: (actor: Actor<unknown>) => boolean): void {
    for (let i = this.actors.length - 1; i >= 0; i--) if (gone(this.actors[i]!)) this.actors.splice(i, 1)
  }

  /**
   * In "office hours only" nobody acts at night, so the night is skipped
   * instead of waited for: simulated time moves to the next working morning,
   * and whoever was still due that evening acts first thing, without that
   * counting as being late.
   */
  skipNight(wallNow: number = Date.now()): boolean {
    const now = this.clock.now(wallNow)
    if (this.mode !== 'workday' || this.clock.speed <= 0 || activity(now, this.mode) > 0) return false

    const morning = nextOfficeStart(now)
    this.actors.forEach((actor, index) => { if (actor.due < morning) actor.due = morning + (index % 60) })
    this.clock.jumpTo(morning, wallNow)
    return true
  }

  /** Starts everything that is due. Returns how many actions it started. */
  tick(wallNow: number = Date.now()): number {
    const speed = this.clock.speed
    if (speed <= 0) return 0

    const now = this.clock.now(wallNow)
    let started = 0

    for (const actor of this.actors) {
      if (actor.busy || actor.due > now) continue

      const { action, pause } = actor.next()
      const intended = actor.due
      this.onLag(((now - intended) / speed) * 1000)

      let base = intended
      if (now - intended > MAX_BACKLOG_SECONDS * speed) {
        base = now
        this.skipped += 1
      }
      actor.due = nextMoment(base, pause, this.mode, (pause % 1))
      actor.busy = true
      started += 1
      void actor.act(action).finally(() => { actor.busy = false })
    }

    return started
  }

  get busy(): number {
    return this.actors.filter((a) => a.busy).length
  }
}
