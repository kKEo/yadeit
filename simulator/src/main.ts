import type { Tally } from './metrics.ts'
import { ControlError } from './control.ts'
import { type Outcome, run } from './runner.ts'

/**
 * The runner of the Traffic lab.
 *
 *   SIM_TOKEN=<token from sim:start> SIM_TARGET=http://localhost:8080 node src/main.ts [--smoke]
 *
 * With --standby the runner never ends: it waits for a run to be started (from the admin panel), plays it, and waits again.
 * SIM_TOKEN is then "standby|<SIMULATOR_RUNNER_TOKEN>".
 *
 * With --smoke the process ends with status 1 when the run did not go well
 * enough: too many failed actions or requests, a failed flow, or nothing done.
 */
const target = process.env.SIM_TARGET ?? 'http://localhost:8080'
const token = process.env.SIM_TOKEN ?? ''
const smoke = process.argv.includes('--smoke')
const maxErrorRate = Number(process.env.SIM_MAX_ERROR_RATE ?? '0.01')

if (token === '') {
  console.error('SIM_TOKEN is not set. Start a run (php artisan sim:start) and pass the token it prints.')
  process.exit(2)
}

const stop = new AbortController()
process.on('SIGINT', () => stop.abort())
process.on('SIGTERM', () => stop.abort())

const options = { target, token, name: process.env.SIM_NAME ?? 'runner', log: (line: string) => console.log(line) }

if (process.argv.includes('--standby')) {
  console.log(`Standing by for runs at ${target}.`)
  while (!stop.signal.aborted) {
    try {
      report(await run(options, stop.signal))
    } catch (error) {
      // 409: no run is going on. Anything else is worth a line, then the wait goes on.
      if (!(error instanceof ControlError && error.status === 409)) console.error(error instanceof Error ? error.message : String(error))
    }
    await new Promise((resolve) => setTimeout(resolve, 3000))
  }
  process.exit(0)
}

const outcome = await run(options, stop.signal)
const failures = report(outcome)

if (smoke && failures.length > 0) {
  console.error(`\nSmoke run failed:\n${failures.map((f) => `  - ${f}`).join('\n')}`)
  process.exit(1)
}
process.exit(0)

function report({ run: state, recorder, skipped, teams, members }: Outcome): string[] {
  const requests = recorder.totals(recorder.endpoints)
  const actions = recorder.totals(recorder.actions)
  const flows = recorder.totals(recorder.flows)
  const rate = (t: Tally) => (t.count === 0 ? 0 : t.errors / t.count)
  const mean = (t: Tally) => (t.count === 0 ? 0 : Math.round(t.totalMs / t.count))

  console.log(`\nRun ${state.id} is ${state.status}${state.stop_reason ? ` (${state.stop_reason})` : ''}. Played ${teams} teams, ${members} members.`)
  console.log(`Requests: ${requests.count}, failed ${requests.errors}, mean ${mean(requests)} ms, slowest ${Math.round(requests.maxMs)} ms`)
  console.log(`Actions:  ${actions.count}, failed ${actions.errors}; started late by at most ${Math.round(recorder.maxLagMs)} ms; ${skipped} skipped`)
  console.log(`Flows:    ${flows.count}, failed ${flows.errors}`)

  const slowest = [...recorder.endpoints].sort((a, b) => b[1].totalMs / b[1].count - a[1].totalMs / a[1].count).slice(0, 8)
  console.log('\nSlowest endpoints (mean ms, count):')
  for (const [name, tally] of slowest) console.log(`  ${String(mean(tally)).padStart(6)}  ${String(tally.count).padStart(6)}  ${name}`)

  if (recorder.problems.length > 0) {
    console.log(`\nProblems (${recorder.problems.length}):`)
    for (const problem of recorder.problems.slice(0, 15)) console.log(`  ${problem}`)
  }

  const failures: string[] = []
  if (actions.count === 0) failures.push('no action was carried out')
  if (rate(actions) > maxErrorRate) failures.push(`${(rate(actions) * 100).toFixed(1)}% of actions failed (limit ${maxErrorRate * 100}%)`)
  if (rate(requests) > maxErrorRate) failures.push(`${(rate(requests) * 100).toFixed(1)}% of requests failed (limit ${maxErrorRate * 100}%)`)
  if (flows.errors > 0) failures.push(`${flows.errors} flow(s) failed`)
  return failures
}
