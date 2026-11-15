import type { DayMode } from './clock.ts'
import type { Report } from './metrics.ts'

export interface RunConfig {
  teams: number
  duration_minutes: number
  day_mode: DayMode
  ramp_up_seconds: number
  intensity: number
  /** Actions and flows the scenario switches off. */
  disabled_actions?: string[]
  /** The scenario's own pause and weights per persona, laid over the defaults. */
  personas?: Record<string, { pause?: number; weights?: Record<string, number> }>
  [key: string]: unknown
}

export interface RunState { id: number; status: string; speed: number; seed: number; config: RunConfig; stop_reason: string | null }

export interface MemberPlan {
  id: number
  name: string
  persona: string
  role: 'owner' | 'admin' | 'member' | 'guest'
  kind: 'scripted' | 'ai' | 'agent'
  email: string
  password: string
  header: string
}

export interface TeamPlan {
  id: number
  name: string
  onboarding: 'fast' | 'real'
  status: string
  workspace: string | null
  /** Signed headers for people who are not signed in; each visit uses another. */
  visitor_headers: string[]
  members: MemberPlan[]
}

export interface Pack {
  projects: { key: string; name: string; items: { title: string; description?: string; kind?: string; priority?: string }[] }[]
  comments: string[]
  pages: { title: string; body: string }[]
  feedback: { title: string; body: string }[]
  [key: string]: unknown
}

export interface RealtimeConfig { key: string; host: string; port: number; scheme: string }

export interface Registration {
  runner_id: number; run: RunState; origin: string; pack: Pack; histogram_bounds: number[]; step_seconds: number
  /** Where simulated workspaces send their webhooks. */
  sink_url: string
  /** Null when the installation has realtime switched off. */
  realtime: RealtimeConfig | null
}

const ACTIVE = ['provisioning', 'running', 'paused', 'stopping']
export const isActive = (run: RunState) => ACTIVE.includes(run.status)

export class ControlError extends Error {
  readonly status: number

  constructor(status: number, message: string) {
    super(message)
    this.status = status
  }
}

/** The runner's line to the coordinator: who to play, how fast, and the mail of its simulated people. */
export class Control {
  private readonly target: string
  private readonly token: string

  constructor(target: string, token: string) {
    this.target = target.replace(/\/$/, '')
    this.token = token
  }

  register(name: string, host: string): Promise<Registration> {
    return this.call('POST', '/register', { name, host })
  }

  poll(runnerId: number, lagMs: number, activity: Record<number, string> = {}, simDay = 0): Promise<{ run: RunState; teams: TeamPlan[] }> {
    return this.call('POST', '/poll', { runner_id: runnerId, lag_ms: Math.max(0, Math.round(lagMs)), activity, sim_day: simDay })
  }

  /** Waits for a message to arrive, as a person waits for an email, and returns its first link. */
  async link(email: string, kind: string, contains: string): Promise<string> {
    for (let attempt = 0; attempt < 20; attempt++) {
      const mail = await this.call<{ links: string[] } | null>('GET', `/mailbox?kind=${kind}&email=${encodeURIComponent(email)}`)
      const link = mail?.links.find((l) => l.includes(contains))
      if (link) return link
      await new Promise((resolve) => setTimeout(resolve, 250))
    }
    throw new Error(`No ${kind} message arrived for ${email}`)
  }

  metrics(report: Report): Promise<{ status: string }> {
    return this.call('POST', '/metrics', report)
  }

  teamReady(teamId: number): Promise<{ status: string }> {
    return this.call('POST', `/teams/${teamId}/ready`)
  }

  private async call<T>(method: string, path: string, body?: unknown): Promise<T> {
    const response = await fetch(`${this.target}/api/v1/sim/runner${path}`, {
      method,
      headers: { Accept: 'application/json', Authorization: `Bearer ${this.token}`, ...(body ? { 'Content-Type': 'application/json' } : {}) },
      body: body ? JSON.stringify(body) : undefined,
      signal: AbortSignal.timeout(20_000),
    })
    if (!response.ok) throw new ControlError(response.status, `Coordinator answered ${response.status} to ${method} ${path}: ${(await response.text()).slice(0, 200)}`)
    return ((await response.json()) as { data: T }).data
  }
}
