import { randomUUID } from 'node:crypto'
import type {
  Comment, DocPage, DocPageSummary, Feedback, InboxPage, Item, ItemDetail, ItemResponse, ListResponse, Me,
  Member as WorkspaceMember, Project, ProjectDetail,
} from '../../frontend/src/api/types.ts'
import type { Member } from './member.ts'

/**
 * Everything a simulated person can do. Each action sends the requests the
 * web app sends for the same thing (see docs/traffic-lab.md), in the same
 * order, in parallel where the app sends them in parallel.
 */
export const ACTIONS = {
  /** Sign in, then what the app loads when it starts. */
  async signIn(m: Member) {
    await m.session.csrfCookie()
    await m.session.post('/auth/login', { email: m.plan.email, password: m.plan.password })
    m.signedIn = true
    await ACTIONS.appStart(m)
  },

  /** A page reload: the account, then the workspace shell and the items page together. */
  async appStart(m: Member) {
    m.me = (await m.session.get<ItemResponse<Me>>('/me')).data.data
    const [, , , people] = await Promise.all([
      m.session.get(m.R),
      m.session.get(`${m.R}/views`),
      m.session.get(`${m.R}/inbox?unread=1&limit=1`),
      m.session.get<ListResponse<WorkspaceMember>>(`${m.R}/members`),
      ACTIONS.openItems(m),
    ])
    m.people = people.data.data
  },

  async openItems(m: Member) {
    const [items] = await Promise.all([listItems(m, ''), loadProjects(m)])
    m.items = items
  },

  /** The built-in "assigned to me" view. */
  async openMine(m: Member) {
    await listItems(m, '&assignee=me&open=1')
  },

  /** Narrowing the list to one project, which also loads that project's workflow. */
  async filterItems(m: Member) {
    const project = m.dataRng.pick(await loadProjects(m))
    if (!project) return
    const [items] = await Promise.all([listItems(m, `&project=${project.key}`), m.session.get(`${m.R}/projects/${project.key}`)])
    if (items.length > 0) m.items = items
  },

  /** The side panel: the item first, then its thread and what the property pickers need. */
  async openItem(m: Member) {
    await openDetail(m, await someItem(m))
  },

  async createItem(m: Member) {
    const project = m.dataRng.pick(await loadProjects(m))
    const source = m.dataRng.pick(m.team.pack.projects.flatMap((p) => p.items))
    if (!project || !source) return

    const created = await m.session.post<ItemResponse<ItemDetail>>(`${m.R}/items`, {
      project: project.key,
      title: `${source.title} ${m.dataRng.int(100, 999)}`,
      description: source.description ?? null,
      kind: source.kind ?? 'task',
      priority: source.priority ?? 'none',
    }, { headers: { 'Idempotency-Key': randomUUID() } })
    m.items.unshift(created.data.data)
  },

  /** Moving work forward: started, then in review, then done. */
  async moveItem(m: Member) {
    const open = m.items.filter((i) => i.state.group !== 'completed' && i.state.group !== 'cancelled')
    const detail = await openDetail(m, m.dataRng.pick(open) ?? (await someItem(m)))
    if (!detail) return

    const state = detail.state.group === 'started' ? (detail.state.name === 'In review' ? 'Done' : 'In review') : 'In progress'
    await change(m, detail, { state })
  },

  async editItem(m: Member) {
    const detail = await openDetail(m, await someItem(m))
    if (!detail) return

    const writers = m.people.filter((p) => p.type === 'human' && p.role !== 'guest')
    const changes = m.dataRng.chance(0.5) || writers.length === 0
      ? { priority: m.dataRng.pick(['low', 'medium', 'high', 'urgent'] as const) }
      : { assignee: m.dataRng.pick(writers)!.id }
    await change(m, detail, changes)
  },

  async comment(m: Member) {
    const item = await someItem(m)
    const body = m.dataRng.pick(m.team.pack.comments)
    if (!item || !body) return

    await openDetail(m, item)
    await m.session.post(`${m.R}/items/${item.key}/comments`, { body }, { headers: { 'Idempotency-Key': randomUUID() } })
    await Promise.all([m.session.get(`${m.R}/items/${item.key}/comments?limit=100`), m.session.get(`${m.R}/items/${item.key}`)])
  },

  async attach(m: Member) {
    const item = await someItem(m)
    if (!item) return

    const form = new FormData()
    form.append('file', new Blob([`Notes for ${item.key}\n`.repeat(m.dataRng.int(20, 400))], { type: 'text/plain' }), 'notes.txt')
    await m.session.request('POST', `${m.R}/items/${item.key}/attachments`, { form })
    await m.session.get(`${m.R}/items/${item.key}`)
  },

  /** Reading the inbox, opening what is new, now and then clearing it. */
  async inbox(m: Member) {
    const page = (await m.session.get<InboxPage>(`${m.R}/inbox`)).data
    const unread = page.data.find((entry) => !entry.read)

    if (unread) {
      await m.session.patch(`${m.R}/inbox/${unread.id}`, { read: true })
      await openDetail(m, { key: unread.item.key, project: { key: unread.item.key.split('-')[0]! } })
    } else if (page.unread_count > 0 && m.dataRng.chance(0.3)) {
      await m.session.post(`${m.R}/inbox/read-all`)
    }
  },

  /** Typing into the command palette: a first try, then a longer one. */
  async search(m: Member) {
    const words = (m.dataRng.pick(m.team.pack.projects.flatMap((p) => p.items))?.title ?? 'search').toLowerCase().split(/\s+/).filter((w) => w.length > 3)
    const word = m.dataRng.pick(words) ?? 'item'
    await m.session.get(`${m.R}/search?q=${encodeURIComponent(word.slice(0, 3))}&limit=6`)
    await m.session.get(`${m.R}/search?q=${encodeURIComponent(word)}&limit=6`)
  },

  async docs(m: Member) {
    await loadProjects(m, true)
    m.pages = (await m.session.get<ListResponse<DocPageSummary>>(`${m.R}/pages?limit=100`)).data.data
  },

  async openDoc(m: Member) {
    if (m.pages.length === 0) await ACTIONS.docs(m)
    const page = m.dataRng.pick(m.pages)
    if (page) await Promise.all([m.session.get(`${m.R}/pages/${page.id}`), loadProjects(m, true)])
  },

  async editDoc(m: Member) {
    if (m.pages.length === 0) await ACTIONS.docs(m)
    const summary = m.dataRng.pick(m.pages)
    if (!summary) return

    const page = (await m.session.get<ItemResponse<DocPage>>(`${m.R}/pages/${summary.id}`)).data.data
    const note = m.dataRng.pick(m.team.pack.comments) ?? 'Updated.'
    // Someone else saving first is an ordinary answer (412); the app then shows the newer text.
    await m.session.patch(`${m.R}/pages/${page.id}`, { body: `${page.body ?? ''}\n\n${note}`, expected_version: page.version }, { allow: [412] })
  },

  async createDoc(m: Member) {
    const source = m.dataRng.pick(m.team.pack.pages)
    if (!source) return

    const page = (await m.session.post<ItemResponse<DocPage>>(`${m.R}/pages`, { title: `${source.title} ${m.dataRng.int(2, 99)}`, body: source.body })).data.data
    if (m.dataRng.chance(0.6)) await m.session.patch(`${m.R}/pages/${page.id}`, { status: 'published', expected_version: page.version }, { allow: [412] })
    m.pages.unshift(page)
  },

  async feedbackList(m: Member) {
    await loadProjects(m, true)
    m.feedback = (await m.session.get<ListResponse<Feedback>>(`${m.R}/feedback?limit=100&status=new`)).data.data
  },

  async sendFeedback(m: Member) {
    const project = m.dataRng.pick(await loadProjects(m))
    const source = m.dataRng.pick(m.team.pack.feedback)
    if (project && source) await m.session.post(`${m.R}/feedback`, { project: project.key, title: source.title, body: source.body })
  },

  /** Working through the feedback inbox: most becomes an item, some is declined. */
  async triage(m: Member) {
    await ACTIONS.feedbackList(m)
    const entry = m.feedback.shift()
    if (!entry) return

    // A teammate may have decided it a moment ago (409).
    if (m.dataRng.chance(0.75)) await m.session.post(`${m.R}/feedback/${entry.id}/accept`, {}, { allow: [409, 422] })
    else await m.session.post(`${m.R}/feedback/${entry.id}/decline`, { reason: 'Not planned for now.' }, { allow: [409, 422] })
  },

  async members(m: Member) {
    const [people] = await Promise.all([
      m.session.get<ListResponse<WorkspaceMember>>(`${m.R}/members`),
      m.isAdmin ? m.session.get(`${m.R}/invitations`) : null,
    ])
    m.people = people.data.data
  },

  /** The settings page of an admin loads four things at once. */
  async settings(m: Member) {
    await Promise.all(['billing', 'ai', 'webhooks', 'audit'].map((part) => m.session.get(`${m.R}/${part}`)))
  },
} satisfies Record<string, (m: Member) => Promise<unknown>>

export type ActionName = Exclude<keyof typeof ACTIONS, 'signIn'>

async function listItems(m: Member, filters: string): Promise<Item[]> {
  return (await m.session.get<ListResponse<Item>>(`${m.R}/items?limit=50${filters}&sort=-updated_at`)).data.data
}

/** The project list is cached by the app for a few seconds; here it is fetched when missing or when a page always asks for it. */
async function loadProjects(m: Member, always = false): Promise<Project[]> {
  if (always || m.projects.length === 0) m.projects = (await m.session.get<ListResponse<Project>>(`${m.R}/projects`)).data.data
  return m.projects
}

async function someItem(m: Member): Promise<Item | undefined> {
  if (m.items.length === 0) await ACTIONS.openItems(m)
  return m.dataRng.pick(m.items)
}

async function openDetail(m: Member, item: { key: string; project: { key: string } } | undefined): Promise<ItemDetail | null> {
  if (!item) return null

  // Deleted by a teammate since the list was loaded: the app shows "not found" and moves on.
  const reply = await m.session.get<ItemResponse<ItemDetail>>(`${m.R}/items/${item.key}`, { allow: [404] })
  if (reply.status === 404) return null

  await Promise.all([
    m.session.get<ListResponse<Comment>>(`${m.R}/items/${item.key}/comments?limit=100`),
    m.session.get<ItemResponse<ProjectDetail>>(`${m.R}/projects/${item.project.key}`),
    m.session.get(`${m.R}/ai`),
  ])
  return reply.data.data
}

/** A change with the version the person saw. When a teammate was faster (412) the app reloads and the person tries once more. */
async function change(m: Member, detail: ItemDetail, changes: Record<string, unknown>): Promise<void> {
  const path = `${m.R}/items/${detail.key}`
  const first = await m.session.patch(path, { ...changes, expected_version: detail.version }, { allow: [412] })
  if (first.status !== 412) return

  const fresh = (await m.session.get<ItemResponse<ItemDetail>>(path)).data.data
  await m.session.patch(path, { ...changes, expected_version: fresh.version }, { allow: [412] })
}
