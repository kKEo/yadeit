// ── Work items ───────────────────────────────────────────────────────────────

/** The fixed meaning behind every custom state name, in workflow order. */
export type StateGroup = 'triage' | 'backlog' | 'unstarted' | 'started' | 'completed' | 'cancelled'
export const STATE_GROUPS: readonly StateGroup[] = ['triage', 'backlog', 'unstarted', 'started', 'completed', 'cancelled']

export type Priority = 'none' | 'low' | 'medium' | 'high' | 'urgent'
/** Most pressing first, the order pickers and board columns use. */
export const PRIORITIES: readonly Priority[] = ['urgent', 'high', 'medium', 'low', 'none']

export type ItemKind = 'task' | 'bug' | 'feature' | 'design' | 'research'
export const ITEM_KINDS: readonly ItemKind[] = ['task', 'bug', 'feature', 'design', 'research']

export type LinkKind = 'pr' | 'branch' | 'design' | 'url'
export const LINK_KINDS: readonly LinkKind[] = ['url', 'pr', 'branch', 'design']

export type RelationType = 'blocks' | 'blocked_by' | 'relates' | 'duplicate'
/** Types the API accepts when adding; `blocked_by` is only the other side of `blocks`. */
export const RELATION_TYPES: readonly Exclude<RelationType, 'blocked_by'>[] = ['blocks', 'relates', 'duplicate']

export interface StateRef { id: number; name: string; group: StateGroup; color: string }
export interface ProjectState extends StateRef { is_default: boolean }
export interface LabelRef { id: number; name: string; color: string }
export interface UserRef { id: number; name: string }

/** A list row. `key` (e.g. "WEB-143") is the identifier used in URLs. */
export interface Item {
  key: string
  title: string
  state: StateRef
  priority: Priority
  kind: ItemKind
  assignee: UserRef | null
  /** The agent doing the work; the assignee stays the accountable person. */
  delegate: UserRef | null
  /** Paused on a question to a person. */
  needs_input: boolean
  cycle: CycleRef | null
  /** How many pieces of feedback ask for this item. */
  demand: number
  /** An open item must be finished before this one. */
  blocked: boolean
  /** How many sub-items exist and how many are done; null without sub-items. */
  sub_items: { total: number; done: number } | null
  labels: LabelRef[]
  project: { key: string; name: string }
  parent: string | null
  due_date: string | null
  version: number
  created_at: string
  updated_at: string
  completed_at: string | null
}

export interface CycleRef { id: number; number: number; name: string }
export interface Cycle extends CycleRef {
  status: 'upcoming' | 'active' | 'completed'
  starts_on: string
  ends_on: string
  items: { total: number; completed: number; started: number }
}

export interface ItemReference { key: string; title: string; state: StateRef }
export interface ChecklistEntry { id: number; text: string; is_done: boolean }
export interface ItemLink { id: number; kind: LinkKind; url: string; title: string | null }
export interface ItemRelation { id: number; type: RelationType; item: ItemReference }
export interface Attachment { id: number; name: string; mime_type: string; size: number; is_image: boolean; created_at: string }

export interface ItemDetail extends Item {
  /** Markdown source */
  description: string | null
  checklist: ChecklistEntry[]
  links: ItemLink[]
  children: ItemReference[]
  relations: ItemRelation[]
  attachments: Attachment[]
  comment_count: number
  subscribed: boolean
}

export interface CreateItemRequest {
  project: string
  title: string
  description?: string | null
  /** State name or id */
  state?: string | number
  priority?: Priority
  kind?: ItemKind
  assignee?: number | 'me' | null
  parent?: string | null
  due_date?: string | null
  /** Label names */
  labels?: string[]
}

export interface UpdateItemRequest extends Partial<Omit<CreateItemRequest, 'project'>> {
  expected_version?: number
  /** An agent's id, or null to take the work back. */
  delegate?: number | null
  /** "current", a cycle number, or null for none. */
  cycle?: number | 'current' | null
  needs_input?: boolean
}

/** Filter names shared by the item list, the URL and saved views. Values are strings, as stored. */
export interface ItemFilters {
  project?: string
  state?: string
  state_group?: StateGroup
  priority?: Priority
  kind?: ItemKind
  /** Member id, "me" or "none" */
  assignee?: string
  label?: string
  /** "current" or "none" */
  cycle?: string
  /** "1": waiting for a person's answer */
  needs_input?: string
  /** "1": blocked by an open item */
  blocked?: string
  /** "1": at least one piece of feedback asks for it */
  demand?: string
  q?: string
  /** "1" hides closed items */
  open?: string
}
export const FILTER_NAMES = ['project', 'state', 'state_group', 'priority', 'kind', 'assignee', 'label', 'cycle', 'needs_input', 'blocked', 'demand', 'q', 'open'] as const
export type FilterName = (typeof FILTER_NAMES)[number]

export interface ListItemsParams extends ItemFilters {
  parent?: string
  sort?: string
  limit?: number
  cursor?: string
}

export interface Comment { id: number; body: string; author: UserRef | null; created_at: string; edited_at: string | null }

export interface ActivityChange { from: unknown; to: unknown }
export interface ActivityEntry {
  id: number
  event: string
  /** For `updated`: field → {from, to}. For other events: a few named values. */
  changes: Record<string, unknown> | null
  actor: UserRef | null
  /** Name of the access token the change came through; null for the web app. */
  token?: string | null
  created_at: string
}

// ── Saved views ──────────────────────────────────────────────────────────────

export type ViewLayout = 'list' | 'board'
export type GroupBy = 'state' | 'priority' | 'assignee' | 'kind' | 'none'
export const GROUPINGS: readonly GroupBy[] = ['state', 'priority', 'assignee', 'kind', 'none']
export const SORT_FIELDS = ['updated_at', 'created_at', 'priority', 'due_date', 'sequence', 'demand'] as const

/** What the items page shows; lives in the URL and is what a saved view stores. */
export interface ViewState { filters: ItemFilters; layout: ViewLayout; group_by: GroupBy; sort: string }

export interface SavedView extends ViewState { id: number; name: string; is_shared: boolean; owner_id: number }
export interface SaveViewRequest extends Partial<ViewState> { name?: string; is_shared?: boolean }

// ── Inbox ────────────────────────────────────────────────────────────────────

export type InboxEvent = 'assigned' | 'commented' | 'state_changed' | 'input_requested' | 'submitted_for_review' | 'run_failed'

export type RunStatus = 'active' | 'awaiting_input' | 'completed' | 'failed' | 'stopped'
export type RunEntryType = 'thought' | 'action' | 'question' | 'response' | 'error'
export interface RunEntry { id: number; type: RunEntryType; body: string; created_at: string }
/** One stretch of an agent's work on an item, with its log. */
export interface AgentRun {
  id: number
  agent: UserRef | null
  status: RunStatus
  /** Still active but silent for a while. */
  stale: boolean
  started_at: string
  last_activity_at: string
  ended_at: string | null
  entries: RunEntry[]
}
export interface InboxEntry {
  id: number
  event: InboxEvent
  details: { from?: string; to?: string } | null
  item: { key: string; title: string }
  actor: UserRef | null
  read: boolean
  snoozed_until: string | null
  created_at: string
}
export interface InboxPage { data: InboxEntry[]; next_cursor: string | null; unread_count: number }
export interface UpdateInboxRequest { read?: boolean; snoozed_until?: string | null }

// ── Accounts and workspaces ──────────────────────────────────────────────────

export type WorkspaceRole = 'owner' | 'admin' | 'member' | 'guest'

/** Account as returned by register and login (no workspace list). */
export interface User {
  id: number
  name: string
  email: string
  locale: string
  email_verified: boolean
  email_notifications: boolean
  /** Runs the installation and sees the operator area. */
  is_operator: boolean
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
  email_notifications?: boolean
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
  /** null for agents, which have no mailbox */
  email: string | null
  type: 'human' | 'agent'
  role: WorkspaceRole
  joined_at: string
}

/** A member that acts through access tokens, owned by a person. */
export interface Agent {
  id: number
  name: string
  description: string | null
  owner: { id: number; name: string } | null
  token_count: number
  created_at: string
}

export interface AgentToken {
  id: number
  name: string
  access: 'read' | 'write'
  /** Project keys the token is limited to; null means every project. */
  projects: string[] | null
  last_used_at: string | null
  expires_at: string | null
  created_at: string
}

export interface NewAgentToken extends AgentToken {
  /** The secret; returned once at creation. */
  token: string
}

export interface CreateAgentTokenRequest {
  name: string
  access: 'read' | 'write'
  projects?: string[]
  expires_in_days?: number | null
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
  archived: boolean
  /** Weeks per cycle; null when the project does not use cycles. */
  cycle_weeks: number | null
  /** Days after which untouched open items return to the backlog; null when off. */
  stale_after_days: number | null
  /** Token of the public feedback form's link; null when the form is off. */
  feedback_token: string | null
  created_at: string
}

/** A project with its workflow, as `GET projects/{KEY}` returns it. */
export interface ProjectDetail extends Project { states: ProjectState[]; labels: LabelRef[]; current_cycle: CycleRef | null }

export interface CreateProjectRequest { name: string; key: string; description?: string | null }

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
    details?: { fields?: FieldErrors } & Record<string, unknown>
  }
}

/** Sends a signed HTTP request to `url` when items change. */
export interface Webhook {
  id: number
  name: string
  url: string
  /** Event names; null means every event. */
  events: string[] | null
  is_active: boolean
  /** Set when it switched itself off after repeated failures. */
  disabled_at: string | null
  created_at: string
}
export interface NewWebhook extends Webhook {
  /** The signing secret; returned once at creation. */
  secret: string
}
export interface SaveWebhookRequest { name?: string; url?: string; events?: string[] | null; is_active?: boolean }
export interface WebhookDelivery {
  id: number
  event_id: string
  event: string
  attempt: number
  succeeded: boolean
  status_code: number | null
  error: string | null
  duration_ms: number | null
  created_at: string
}

/** A Markdown page of the workspace or of one project. */
export interface DocPageSummary {
  id: number
  title: string
  project: { key: string; name: string } | null
  status: 'draft' | 'published'
  /** Token of the link that works without signing in; null when the page is not shared. */
  public_token: string | null
  version: number
  updated_by: UserRef | null
  created_at: string
  updated_at: string
}
export interface DocPage extends DocPageSummary { body: string | null }
export interface PublicPage { title: string; body: string | null; updated_at: string }
export interface SavePageRequest {
  title?: string
  body?: string | null
  /** Project key, or null for a workspace-wide page. */
  project?: string | null
  status?: 'draft' | 'published'
  public?: boolean
  expected_version?: number
}

/** One hit of the workspace search. */
export type SearchResult =
  | { type: 'item'; key: string; title: string; state: StateRef; snippet: string | null }
  | { type: 'comment'; key: string; title: string; author: string | null; snippet: string | null }
  | { type: 'page'; id: number; title: string; status: 'draft' | 'published'; project: string | null; snippet: string | null }

/** What someone says they need, before it is work. */
export interface Feedback {
  id: number
  title: string
  body: string | null
  project: { key: string; name: string }
  source: 'app' | 'public' | 'agent'
  status: 'new' | 'accepted' | 'declined' | 'duplicate'
  snoozed_until: string | null
  from: { name?: string; email?: string; member_id?: number }
  /** The work item this feedback counts for. */
  item: { key: string; title: string; demand: number } | null
  duplicate_of: number | null
  decline_reason: string | null
  created_at: string
  triaged_at: string | null
}
export type FeedbackStatusFilter = Feedback['status'] | 'snoozed' | 'all'

// ── Built-in AI: every result is a suggestion for a person to check ──────────

export interface AiStatus {
  /** Switched on by an admin of the workspace. */
  enabled: boolean
  /** A provider key is available (the workspace's own or the instance's). */
  configured: boolean
  has_own_key: boolean
  used_tokens: number
  /** The limit in force: the workspace's own, the default, or the plan's when that is lower. */
  monthly_token_limit: number
  /** What the workspace set itself; null when it follows the default. */
  own_token_limit: number | null
  model: string
}
export interface AiItemDraft { title: string; description: string; kind: ItemKind; priority: Priority; labels: string[]; acceptance: string[] }
export interface AiSubItem { title: string; description: string }
export interface AiItemSummary { summary: string; decisions: string[]; open_questions: string[] }
export interface AiPageSummary { summary: string; key_points: string[] }
export interface AiTriageSuggestion {
  duplicate_of: number | null
  existing_item: string | null
  kind: ItemKind
  priority: Priority
  labels: string[]
  reason: string
}

// ── Operator area and audit log ──────────────────────────────────────────────

/** One page of a list that continues with `next_cursor`. */
export interface CursorPage<T> {
  data: T[]
  next_cursor: string | null
}

export interface AdminOverview {
  workspaces: number
  suspended_workspaces: number
  people: number
  agents: number
  items: number
  signups_last_7_days: number
}

export interface AdminWorkspace {
  id: number
  name: string
  slug: string
  plan: string
  members: number
  items: number
  suspended_at: string | null
  created_at: string
}

export interface AdminUser {
  id: number
  name: string
  email: string
  email_verified: boolean
  is_operator: boolean
  workspaces: number
  suspended_at: string | null
  created_at: string
}

export interface AuditEntry {
  id: number
  /** A dotted code such as `member.role_changed`. */
  action: string
  subject: string | null
  details: Record<string, unknown> | null
  actor: { id: number; name: string; type: 'human' | 'agent' } | null
  /** The name of the access token, when the request came with one. */
  token: string | null
  created_at: string
  /** Operator view only. */
  workspace?: string | null
  ip?: string | null
}

export type MailStatus = 'waiting' | 'sending' | 'sent' | 'failed'

export interface OutgoingEmail {
  id: number
  /** What sent it: `Announcement`, `ResetPassword`, `ItemActivity`… */
  kind: string
  to: { email: string; name: string | null }
  subject: string
  status: MailStatus
  attempts: number
  error: string | null
  broadcast_id: number | null
  sent_at: string | null
  created_at: string
}

/** The text is kept for announcements only. */
export interface OutgoingEmailDetail extends OutgoingEmail {
  body: string | null
}

export interface MailPage extends CursorPage<OutgoingEmail> {
  counts: Record<MailStatus, number>
}

export type BroadcastAudience = 'everyone' | 'admins' | 'owners'

export interface BroadcastFilter {
  audience: BroadcastAudience
  plan: string | null
  /** A service notice: also reaches people who turned email notifications off. */
  important: boolean
}

export interface Broadcast extends BroadcastFilter {
  id: number
  subject: string
  body: string
  recipients: number
  waiting: number
  sent: number
  failed: number
  created_by: string | null
  created_at: string
}

export interface BroadcastPreview {
  recipients: number
  sample: string[]
}

// ── Plans and billing ────────────────────────────────────────────────────────

export interface PlanLimits {
  /** People, open invitations included; agents never count. null means no limit. */
  members: number | null
  projects: number | null
  ai_tokens: number | null
}

export interface PlanOption extends PlanLimits {
  key: string
  name: string
  /** Can be bought here: Stripe is set up and the plan has a price. */
  purchasable: boolean
}

export interface BillingStatus {
  plan: string
  /** Off on installations without paid plans: limits then refuse nothing. */
  limits_enforced: boolean
  billing_enabled: boolean
  limits: PlanLimits
  usage: { members: number; projects: number }
  plans: PlanOption[]
  subscription: { status: string; ends_at: string | null } | null
  has_billing_account: boolean
}
