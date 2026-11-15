import { type DayMode, nextMoment, SimClock } from './clock.ts'

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
export class Scheduler<S> {
  readonly actors: Actor<S>[] = []
  skipped = 0
  private readonly clock: SimClock
  private readonly mode: DayMode
  private readonly onLag: (ms: number) => void

  constructor(clock: SimClock, mode: DayMode, onLag: (ms: number) => void) {
    this.clock = clock
    this.mode = mode
    this.onLag = onLag
  }

  add(actor: Actor<S>, firstDue: number): void {
    actor.due = firstDue
    this.actors.push(actor)
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
