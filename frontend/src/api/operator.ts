import { request, workspacePath } from './client'
import type { AdminOverview, AdminUser, AdminWorkspace, AuditEntry, CursorPage, ItemResponse } from './types'

type Query = Record<string, string | number | boolean | null | undefined>

function withQuery(path: string, query: Query): string {
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(query)) {
    if (value !== undefined && value !== null && value !== '') params.set(key, String(value))
  }
  const text = params.toString()
  return text ? `${path}?${text}` : path
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
