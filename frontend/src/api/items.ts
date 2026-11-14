import { v4 as uuidv4 } from 'uuid'
import { API_BASE_PATH, request, workspacePath } from './client'
import type {
  ActivityEntry, AgentRun, Attachment, SearchResult, ChecklistEntry, Comment, CreateItemRequest, Item, ItemDetail, ItemLink,
  ItemResponse, LinkKind, ListItemsParams, ListResponse, RelationType, UpdateItemRequest,
} from './types'

const itemsPath = (slug: string) => `${workspacePath(slug)}/items`
const itemPath = (slug: string, key: string) => `${itemsPath(slug)}/${encodeURIComponent(key)}`

/** Query string for the item list: empty values are left out so the API applies its defaults. */
export function itemListQuery(params: ListItemsParams = {}): string {
  const qs = new URLSearchParams()
  for (const [name, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') qs.set(name, String(value))
  }
  const query = qs.toString()
  return query ? `?${query}` : ''
}

export function listItems(slug: string, params?: ListItemsParams): Promise<ListResponse<Item>> {
  return request<ListResponse<Item>>('GET', `${itemsPath(slug)}${itemListQuery(params)}`)
}

export async function getItem(slug: string, key: string): Promise<ItemDetail> {
  return (await request<ItemResponse<ItemDetail>>('GET', itemPath(slug, key))).data
}

/** Each call is one logical create: retries of the same request must reuse `idempotencyKey`. */
export async function createItem(slug: string, req: CreateItemRequest, idempotencyKey = uuidv4()): Promise<ItemDetail> {
  const res = await request<ItemResponse<ItemDetail>>('POST', itemsPath(slug), {
    body: req,
    headers: { 'Idempotency-Key': idempotencyKey },
  })
  return res.data
}

/** Send only the changed fields plus `expected_version`; a stale version answers 412. */
export async function updateItem(slug: string, key: string, req: UpdateItemRequest): Promise<ItemDetail> {
  return (await request<ItemResponse<ItemDetail>>('PATCH', itemPath(slug, key), { body: req })).data
}

export async function deleteItem(slug: string, key: string): Promise<void> {
  await request<void>('DELETE', itemPath(slug, key))
}

export async function setSubscription(slug: string, key: string, subscribed: boolean): Promise<void> {
  await request<void>(subscribed ? 'PUT' : 'DELETE', `${itemPath(slug, key)}/subscription`)
}

// ── Comments and activity ────────────────────────────────────────────────────

function paged(cursor?: string | null, limit = 100): string {
  const qs = new URLSearchParams({ limit: String(limit) })
  if (cursor) qs.set('cursor', cursor)
  return `?${qs}`
}

export function listComments(slug: string, key: string, cursor?: string | null): Promise<ListResponse<Comment>> {
  return request<ListResponse<Comment>>('GET', `${itemPath(slug, key)}/comments${paged(cursor)}`)
}

export async function addComment(slug: string, key: string, body: string): Promise<Comment> {
  const res = await request<ItemResponse<Comment>>('POST', `${itemPath(slug, key)}/comments`, {
    body: { body },
    headers: { 'Idempotency-Key': uuidv4() },
  })
  return res.data
}

export async function updateComment(slug: string, key: string, id: number, body: string): Promise<Comment> {
  return (await request<ItemResponse<Comment>>('PATCH', `${itemPath(slug, key)}/comments/${id}`, { body: { body } })).data
}

export async function deleteComment(slug: string, key: string, id: number): Promise<void> {
  await request<void>('DELETE', `${itemPath(slug, key)}/comments/${id}`)
}

/** Items, comments and pages that contain every word of `q`. */
export async function search(slug: string, q: string): Promise<SearchResult[]> {
  return (await request<ItemResponse<SearchResult[]>>('GET', `/workspaces/${encodeURIComponent(slug)}/search?q=${encodeURIComponent(q)}&limit=6`)).data
}

export async function listRuns(slug: string, key: string): Promise<AgentRun[]> {
  return (await request<ItemResponse<AgentRun[]>>('GET', `${itemPath(slug, key)}/runs`)).data
}

export function listActivity(slug: string, key: string): Promise<ListResponse<ActivityEntry>> {
  return request<ListResponse<ActivityEntry>>('GET', `${itemPath(slug, key)}/activity?limit=100`)
}

// ── Checklist, links, relations ──────────────────────────────────────────────

export async function addChecklistEntry(slug: string, key: string, text: string): Promise<ChecklistEntry> {
  return (await request<ItemResponse<ChecklistEntry>>('POST', `${itemPath(slug, key)}/checklist`, { body: { text } })).data
}

export async function updateChecklistEntry(
  slug: string, key: string, id: number, req: Partial<Pick<ChecklistEntry, 'text' | 'is_done'>>,
): Promise<ChecklistEntry> {
  return (await request<ItemResponse<ChecklistEntry>>('PATCH', `${itemPath(slug, key)}/checklist/${id}`, { body: req })).data
}

export async function removeChecklistEntry(slug: string, key: string, id: number): Promise<void> {
  await request<void>('DELETE', `${itemPath(slug, key)}/checklist/${id}`)
}

export async function addLink(slug: string, key: string, req: { url: string; kind: LinkKind; title?: string | null }): Promise<ItemLink> {
  return (await request<ItemResponse<ItemLink>>('POST', `${itemPath(slug, key)}/links`, { body: req })).data
}

export async function removeLink(slug: string, key: string, id: number): Promise<void> {
  await request<void>('DELETE', `${itemPath(slug, key)}/links/${id}`)
}

/** `{type: "blocks", item: "WEB-7"}` means this item blocks WEB-7. */
export async function addRelation(slug: string, key: string, req: { type: Exclude<RelationType, 'blocked_by'>; item: string }): Promise<void> {
  await request<unknown>('POST', `${itemPath(slug, key)}/relations`, { body: req })
}

export async function removeRelation(slug: string, key: string, id: number): Promise<void> {
  await request<void>('DELETE', `${itemPath(slug, key)}/relations/${id}`)
}

// ── Attachments ──────────────────────────────────────────────────────────────

export async function uploadAttachment(slug: string, key: string, file: File): Promise<Attachment> {
  const body = new FormData()
  body.append('file', file)
  return (await request<ItemResponse<Attachment>>('POST', `${itemPath(slug, key)}/attachments`, { body })).data
}

export async function removeAttachment(slug: string, key: string, id: number): Promise<void> {
  await request<void>('DELETE', `${itemPath(slug, key)}/attachments/${id}`)
}

/** Same-origin URL served with the session cookie: images render inline, other files download. */
export function attachmentUrl(slug: string, key: string, id: number): string {
  return `${API_BASE_PATH}${itemPath(slug, key)}/attachments/${id}/download`
}
