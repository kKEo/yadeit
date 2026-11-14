import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import * as api from '@/api/views'
import type { SavedView, SaveViewRequest } from '@/api/types'
import { keys, useSlug } from './keys'

export function useSavedViews() {
  const slug = useSlug()
  return useQuery(() => ({
    key: keys.views(slug.value),
    query: () => api.listViews(slug.value),
    enabled: slug.value !== '',
  }))
}

/** Create, change and delete saved views, keeping the cached list (sorted by name, like the API) current. */
export function useViewMutations() {
  const cache = useQueryCache()
  const slug = useSlug()

  function write(change: (views: SavedView[]) => SavedView[]) {
    const key = keys.views(slug.value)
    const next = change(cache.getQueryData<SavedView[]>(key) ?? [])
    cache.setQueryData(key, [...next].sort((a, b) => a.name.localeCompare(b.name)))
  }

  const create = useMutation({
    mutation: (req: SaveViewRequest & { name: string }) => api.createView(slug.value, req),
    onSuccess: (view) => write((views) => [...views, view]),
  })
  const update = useMutation({
    mutation: ({ id, ...req }: SaveViewRequest & { id: number }) => api.updateView(slug.value, id, req),
    onSuccess: (view) => write((views) => views.map((v) => (v.id === view.id ? view : v))),
  })
  const remove = useMutation({
    mutation: (id: number) => api.deleteView(slug.value, id),
    onSuccess: (_data, id) => write((views) => views.filter((v) => v.id !== id)),
  })

  return { create, update, remove }
}
