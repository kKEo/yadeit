import type { ItemFilters, Priority, Project, ProjectDetail } from '@/api/types'

export const LAST_PROJECT_STORAGE_KEY = 'yadeit_last_project'

export function rememberedProject(slug: string): string | null {
  try {
    return localStorage.getItem(`${LAST_PROJECT_STORAGE_KEY}:${slug}`)
  } catch {
    return null
  }
}

export function rememberProject(slug: string, key: string) {
  try {
    localStorage.setItem(`${LAST_PROJECT_STORAGE_KEY}:${slug}`, key)
  } catch {
    // ignore: the next capture just starts from the first project
  }
}

/** The filtered project, else the one used last, else the first. */
export function defaultProject(filters: ItemFilters, projects: readonly Project[], lastUsed: string | null): string | null {
  const known = (key: string | null | undefined) => projects.find((p) => p.key === key?.toUpperCase())?.key ?? null
  return known(filters.project) ?? known(lastUsed) ?? projects[0]?.key ?? null
}

export interface CaptureDefaults {
  /** State id as text, or `null` for the project's default state. */
  state: string | null
  priority: Priority
  /** Member id as text, or `null` for nobody. */
  assignee: string | null
  /** Label names. */
  labels: string[]
}

/**
 * A new item starts inside the list the user is looking at: the filters that
 * describe a single value carry over, so the item shows up where it was created.
 */
export function captureDefaults(filters: ItemFilters, project: ProjectDetail | null, meId: number | null): CaptureDefaults {
  const states = project?.states ?? []
  const state = filters.state
    ? states.find((s) => s.name.toLowerCase() === filters.state?.toLowerCase() || String(s.id) === filters.state)
    : filters.state_group
      ? states.find((s) => s.group === filters.state_group)
      : undefined

  const assignee = filters.assignee === 'me' ? meId : filters.assignee && filters.assignee !== 'none' ? Number(filters.assignee) : null

  return {
    state: state ? String(state.id) : null,
    priority: filters.priority ?? 'none',
    assignee: assignee && Number.isFinite(assignee) ? String(assignee) : null,
    labels: filters.label && project?.labels.some((l) => l.name === filters.label) ? [filters.label] : [],
  }
}
