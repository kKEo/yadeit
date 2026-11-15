import { describe, expect, it } from 'vitest'
import { ACTIONS } from './catalogue.ts'
import type { MemberPlan } from './control.ts'
import { Member } from './member.ts'
import { Recorder } from './metrics.ts'
import { PERSONAS, weightsFor } from './personas.ts'
import { Rng } from './rng.ts'
import { slot } from './metrics.ts'
import { endpointName, serverTiming, Session } from './session.ts'

const pack = { projects: [], comments: [], pages: [], feedback: [] }

function member(id: number, persona: string, role: MemberPlan['role'], seed = 7, run = 1, config: ConstructorParameters<typeof Member>[5] = { intensity: 1 }): Member {
  const plan: MemberPlan = { id: id + run * 1000, name: 'A', persona, role, kind: 'scripted', email: `r${run}-t1-m${id}@sim.invalid`, password: 'x', header: 'h' }
  const recorder = new Recorder([10, 100], 10)
  return new Member(plan, { id: 1, name: 'T', slug: 't', pack, members: [], visitor: new Session('http://localhost', 'http://localhost', 'v', recorder), newVisitor: () => new Session('http://localhost', 'http://localhost', 'v', recorder), others: () => [], realtime: null, workspaceId: 1, agents: [], feedbackToken: null, publicPages: [], sinkUrl: '', decide: async () => ({ action: 'search', stuck: 'Nothing to do here' }), explorerSlowdown: () => 4 }, new Session('http://localhost', 'http://localhost', 'h', recorder), recorder, seed, config)
}

const steps = (m: Member, n = 200) => Array.from({ length: n }, () => m.next())

describe('what a member does next', () => {
  it('is the same sequence for the same seed', () => {
    expect(steps(member(3, 'developer', 'member'))).toEqual(steps(member(3, 'developer', 'member')))
  })

  it('does not depend on the run or on database ids, only on the seed and the place in the team', () => {
    expect(steps(member(3, 'developer', 'member', 7, 1))).toEqual(steps(member(3, 'developer', 'member', 7, 2)))
  })

  it('differs between members and between seeds', () => {
    const one = steps(member(3, 'developer', 'member'))
    expect(steps(member(4, 'developer', 'member'))).not.toEqual(one)
    expect(steps(member(3, 'developer', 'member', 8))).not.toEqual(one)
  })

  it('reads far more often than it writes', () => {
    const writes = ['createItem', 'moveItem', 'editItem', 'comment', 'attach', 'editDoc', 'createDoc', 'sendFeedback', 'triage']
    const drawn = steps(member(5, 'developer', 'member'), 3000)
    const share = drawn.filter((s) => writes.includes(s.action)).length / drawn.length
    expect(share).toBeGreaterThan(0.1)
    expect(share).toBeLessThan(0.3)
  })

  it('never has a guest try what guests may not do, nor a member open admin settings', () => {
    const guest = new Set(steps(member(6, 'client', 'guest'), 2000).map((s) => s.action))
    for (const action of ['createItem', 'moveItem', 'editItem', 'attach', 'triage', 'settings']) expect(guest.has(action as never)).toBe(false)
    expect(Object.keys(weightsFor(PERSONAS.lead!, 'member'))).not.toContain('settings')
    expect(Object.keys(weightsFor(PERSONAS.lead!, 'admin'))).toContain('settings')
  })

  it('follows what the scenario switches off and reweights', () => {
    const quiet = new Set(steps(member(8, 'developer', 'member', 7, 1, { intensity: 1, disabled_actions: ['flowLifecycle', 'comment'] }), 3000).map((s) => s.action))
    expect(quiet.has('flowLifecycle')).toBe(false)
    expect(quiet.has('comment')).toBe(false)

    const only = steps(member(8, 'client', 'guest', 7, 1, { intensity: 1, personas: { client: { pause: 10, weights: { search: 100000 } } } }), 300)
    expect(only.filter((s) => s.action === 'search').length).toBeGreaterThan(290)
    expect(only.reduce((sum, s) => sum + s.pause, 0) / only.length).toBeLessThan(15)
  })

  it('does what the director asked first, with the director\'s words, and then carries on as the seed says', () => {
    const plain = steps(member(3, 'developer', 'member'), 20)
    const led = member(3, 'developer', 'member')
    led.directed.push({ action: 'createItem', text: 'Fix the export of invoices' }, { action: 'comment', text: null })

    expect(led.next().action).toBe('createItem')
    expect(led.note).toBe('Fix the export of invoices')
    expect(led.next().action).toBe('comment')
    expect(led.note).toBeNull()
    expect(steps(led, 18)).toEqual(plain.slice(2))
    expect(led.allows('settings')).toBe(false)
  })

  it('lets an explorer be slower than the run, and follow the model\'s choice', async () => {
    const explorer = member(4, 'developer', 'member')
    ;(explorer.plan as { kind: string }).kind = 'ai'
    const scripted = member(4, 'developer', 'member')
    expect(explorer.next().pause).toBe(scripted.next().pause * 4)

    explorer.signedIn = true
    await explorer.act('openItems')
    // The stand-in model chose "search" and said it was stuck; the request itself fails here, which is recorded too.
    expect([...explorer.recorder.actions.keys()]).toEqual(['search'])
    expect(explorer.recorder.drain().findings.map((f) => f.flow)).toContain('explorer')
  })

  it('pauses around the persona\'s mean', () => {
    const pauses = steps(member(7, 'developer', 'member'), 5000).map((s) => s.pause)
    const mean = pauses.reduce((a, b) => a + b, 0) / pauses.length
    expect(mean).toBeGreaterThan(45)
    expect(mean).toBeLessThan(57)
  })
})

describe('the catalogue', () => {
  it('has an action behind every weight of every persona', () => {
    for (const persona of Object.values(PERSONAS)) {
      for (const name of Object.keys(persona.weights)) expect(ACTIONS).toHaveProperty(name)
    }
  })

  it('names endpoints without what differs between teams and items', () => {
    expect(endpointName('/api/v1/workspaces/acme-2/items/WEB-12/comments?limit=100')).toBe('/api/v1/workspaces/{workspace}/items/{item}/comments')
    expect(endpointName('/api/v1/workspaces/acme/projects/WEB')).toBe('/api/v1/workspaces/{workspace}/projects/{project}')
    expect(endpointName('/api/v1/workspaces/acme/inbox/41')).toBe('/api/v1/workspaces/{workspace}/inbox/{id}')
    expect(endpointName('/api/v1/invitations/AbCdEfGhIjKlMnOpQrStUvWxYz012345/accept')).toBe('/api/v1/invitations/{token}/accept')
    expect(endpointName('/api/v1/me')).toBe('/api/v1/me')
  })
})

describe('Recorder', () => {
  it('sorts latencies into the buckets the coordinator gave it', () => {
    const bounds = [10, 100, 1000]
    expect([5, 10, 11, 1000, 5000].map((ms) => slot(bounds, ms))).toEqual([0, 0, 1, 2, 3])
  })

  it('hands over what it measured once, with the server\'s own timing added up', () => {
    const recorder = new Recorder([10, 100], 10)
    recorder.endpoint('GET /x', 5, true, { app: 3, db: 1, queries: 4 })
    recorder.endpoint('GET /x', 50, false, { app: 30, db: 10, queries: 6 })
    recorder.flow('itemLifecycle', 400, false)
    recorder.finding({ flow: 'itemLifecycle', step: 'create', severity: 'error', message: 'broken' })

    const report = recorder.drain()
    const x = report.buckets.find((b) => b.name === 'GET /x')!
    expect(x).toMatchObject({ kind: 'endpoint', count: 2, errors: 1, max_ms: 50, histogram: [1, 1, 0], extra: { timed: 2, server_ms: 33, db_ms: 11, queries: 10 } })
    expect(report.findings).toHaveLength(1)
    expect(recorder.drain()).toEqual({ buckets: [], findings: [] })
    // The running totals for the summary are kept.
    expect(recorder.totals(recorder.endpoints).count).toBe(2)
  })

  it('reads the Server-Timing header', () => {
    expect(serverTiming('app;dur=12.5, db;dur=4.0, queries;desc="7"')).toEqual({ app: 12.5, db: 4, queries: 7 })
    expect(serverTiming(null)).toBeUndefined()
  })
})

describe('Rng', () => {
  it('repeats for a seed and respects weights', () => {
    expect(new Rng(1).next()).toBe(new Rng(1).next())
    const rng = new Rng(2)
    const picks = Array.from({ length: 1000 }, () => rng.weighted({ a: 9, b: 1 }))
    expect(picks.filter((p) => p === 'a').length).toBeGreaterThan(820)
  })
})
