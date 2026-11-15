export interface Tally { count: number; errors: number; totalMs: number; maxMs: number }

const empty = (): Tally => ({ count: 0, errors: 0, totalMs: 0, maxMs: 0 })

/** Counts what happened during a run: per endpoint, per action and per flow, plus how late actions started. */
export class Recorder {
  readonly endpoints = new Map<string, Tally>()
  readonly actions = new Map<string, Tally>()
  readonly flows = new Map<string, Tally>()
  readonly problems: string[] = []
  private lagSum = 0
  private lagCount = 0
  maxLagMs = 0

  endpoint(name: string, ms: number, ok: boolean): void {
    add(this.endpoints, name, ms, ok)
  }

  action(name: string, ms: number, ok: boolean): void {
    add(this.actions, name, ms, ok)
  }

  flow(name: string, ms: number, ok: boolean, problem?: string): void {
    add(this.flows, name, ms, ok)
    if (!ok && problem) this.problem(`${name}: ${problem}`)
  }

  problem(text: string): void {
    if (this.problems.length < 200) this.problems.push(text)
  }

  lag(ms: number): void {
    this.lagSum += ms
    this.lagCount += 1
    this.maxLagMs = Math.max(this.maxLagMs, ms)
  }

  /** Mean lag since the last call, in milliseconds. */
  takeLag(): number {
    const mean = this.lagCount === 0 ? 0 : this.lagSum / this.lagCount
    this.lagSum = 0
    this.lagCount = 0
    return Math.round(mean)
  }

  totals(of: Map<string, Tally>): Tally {
    const sum = empty()
    for (const t of of.values()) {
      sum.count += t.count
      sum.errors += t.errors
      sum.totalMs += t.totalMs
      sum.maxMs = Math.max(sum.maxMs, t.maxMs)
    }
    return sum
  }
}

function add(map: Map<string, Tally>, name: string, ms: number, ok: boolean): void {
  let tally = map.get(name)
  if (!tally) map.set(name, (tally = empty()))
  tally.count += 1
  tally.totalMs += ms
  tally.maxMs = Math.max(tally.maxMs, ms)
  if (!ok) tally.errors += 1
}
