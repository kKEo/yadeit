import { request, workspacePath } from './client'
import type { InboxEntry, InboxPage, UpdateInboxRequest } from './types'

const inboxPath = (slug: string) => `${workspacePath(slug)}/inbox`

export function listInbox(slug: string, opts: { unread?: boolean; cursor?: string | null; limit?: number } = {}): Promise<InboxPage> {
  const qs = new URLSearchParams()
  if (opts.unread) qs.set('unread', '1')
  if (opts.limit != null) qs.set('limit', String(opts.limit))
  if (opts.cursor) qs.set('cursor', opts.cursor)
  const query = qs.toString()
  return request<InboxPage>('GET', `${inboxPath(slug)}${query ? `?${query}` : ''}`)
}

export function updateInboxEntry(
  slug: string, id: number, req: UpdateInboxRequest,
): Promise<{ data: InboxEntry; unread_count: number }> {
  return request<{ data: InboxEntry; unread_count: number }>('PATCH', `${inboxPath(slug)}/${id}`, { body: req })
}

export function markAllInboxRead(slug: string): Promise<{ unread_count: number }> {
  return request<{ unread_count: number }>('POST', `${inboxPath(slug)}/read-all`)
}

export async function deleteInboxEntry(slug: string, id: number): Promise<void> {
  await request<void>('DELETE', `${inboxPath(slug)}/${id}`)
}
