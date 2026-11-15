import { describe, expect, it } from 'vitest'
import { ACTIONS } from './actions.ts'
import type { MemberPlan } from './control.ts'
import { Member } from './member.ts'
import { Recorder } from './metrics.ts'
import { PERSONAS, weightsFor } from './personas.ts'
import { Rng } from './rng.ts'
import { endpointName, Session } from './session.ts'

const pack = { projects: [], comments: [], pages: [], feedback: [] }

function member(id: number, persona: string, role: MemberPlan['role'], seed = 7): Member {
  const plan: MemberPlan = { id, name: 'A', persona, role, kind: 'scripted', email: `m${id}@sim.invalid`, password: 'x', header: 'h' }
  const recorder = new Recorder()
  return new Member(plan, { id: 1, name: 'T', slug: 't', pack }, new Session('http://localhost', 'http://localhost', 'h', recorder), recorder, seed, 1)
}

const steps = (m: Member, n = 200) => Array.from({ length: n }, () => m.next())

describe('what a member does next', () => {
  it('is the same sequence for the same seed', () => {
    expect(steps(member(3, 'developer', 'member'))).toEqual(steps(member(3, 'developer', 'member')))
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

describe('Rng', () => {
  it('repeats for a seed and respects weights', () => {
    expect(new Rng(1).next()).toBe(new Rng(1).next())
    const rng = new Rng(2)
    const picks = Array.from({ length: 1000 }, () => rng.weighted({ a: 9, b: 1 }))
    expect(picks.filter((p) => p === 'a').length).toBeGreaterThan(820)
  })
})
