import type { WorkspaceRole } from '@/api/types'

/** Highest first, as the API orders them. */
export const WORKSPACE_ROLES: readonly WorkspaceRole[] = ['owner', 'admin', 'member', 'guest']

const RANK: Record<WorkspaceRole, number> = { owner: 40, admin: 30, member: 20, guest: 10 }

export function roleAtLeast(role: WorkspaceRole | null | undefined, minimum: WorkspaceRole): boolean {
  return role != null && RANK[role] >= RANK[minimum]
}

/** Roles the actor may hand out: admins everything but owner, owners everything. */
export function assignableRoles(actor: WorkspaceRole | null | undefined): WorkspaceRole[] {
  if (!roleAtLeast(actor, 'admin')) return []
  return WORKSPACE_ROLES.filter((role) => role !== 'owner' || actor === 'owner')
}

/** Whether the actor may change or remove a member holding `target`; only owners touch owners. */
export function canManageMember(actor: WorkspaceRole | null | undefined, target: WorkspaceRole): boolean {
  return roleAtLeast(actor, 'admin') && (target !== 'owner' || actor === 'owner')
}
