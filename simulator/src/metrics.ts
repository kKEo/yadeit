export interface Tally { count: number; errors: number; totalMs: number; maxMs: number }

/** The server's own account of a request, from its Server-Timing header. */
export interface ServerTiming { app: number; db: number; queries: number }

export interface Finding {
  flow: string
  step: string | null
  severity: 'error' | 'warning' | 'note'
  message: string
  team_id?: number
  member_id?: number
  details?: Record<string, unknown>
}

export type Kind = 'endpoint' | 'action' | 'flow' | 'runner'

export interface Bucket {
  at: number
  kind: Kind
  name: string
  count: number
  errors: number
  sum_ms: number
  max_ms: number
  histogram: number[]
  extra: Record<string, number>
}

export interface Report { buckets: Bucket[]; findings: Finding[] }

const empty = (): Tally => ({ count: 0, errors: 0, totalMs: 0, maxMs: 0 })

/**
 * Measures a run. Every request, action and flow is counted twice: into a
 * running total for the summary printed at the end, and into ten-second
 * buckets with a latency histogram, which are sent to the coordinator. The
 * histogram bounds come from the coordinator, so buckets of several runners
 * add up.
 */
export class Recorder {
  readonly endpoints = new Map<string, Tally>()
  readonly actions = new Map<string, Tally>()
  readonly flows = new Map<string, Tally>()
  readonly problems: string[] = []
  maxLagMs = 0
  private readonly bounds: number[]
  private readonly stepSeconds: number
  private buckets = new Map<string, Bucket>()
  private findings: Finding[] = []
  private lagSum = 0
  private lagCount = 0

  constructor(bounds: number[], stepSeconds: number) {
    this.bounds = bounds
    this.stepSeconds = stepSeconds
  }

  endpoint(name: string, ms: number, ok: boolean, server?: ServerTiming): void {
    add(this.endpoints, name, ms, ok)
    const bucket = this.bucket('endpoint', name, ms, ok)
    if (server) {
      bucket.extra.timed = (bucket.extra.timed ?? 0) + 1
      bucket.extra.server_ms = (bucket.extra.server_ms ?? 0) + server.app
      bucket.extra.db_ms = (bucket.extra.db_ms ?? 0) + server.db
      bucket.extra.queries = (bucket.extra.queries ?? 0) + server.queries
    }
  }

  action(name: string, ms: number, ok: boolean): void {
    add(this.actions, name, ms, ok)
    this.bucket('action', name, ms, ok)
  }

  flow(name: string, ms: number, ok: boolean): void {
    add(this.flows, name, ms, ok)
    this.bucket('flow', name, ms, ok)
  }

  /** Something a check found wrong, or an action that failed: kept for the report and shown in the summary. */
  finding(finding: Finding): void {
    if (this.findings.length < 200) this.findings.push({ ...finding, message: finding.message.slice(0, 1900) })
    if (this.problems.length < 200) this.problems.push(`${finding.flow}${finding.step ? ` (${finding.step})` : ''}: ${finding.message}`)
  }

  /** How late an action started, in wall milliseconds. */
  lag(ms: number): void {
    const late = Math.max(0, ms)
    this.lagSum += late
    this.lagCount += 1
    this.maxLagMs = Math.max(this.maxLagMs, late)
    this.bucket('runner', 'lag', late, true)
  }

  /** Mean lag since the last call, in milliseconds. */
  takeLag(): number {
    const mean = this.lagCount === 0 ? 0 : this.lagSum / this.lagCount
    this.lagSum = 0
    this.lagCount = 0
    return Math.round(mean)
  }

  /** Everything measured since the last call. The coordinator adds it to what it has. */
  drain(): Report {
    const report = { buckets: [...this.buckets.values()], findings: this.findings }
    this.buckets = new Map()
    this.findings = []
    return report
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

  private bucket(kind: Kind, name: string, ms: number, ok: boolean): Bucket {
    const at = Math.floor(Date.now() / 1000 / this.stepSeconds) * this.stepSeconds
    const key = `${at}|${kind}|${name}`
    let bucket = this.buckets.get(key)
    if (!bucket) {
      bucket = { at, kind, name: name.slice(0, 120), count: 0, errors: 0, sum_ms: 0, max_ms: 0, histogram: new Array<number>(this.bounds.length + 1).fill(0), extra: {} }
      this.buckets.set(key, bucket)
    }
    bucket.count += 1
    bucket.sum_ms += ms
    bucket.max_ms = Math.max(bucket.max_ms, ms)
    if (!ok) bucket.errors += 1
    bucket.histogram[slot(this.bounds, ms)]! += 1
    return bucket
  }
}

/** The histogram bucket a latency falls into: the first whose upper bound it does not exceed, or the last. */
export function slot(bounds: number[], ms: number): number {
  const index = bounds.findIndex((bound) => ms <= bound)
  return index === -1 ? bounds.length : index
}

function add(map: Map<string, Tally>, name: string, ms: number, ok: boolean): void {
  let tally = map.get(name)
  if (!tally) map.set(name, (tally = empty()))
  tally.count += 1
  tally.totalMs += ms
  tally.maxMs = Math.max(tally.maxMs, ms)
  if (!ok) tally.errors += 1
}
