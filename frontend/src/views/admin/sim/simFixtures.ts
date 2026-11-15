import type { SimConfig, SimReport, SimRun, SimRunDetail, SimStatus } from '@/api/simulator'

/** Sample answers of the Traffic lab API, for view tests. */
export const config: SimConfig = {
  teams: 3, size_min: 2, size_max: 6, speed: 10, duration_minutes: 10, day_mode: 'workday', ramp_up_seconds: 30, real_signup_share: 0.34,
  volume: 'small', intensity: 1, seed: 1, pack_id: null, thresholds: {}, disabled_actions: [], personas: {},
  ai: { director: false, explorers_per_team: 0, token_budget: 500000, replay_run_id: null },
}

export const status: SimStatus = {
  enabled: true, production: false, caps: { teams: 50, team_size: 10, speed: 100, duration_minutes: 240 }, speeds: [1, 2, 5, 10, 50, 100],
  volumes: ['empty', 'small', 'medium', 'large'], defaults: config, thresholds: { p95_ms: 1000, error_rate: 0.01, flow_success: 0.99 },
  standing_runner: false, ai_available: true, active_run_id: null,
}

export function makeRun(overrides: Partial<SimRun> = {}): SimRun {
  return {
    id: 7, name: 'Busy day', status: 'running', active: true, speed: 10, seed: 1, config, verdict: null, stop_reason: null, ai_tokens_used: 0, totals: null,
    purged: false, started_at: '2026-11-15T10:00:00Z', finished_at: null, ...overrides,
  }
}

const row = (name: string, errors = 0, p95 = 70) => ({ name, count: 40, errors, mean_ms: 40, p50_ms: 30, p95_ms: p95, p99_ms: p95 + 20, max_ms: p95 + 30, server_ms: 25, db_ms: 9, queries: 14.5 })

export function makeReport(overrides: Partial<SimReport> = {}): SimReport {
  return {
    verdict: 'pass', reasons: [], runner_saturated: false, thresholds: status.thresholds,
    totals: {
      requests: 800, failed_requests: 0, error_rate: 0, requests_per_second: 6.7, p50_ms: 50, p95_ms: 70, p99_ms: 300, max_ms: 380,
      actions: 240, failed_actions: 0, flows: 6, failed_flows: 0, findings: 0, runner_lag_p95_ms: 100, oldest_job_seconds: 0,
    },
    endpoints: [row('GET /api/v1/workspaces/{workspace}/items'), row('POST /api/v1/auth/login', 0, 285)],
    actions: [row('openItems')],
    flows: [row('itemLifecycle', 0, 300), row('createToSearch', 0, 98)],
    timeline: [0, 1, 2].map((step) => ({
      at: 1000 + step * 10, requests_per_second: 5 + step, errors: 0, p50_ms: 40, p95_ms: 70, p99_ms: 200, failed_flows: 0,
      runner_lag_ms: 50, jobs_waiting: step, oldest_job_seconds: 0,
    })),
    ...overrides,
  }
}

export function makeDetail(run: SimRun = makeRun(), report: SimReport = makeReport()): SimRunDetail {
  return {
    run, report,
    teams: [
      { id: 1, name: 'Northwind Studio 7-1', size: 2, onboarding: 'fast', status: 'ready', workspace: 'northwind', members: [
        { id: 11, name: 'Anna Kowalska', persona: 'lead', role: 'owner', kind: 'scripted', action: 'openItems' },
        { id: 12, name: 'Ben Carter', persona: 'developer', role: 'member', kind: 'scripted', action: 'idle' },
      ] },
      { id: 2, name: 'Kiln and Co 7-2', size: 1, onboarding: 'real', status: 'awaiting_signup', workspace: null, members: [
        { id: 13, name: 'Chloe Martin', persona: 'lead', role: 'owner', kind: 'scripted', action: null },
      ] },
    ],
    runners: [{ id: 1, name: 'standing', host: 'box', lag_ms: 40, last_seen_at: '2026-11-15T10:01:00Z', alive: true }],
    findings: [],
  }
}
