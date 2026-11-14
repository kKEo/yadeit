import type { LocationQuery } from 'vue-router'
import {
  FILTER_NAMES, GROUPINGS, ITEM_KINDS, PRIORITIES, SORT_FIELDS, STATE_GROUPS,
  type FilterName, type GroupBy, type Item, type ItemFilters, type ListItemsParams, type SavedView,
  type SaveViewRequest, type ViewLayout, type ViewState,
} from '@/api/types'

export const DEFAULT_SORT = '-updated_at'
export const DEFAULT_VIEW: ViewState = { filters: {}, layout: 'list', group_by: 'state', sort: DEFAULT_SORT }

const SORT_PATTERN = new RegExp(`^-?(${SORT_FIELDS.join('|')})$`)

/** Values a filter accepts when it is a closed set; other filters take any non-empty text. */
const ALLOWED: Partial<Record<FilterName, readonly string[]>> = {
  state_group: STATE_GROUPS,
  priority: PRIORITIES,
  kind: ITEM_KINDS,
  open: ['1'],
  needs_input: ['1'],
  cycle: ['current', 'none'],
}

type Source = Record<string, unknown>

function text(value: unknown): string | null {
  if (typeof value === 'number') return String(value)
  return typeof value === 'string' && value.trim() !== '' ? value : null
}

/** Keeps known filters with usable values; anything else is dropped. */
export function cleanFilters(source: Source): ItemFilters {
  const filters: Record<string, string> = {}
  for (const name of FILTER_NAMES) {
    let value = text(source[name])
    if (name === 'open' && (source[name] === true || value === 'true')) value = '1'
    if (value === null) continue
    const allowed = ALLOWED[name]
    if (allowed && !allowed.includes(value)) continue
    filters[name] = name === 'project' ? value.toUpperCase() : value
  }
  return filters as ItemFilters
}

function cleanShape(source: Source, groupKey: 'group' | 'group_by'): Omit<ViewState, 'filters'> {
  const layout = source.layout === 'board' ? 'board' : DEFAULT_VIEW.layout
  const group = text(source[groupKey])
  const sort = text(source.sort)
  return {
    layout: layout as ViewLayout,
    group_by: GROUPINGS.includes(group as GroupBy) ? (group as GroupBy) : DEFAULT_VIEW.group_by,
    sort: sort && SORT_PATTERN.test(sort) ? sort : DEFAULT_SORT,
  }
}

/** Reads the view state from the query string; unknown or invalid values fall back to defaults. */
export function parseViewState(query: LocationQuery): ViewState {
  return { filters: cleanFilters(query), ...cleanShape(query, 'group') }
}

/** The query string form of a view state. Defaults are left out to keep links short. */
export function viewStateQuery(state: ViewState): Record<string, string> {
  const query: Record<string, string> = { ...cleanFilters(state.filters as Source) }
  if (state.layout !== DEFAULT_VIEW.layout) query.layout = state.layout
  if (state.group_by !== DEFAULT_VIEW.group_by) query.group = state.group_by
  if (state.sort !== DEFAULT_SORT) query.sort = state.sort
  return query
}

export function sameViewState(a: ViewState, b: ViewState): boolean {
  return JSON.stringify(Object.entries(viewStateQuery(a)).sort()) === JSON.stringify(Object.entries(viewStateQuery(b)).sort())
}

/** What the item list request sends for a view state. */
export function listParams(state: ViewState, limit?: number): ListItemsParams {
  return { ...cleanFilters(state.filters as Source), sort: state.sort, ...(limit ? { limit } : {}) }
}

export interface FilterChip { name: FilterName; value: string }

/** One removable chip per active filter, in a stable order. The search text has its own box. */
export function filterChips(filters: ItemFilters): FilterChip[] {
  return FILTER_NAMES
    .filter((name) => name !== 'q' && filters[name] !== undefined)
    .map((name) => ({ name, value: filters[name] as string }))
}

export function withFilter(filters: ItemFilters, name: FilterName, value: string | null): ItemFilters {
  const next: Record<string, string | undefined> = { ...filters, [name]: value ?? undefined }
  // A state belongs to one project and one group: keep the combination meaningful.
  if (name === 'project') delete next.state
  if (name === 'state' && value) delete next.state_group
  if (name === 'state_group' && value) delete next.state
  return cleanFilters(next)
}

// ── Saved views ──────────────────────────────────────────────────────────────

export function viewToState(view: Pick<SavedView, 'filters' | 'layout' | 'group_by' | 'sort'>): ViewState {
  return { filters: cleanFilters((view.filters ?? {}) as Source), ...cleanShape(view as unknown as Source, 'group_by') }
}

export function viewPayload(state: ViewState): Required<Pick<SaveViewRequest, 'filters' | 'layout' | 'group_by' | 'sort'>> {
  return { filters: cleanFilters(state.filters as Source), layout: state.layout, group_by: state.group_by, sort: state.sort }
}

export interface BuiltInView { id: 'all' | 'mine' | 'open' | 'recent'; state: ViewState }

export const BUILT_IN_VIEWS: readonly BuiltInView[] = [
  { id: 'all', state: DEFAULT_VIEW },
  { id: 'mine', state: { ...DEFAULT_VIEW, filters: { assignee: 'me', open: '1' } } },
  { id: 'open', state: { ...DEFAULT_VIEW, filters: { open: '1' } } },
  { id: 'recent', state: { ...DEFAULT_VIEW, group_by: 'none' } },
]

// ── Matching rows on the client ──────────────────────────────────────────────

/** Whether a row belongs in a list with these filters; mirrors the API's rules. */
export function matchesFilters(item: Item, filters: ItemFilters, meId: number | null): boolean {
  if (filters.project && item.project.key !== filters.project.toUpperCase()) return false
  if (filters.state && item.state.name !== filters.state && String(item.state.id) !== filters.state) return false
  if (filters.state_group && item.state.group !== filters.state_group) return false
  if (filters.priority && item.priority !== filters.priority) return false
  if (filters.kind && item.kind !== filters.kind) return false
  if (filters.open && item.completed_at !== null) return false
  if (filters.label && !item.labels.some((label) => label.name === filters.label)) return false
  if (filters.assignee) {
    const wanted = filters.assignee === 'none' ? null : filters.assignee === 'me' ? meId : Number(filters.assignee)
    if ((item.assignee?.id ?? null) !== wanted) return false
  }
  if (filters.q) {
    const term = filters.q.trim().toLowerCase()
    if (!item.title.toLowerCase().includes(term) && item.key.toLowerCase() !== term) return false
  }
  return true
}
