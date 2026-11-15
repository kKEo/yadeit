import { request } from './client'
import type { ItemResponse } from './types'

/** The Traffic lab: simulated teams that load the installation (operator area). */

export type SimDayMode = 'realistic' | 'workday' | 'continuous'
export interface SimThresholds { p95_ms: number; error_rate: number; flow_success: number }

export interface SimConfig {
  teams: number
  size_min: number
  size_max: number
  speed: number
  duration_minutes: number
  day_mode: SimDayMode
  ramp_up_seconds: number
  /** Share (0-1) of teams whose members sign up through the API. */
  real_signup_share: number
  volume: string
  intensity: number
  seed: number
  pack_id: number | null
  thresholds: Partial<SimThresholds>
  /** Actions and flows switched off. */
  disabled_actions: string[]
  /** Own pause and weights per persona, laid over the defaults. */
  personas: Record<string, { pause?: number; weights?: Record<string, number> }>
}

export interface SimStatus {
  enabled: boolean
  production: boolean
  caps: { teams: number; team_size: number; speed: number; duration_minutes: number }
  speeds: number[]
  volumes: string[]
  defaults: SimConfig
  thresholds: SimThresholds
  /** A runner is always there, so a run starts playing by itself. */
  standing_runner: boolean
  active_run_id: number | null
}

export interface SimScenario { id: number; name: string; config: SimConfig; updated_at: string }
export interface SimPack { id: number; name: string; theme: string | null; source: 'builtin' | 'ai'; created_at: string | null }
export interface SimPackContent {
  name?: string
  theme?: string
  people: string[]
  teams: string[]
  projects: { key: string; name: string; items: { title: string }[] }[]
  comments: string[]
  pages: { title: string }[]
  feedback: { title: string }[]
}

export interface SimTotals {
  requests: number; failed_requests: number; error_rate: number; requests_per_second: number
  p50_ms: number; p95_ms: number; p99_ms: number; max_ms: number
  actions: number; failed_actions: number; flows: number; failed_flows: number; findings: number
  runner_lag_p95_ms: number; oldest_job_seconds: number
}

export type SimRunStatus = 'draft' | 'provisioning' | 'running' | 'paused' | 'stopping' | 'finished' | 'failed' | 'aborted'

export interface SimRun {
  id: number
  name: string
  status: SimRunStatus
  active: boolean
  speed: number
  seed: number
  config: SimConfig
  verdict: 'pass' | 'fail' | null
  stop_reason: string | null
  totals: SimTotals | null
  /** Its workspaces and accounts are gone; the report stays. */
  purged: boolean
  started_at: string | null
  finished_at: string | null
}

export interface SimRow {
  name: string; count: number; errors: number; mean_ms: number; p50_ms: number; p95_ms: number; p99_ms: number; max_ms: number
  server_ms: number | null; db_ms: number | null; queries: number | null
}

export interface SimPoint {
  at: number; requests_per_second: number; errors: number; p50_ms: number; p95_ms: number; p99_ms: number
  failed_flows: number; runner_lag_ms: number; jobs_waiting: number; oldest_job_seconds: number
}

export interface SimReport {
  verdict: 'pass' | 'fail'
  reasons: string[]
  /** The runner could not keep up, so good numbers may flatter the server. */
  runner_saturated: boolean
  thresholds: SimThresholds
  totals: SimTotals
  endpoints: SimRow[]
  actions: SimRow[]
  flows: SimRow[]
  timeline: SimPoint[]
}

export interface SimMember { id: number; name: string; persona: string; role: string; kind: 'scripted' | 'ai' | 'agent'; action: string | null }
export interface SimTeam { id: number; name: string; size: number; onboarding: 'fast' | 'real'; status: string; workspace: string | null; members: SimMember[] }
export interface SimRunner { id: number; name: string; host: string | null; lag_ms: number; last_seen_at: string | null; alive: boolean }
export interface SimFinding { id: number; flow: string; step: string | null; severity: string; message: string; details: Record<string, unknown> | null; created_at: string }

export interface SimRunDetail { run: SimRun; teams: SimTeam[]; runners: SimRunner[]; report: SimReport; findings: SimFinding[] }

export interface SimComparison {
  totals: { p95_ms: number; baseline_p95_ms: number }
  endpoints: { name: string; p95_ms: number; baseline_p95_ms: number | null; change_ms: number | null; change_percent: number | null }[]
}

export interface SimControl { speed?: number; paused?: boolean; add_teams?: number; remove_teams?: number }

const data = async <T>(method: string, path: string, body?: unknown): Promise<T> =>
  (await request<ItemResponse<T>>(method, `/admin/sim${path}`, body === undefined ? {} : { body })).data

/** Null when the Traffic lab is switched off on this installation. */
export async function getStatus(): Promise<SimStatus | null> {
  try {
    return await data<SimStatus>('GET', '')
  } catch {
    return null
  }
}

export const listScenarios = () => data<SimScenario[]>('GET', '/scenarios')
export const createScenario = (name: string, config: Partial<SimConfig>) => data<SimScenario>('POST', '/scenarios', { name, config })
export const updateScenario = (id: number, name: string, config: Partial<SimConfig>) => data<SimScenario>('PATCH', `/scenarios/${id}`, { name, config })
export const deleteScenario = (id: number) => request<void>('DELETE', `/admin/sim/scenarios/${id}`)

export const listPacks = () => data<SimPack[]>('GET', '/packs')
export const getPack = (id: number) => data<SimPackContent>('GET', `/packs/${id}`)

export const listRuns = () => data<SimRun[]>('GET', '/runs')
/** Starts a run. The token is shown once; a runner started with it plays the run. */
export const startRun = (req: { name?: string; scenario_id?: number | null; config?: Partial<SimConfig> }) =>
  data<SimRun & { runner_token: string }>('POST', '/runs', req)
export const getRun = (id: number) => data<SimRunDetail>('GET', `/runs/${id}`)
export const controlRun = (id: number, change: SimControl) => data<SimRun>('PATCH', `/runs/${id}`, change)
export const stopRun = (id: number) => data<SimRun>('POST', `/runs/${id}/stop`)
export const purgeRun = (id: number, forget = false) => request<void>('DELETE', `/admin/sim/runs/${id}${forget ? '?forget=true' : ''}`)
export const compareRuns = (id: number, baseline: number) => data<SimComparison>('GET', `/runs/${id}/compare/${baseline}`)
