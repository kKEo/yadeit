import { request, workspacePath } from './client'
import type {
  AdminOverview, AdminUser, HealthReport, AdminWorkspace, AuditEntry, Broadcast, BroadcastFilter, BroadcastPreview, CursorPage, ItemResponse,
  MailPage, MailStatus, OutgoingEmail, OutgoingEmailDetail,
} from './types'

type Query = Record<string, string | number | boolean | null | undefined>

function withQuery(path: string, query: Query): string {
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(query)) {
    if (value !== undefined && value !== null && value !== '') params.set(key, String(value))
  }
  const text = params.toString()
  return text ? `${path}?${text}` : path
}

export async function getHealth(): Promise<HealthReport> {
  return (await request<ItemResponse<HealthReport>>('GET', '/admin/health')).data
}

export async function getOverview(): Promise<AdminOverview> {
  return (await request<ItemResponse<AdminOverview>>('GET', '/admin/overview')).data
}

export interface AdminListQuery { q?: string; suspended?: boolean | null; cursor?: string | null }

export async function listWorkspaces(query: AdminListQuery = {}): Promise<CursorPage<AdminWorkspace>> {
  return request<CursorPage<AdminWorkspace>>('GET', withQuery('/admin/workspaces', { ...query }))
}

export async function updateWorkspace(id: number, changes: { suspended?: boolean; plan?: string }): Promise<AdminWorkspace> {
  return (await request<ItemResponse<AdminWorkspace>>('PATCH', `/admin/workspaces/${id}`, { body: changes })).data
}

export async function listUsers(query: AdminListQuery = {}): Promise<CursorPage<AdminUser>> {
  return request<CursorPage<AdminUser>>('GET', withQuery('/admin/users', { ...query }))
}

export async function setUserSuspended(id: number, suspended: boolean): Promise<AdminUser> {
  return (await request<ItemResponse<AdminUser>>('PATCH', `/admin/users/${id}`, { body: { suspended } })).data
}

export interface AuditQuery { action?: string; actor?: number | null; workspace?: string; cursor?: string | null }

/** The audit log of the whole installation. */
export async function listAudit(query: AuditQuery = {}): Promise<CursorPage<AuditEntry>> {
  return request<CursorPage<AuditEntry>>('GET', withQuery('/admin/audit', { ...query }))
}

/** The audit log of one workspace, for its admins. */
export async function listWorkspaceAudit(slug: string, query: Omit<AuditQuery, 'workspace'> = {}): Promise<CursorPage<AuditEntry>> {
  return request<CursorPage<AuditEntry>>('GET', withQuery(`${workspacePath(slug)}/audit`, { ...query }))
}

// ── Outgoing mail and announcements ──────────────────────────────────────────

export interface MailQuery { status?: MailStatus | null; q?: string; broadcast?: number | null; cursor?: string | null }

export async function listMail(query: MailQuery = {}): Promise<MailPage> {
  return request<MailPage>('GET', withQuery('/admin/mail', { ...query }))
}

export async function getMail(id: number): Promise<OutgoingEmailDetail> {
  return (await request<ItemResponse<OutgoingEmailDetail>>('GET', `/admin/mail/${id}`)).data
}

/** Sends a waiting or failed announcement message right away. */
export async function sendMailNow(id: number): Promise<OutgoingEmail> {
  return (await request<ItemResponse<OutgoingEmail>>('POST', `/admin/mail/${id}/send`)).data
}

export async function deleteMail(id: number): Promise<void> {
  await request<void>('DELETE', `/admin/mail/${id}`)
}

export async function listBroadcasts(): Promise<Broadcast[]> {
  return (await request<ItemResponse<Broadcast[]>>('GET', '/admin/broadcasts')).data
}

/** Who an announcement would reach; nothing is sent. */
export async function previewBroadcast(filter: BroadcastFilter): Promise<BroadcastPreview> {
  return (await request<ItemResponse<BroadcastPreview>>('POST', '/admin/broadcasts/preview', { body: filter })).data
}

/** `confirm_recipients` repeats the number from the preview; the API refuses if it no longer holds. */
export async function sendBroadcast(req: BroadcastFilter & { subject: string; body: string; confirm_recipients: number }): Promise<Broadcast> {
  return (await request<ItemResponse<Broadcast>>('POST', '/admin/broadcasts', { body: req })).data
}
