import { randomBytes, randomUUID } from 'node:crypto'
import type { DocPage, Feedback, InboxPage, ItemDetail, ItemResponse, ListResponse } from '../../frontend/src/api/types.ts'
import { loadProjects } from './actions.ts'
import type { Member } from './member.ts'

/** A check inside a flow did not hold. */
export class CheckFailure extends Error {
  readonly details: Record<string, unknown>

  constructor(message: string, details: Record<string, unknown> = {}) {
    super(message)
    this.details = details
  }
}

type Steps = (label: string) => void

export const SKIP = Symbol('skip')
/** Makes one check fail on purpose, to see that a broken flow reaches the report: SIM_SELF_TEST=1. */
const SELF_TEST = process.env.SIM_SELF_TEST === '1'

/**
 * Runs a journey and records whether it completed and how long it took. A
 * failed check or request becomes a finding that names the step; the member
 * then carries on. Returning SKIP means the journey could not be tried here
 * (no teammate signed in, no other team) and nothing is recorded.
 */
export async function flow(m: Pick<Member, 'recorder' | 'team'> & { plan: { id: number } }, name: string, body: (step: Steps) => Promise<typeof SKIP | void>): Promise<void> {
  const started = performance.now()
  let current = 'start'

  try {
    if ((await body((label) => { current = label })) === SKIP) return
    m.recorder.flow(name, performance.now() - started, true)
  } catch (error) {
    m.recorder.flow(name, performance.now() - started, false)
    m.recorder.finding({
      flow: name, step: current, severity: 'error', team_id: m.team.id, member_id: m.plan.id,
      message: error instanceof Error ? error.message : String(error),
      details: error instanceof CheckFailure ? error.details : undefined,
    })
  }
}

/** Asks again until `found` returns something, like a person refreshing a page; gives up after `seconds` of wall time. */
export async function eventually<T>(seconds: number, what: string, look: () => Promise<T | undefined | null | false>): Promise<T> {
  const deadline = Date.now() + seconds * 1000
  for (;;) {
    const result = await look()
    if (result) return result
    if (Date.now() > deadline) throw new CheckFailure(`${what} within ${seconds} s`)
    await new Promise((resolve) => setTimeout(resolve, 500))
  }
}

export const tag = () => `zq${randomBytes(4).toString('hex')}`

async function createItem(m: Member, title: string, extra: Record<string, unknown> = {}): Promise<ItemDetail> {
  const project = m.dataRng.pick(await loadProjects(m))
  if (!project) throw new CheckFailure('The workspace has no project to create an item in')
  return (await m.session.post<ItemResponse<ItemDetail>>(`${m.R}/items`, { project: project.key, title, ...extra }, { headers: { 'Idempotency-Key': randomUUID() } })).data.data
}

export const FLOWS = {
  /** An item from creation to done; every change is read back, and a change based on an old version must be refused. */
  flowLifecycle: (m: Member) => flow(m, 'itemLifecycle', async (step) => {
    step('create')
    let item = await createItem(m, `Lifecycle check ${tag()}`)
    const path = `${m.R}/items/${item.key}`

    for (const state of ['In progress', 'In review', 'Done']) {
      step(`move to ${state}`)
      // A teammate, an agent or the nightly rollover may touch the item in between: then the app reloads and the person tries again.
      const first = await m.session.patch(path, { state, expected_version: item.version }, { allow: [412] })
      if (first.status === 412) {
        item = (await m.session.get<ItemResponse<ItemDetail>>(path)).data.data
        await m.session.patch(path, { state, expected_version: item.version })
      }
      const read = (await m.session.get<ItemResponse<ItemDetail>>(path)).data.data
      if (read.state.name !== state) throw new CheckFailure(`Wrote state "${state}" but read back "${read.state.name}"`, { item: item.key })
      if (read.version <= item.version) throw new CheckFailure('The version did not increase after a change', { item: item.key, before: item.version, after: read.version })
      item = read
    }

    step('completed')
    if (item.completed_at === null) throw new CheckFailure('An item in Done has no completion time', { item: item.key })

    step('stale version')
    const stale = await m.session.patch(path, { priority: 'low', expected_version: 1 }, { allow: [412] })
    if (stale.status !== 412) throw new CheckFailure(`A change based on version 1 of an item at version ${item.version} was accepted`, { item: item.key, status: stale.status })
  }),

  /** Assigning an item to a teammate must reach that teammate's inbox. The time it takes is what users feel of the queue. */
  flowNotify: (m: Member) => flow(m, 'assignToInbox', async (step) => {
    const mate = m.team.members.find((other) => other !== m && other.signedIn && other.me !== null && other.canWrite)
    if (!mate) return SKIP

    step('assign')
    const item = await createItem(m, `Please look at this ${tag()}`, { assignee: mate.me!.id })

    step('inbox')
    await eventually(15, `No inbox entry reached ${mate.plan.email}`, async () =>
      (await mate.session.get<InboxPage>(`${mate.R}/inbox?unread=1`)).data.data.some((entry) => entry.item.key === item.key))
  }),

  /** What was just written must be findable. */
  flowSearch: (m: Member) => flow(m, 'createToSearch', async (step) => {
    const word = tag()
    step('create')
    const item = await createItem(m, `Findable ${word}`)

    step('find')
    const q = SELF_TEST ? `${word}x` : word
    await eventually(SELF_TEST ? 2 : 10, `Search for "${q}" did not find ${item.key}`, async () =>
      JSON.stringify((await m.session.get(`${m.R}/search?q=${q}&limit=6`)).data).includes(`"${item.key}"`))
  }),

  /** Feedback from anyone, turned into a work item by the lead; the item then counts one request. */
  flowFeedback: (m: Member) => flow(m, 'feedbackToItem', async (step) => {
    const lead = m.team.members.find((other) => other.signedIn && other.isAdmin)
    const project = m.dataRng.pick(await loadProjects(m))
    if (!lead || !project) return SKIP

    step('send')
    const title = `Feedback check ${tag()}`
    await m.session.post(`${m.R}/feedback`, { project: project.key, title, body: 'Sent by a flow check.' })

    step('find in inbox')
    const waiting = (await lead.session.get<ListResponse<Feedback>>(`${lead.R}/feedback?limit=100&status=new`)).data.data
    const entry = waiting.find((f) => f.title === title)
    if (!entry) throw new CheckFailure('The feedback did not appear in the feedback inbox', { title })

    step('accept')
    const accepted = (await lead.session.post<ItemResponse<Feedback>>(`${lead.R}/feedback/${entry.id}/accept`, {})).data.data
    if (accepted.status !== 'accepted' || accepted.item === null) throw new CheckFailure('Accepting feedback did not link it to an item', { feedback: entry.id })
    if (accepted.item.demand < 1) throw new CheckFailure('The item made from feedback shows no demand', { item: accepted.item.key, demand: accepted.item.demand })
  }),

  /** A page published and shared by link opens signed out, and stops opening once the link is withdrawn. */
  flowDoc: (m: Member) => flow(m, 'docPublicLink', async (step) => {
    step('create')
    const title = `Public page check ${tag()}`
    let page = (await m.session.post<ItemResponse<DocPage>>(`${m.R}/pages`, { title, body: 'Visible to anyone with the link.' })).data.data

    step('publish and share')
    page = (await m.session.patch<ItemResponse<DocPage>>(`${m.R}/pages/${page.id}`, { status: 'published', public: true, expected_version: page.version })).data.data
    if (!page.public_token) throw new CheckFailure('A shared page has no link', { page: page.id })

    step('read signed out')
    const open = await m.team.visitor.get<ItemResponse<{ title: string }>>(`/public/pages/${page.public_token}`)
    if (open.data.data.title !== title) throw new CheckFailure('The public link shows another page', { page: page.id })

    step('withdraw')
    await m.session.patch(`${m.R}/pages/${page.id}`, { public: false, expected_version: page.version })
    const closed = await m.team.visitor.get(`/public/pages/${page.public_token}`, { allow: [404] })
    if (closed.status !== 404) throw new CheckFailure('The page still opens signed out after the link was withdrawn', { page: page.id, status: closed.status })
  }),

  /** A change by one member is announced to a teammate's open browser over the websocket. */
  flowRealtime: (m: Member) => flow(m, 'changeToSocket', async (step) => {
    const mate = m.team.members.find((other) => other !== m && other.socket?.ready)
    if (!mate) return SKIP

    step('change')
    const item = await createItem(m, `Realtime check ${tag()}`)
    const since = Date.now() - 2000

    step('announcement')
    await eventually(10, `No announcement of ${item.key} reached ${mate.plan.email}`, async () =>
      mate.heard.some((event) => event.key === item.key && event.at >= since))
  }),

  /** Nobody sees another team's workspace, and a guest cannot write. Runs all the time, as a canary. */
  flowIsolation: (m: Member) => flow(m, 'isolation', async (step) => {
    const other = m.team.others().find((team) => team.slug !== null)
    if (!other && m.canWrite) return SKIP

    if (other) {
      step('another workspace')
      for (const path of [`/workspaces/${other.slug}`, `/workspaces/${other.slug}/items?limit=1`]) {
        const reply = await m.session.get(path, { allow: [404] })
        if (reply.status !== 404) throw new CheckFailure(`A member of ${m.team.name} could read ${path} of ${other.name}`, { status: reply.status })
      }
    }

    if (!m.canWrite) {
      step('guest write')
      const project = m.dataRng.pick(await loadProjects(m))
      if (!project) return
      const reply = await m.session.post(`${m.R}/items`, { project: project.key, title: 'A guest must not create this' }, { allow: [403] })
      if (reply.status !== 403) throw new CheckFailure('A guest could create an item', { status: reply.status })
    }
  }),
} satisfies Record<string, (m: Member) => Promise<unknown>>
