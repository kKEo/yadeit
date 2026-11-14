// Workflow state as a string literal union
export type WorkItemState = 'backlog' | 'unstarted' | 'started' | 'completed' | 'cancelled'

export const WORK_ITEM_STATES: readonly WorkItemState[] = [
  'backlog',
  'unstarted',
  'started',
  'completed',
  'cancelled',
]

export type WorkItemPriority = 'none' | 'low' | 'medium' | 'high' | 'urgent'

export const WORK_ITEM_PRIORITIES: readonly WorkItemPriority[] = [
  'none',
  'low',
  'medium',
  'high',
  'urgent',
]

export interface Assignee {
  id: string | number
  name: string
}

// Core entity. `key` (e.g. "WEB-143") is the identifier used in URLs.
export interface WorkItem {
  key: string
  title: string
  /** Markdown source */
  description: string
  state: WorkItemState
  priority: WorkItemPriority
  assignee: Assignee | null
  updated_at: string
}

// Summary for the list view (lighter than the full entity)
export type WorkItemSummary = Omit<WorkItem, 'description'>

export interface CreateWorkItemRequest {
  title: string
  description?: string
  state?: WorkItemState
  priority?: WorkItemPriority
}

export type UpdateWorkItemRequest = Partial<CreateWorkItemRequest>

export interface ListWorkItemsParams {
  state?: WorkItemState
  cursor?: string
  limit?: number
}

// ── Accounts and workspaces ──────────────────────────────────────────────────

export type WorkspaceRole = 'owner' | 'admin' | 'member' | 'guest'

/** Account as returned by register and login (no workspace list). */
export interface User {
  id: number
  name: string
  email: string
  locale: string
  email_verified: boolean
  created_at: string
}

export interface Workspace {
  id: number
  name: string
  slug: string
  /** The signed-in user's role in this workspace. */
  role: WorkspaceRole
  created_at: string
}

/** The signed-in user as returned by `GET /me`. */
export interface Me extends User {
  workspaces: Workspace[]
}

export interface RegisterRequest {
  name: string
  email: string
  password: string
  password_confirmation: string
  locale?: string
}

export interface UpdateMeRequest {
  name?: string
  locale?: string
}

export interface ResetPasswordRequest {
  token: string
  email: string
  password: string
  password_confirmation: string
}

export interface VerifyEmailParams {
  id: string
  hash: string
  expires: string
  signature: string
}

export interface CreateWorkspaceRequest {
  name: string
  slug?: string
}

export type UpdateWorkspaceRequest = Partial<CreateWorkspaceRequest>

export interface Member {
  /** User id */
  id: number
  name: string
  email: string
  role: WorkspaceRole
  joined_at: string
}

export interface Invitation {
  id: number
  email: string
  role: WorkspaceRole
  expires_at: string
  created_at: string
}

export interface CreateInvitationRequest {
  email: string
  role?: WorkspaceRole
}

/** What the invited person sees before signing in. */
export interface InvitationPreview {
  workspace: { name: string }
  email: string
  role: WorkspaceRole
  expires_at: string
  has_account: boolean
}

export interface AcceptedInvitation {
  workspace: Pick<Workspace, 'id' | 'name' | 'slug'>
}

export interface Project {
  id: number
  key: string
  name: string
  description: string | null
  created_at: string
}

// ── Response envelopes ───────────────────────────────────────────────────────

export interface ListResponse<T> {
  data: T[]
  next_cursor: string | null
}

export interface ItemResponse<T> {
  data: T
}

export interface MessageResponse {
  message: string
}

export type FieldErrors = Record<string, string[]>

export interface ApiErrorBody {
  error: {
    code: string
    message: string
    details?: { fields?: FieldErrors }
  }
}
