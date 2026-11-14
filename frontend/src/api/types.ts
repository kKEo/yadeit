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

export interface User {
  id: string | number
  name: string
  email: string
}

// ── Response envelopes ───────────────────────────────────────────────────────

export interface ListResponse<T> {
  data: T[]
  next_cursor: string | null
}

export interface ItemResponse<T> {
  data: T
}

export type FieldErrors = Record<string, string[]>

export interface ApiErrorBody {
  error: {
    code: string
    message: string
    details?: { fields?: FieldErrors }
  }
}
