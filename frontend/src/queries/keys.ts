import type { EntryKey } from '@pinia/colada'
import { computed, type ComputedRef } from 'vue'
import type { ListItemsParams } from '@/api/types'
import { useWorkspaceStore } from '@/stores/workspace'

/**
 * Cache keys, all under `['w', slug]` so one workspace never sees another's
 * data. Keys are hierarchical: invalidating `item(slug, key)` also covers the
 * item's comments and activity.
 */
export const keys = {
  itemLists: (slug: string): EntryKey => ['w', slug, 'items'],
  itemList: (slug: string, params: ListItemsParams): EntryKey => ['w', slug, 'items', definedOnly(params)],
  itemSearch: (slug: string, q: string): EntryKey => ['w', slug, 'search', q],
  item: (slug: string, key: string): EntryKey => ['w', slug, 'item', key],
  itemDetail: (slug: string, key: string): EntryKey => ['w', slug, 'item', key, 'detail'],
  itemComments: (slug: string, key: string): EntryKey => ['w', slug, 'item', key, 'comments'],
  itemActivity: (slug: string, key: string): EntryKey => ['w', slug, 'item', key, 'activity'],
  itemRuns: (slug: string, key: string): EntryKey => ['w', slug, 'item', key, 'runs'],
  projects: (slug: string): EntryKey => ['w', slug, 'projects'],
  projectList: (slug: string): EntryKey => ['w', slug, 'projects', 'list'],
  project: (slug: string, key: string): EntryKey => ['w', slug, 'projects', 'detail', key.toUpperCase()],
  members: (slug: string): EntryKey => ['w', slug, 'members'],
  views: (slug: string): EntryKey => ['w', slug, 'views'],
  inbox: (slug: string): EntryKey => ['w', slug, 'inbox'],
  inboxLists: (slug: string): EntryKey => ['w', slug, 'inbox', 'list'],
  inboxList: (slug: string, unread: boolean): EntryKey => ['w', slug, 'inbox', 'list', unread ? 'unread' : 'all'],
  inboxCount: (slug: string): EntryKey => ['w', slug, 'inbox', 'count'],
}

function definedOnly(params: ListItemsParams): Record<string, string | number> {
  const out: Record<string, string | number> = {}
  for (const [name, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') out[name] = value as string | number
  }
  return out
}

/** Slug of the workspace in the URL; empty for the moment between two workspaces. */
export function useSlug(): ComputedRef<string> {
  const store = useWorkspaceStore()
  return computed(() => store.currentSlug ?? '')
}
