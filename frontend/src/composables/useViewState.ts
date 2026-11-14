import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import type { FilterName, ViewState } from '@/api/types'
import { parseViewState, viewStateQuery, withFilter } from '@/lib/viewState'
import { queryString } from '@/lib/redirect'
import { useSlug } from '@/queries/keys'

/**
 * The items page's view state, read from and written to the query string, so a
 * view is a link and back/forward step through changes.
 */
export function useViewState() {
  const route = useRoute()
  const router = useRouter()
  const slug = useSlug()

  const state = computed<ViewState>(() => parseViewState(route.query))
  /** Id of the saved or built-in view the state started from, if any. */
  const viewId = computed(() => queryString(route.query, 'view') || null)

  /** `view: undefined` keeps the current view id; `null` detaches from it. */
  function apply(next: ViewState, opts: { view?: string | null; replace?: boolean } = {}) {
    const query: Record<string, string> = viewStateQuery(next)
    const view = opts.view === undefined ? viewId.value : opts.view
    if (view) query.view = view
    const item = route.name === 'items' ? queryString(route.query, 'item') : ''
    if (item) query.item = item
    const to = { name: 'items', params: { slug: slug.value }, query }
    return opts.replace ? router.replace(to) : router.push(to)
  }

  function patch(partial: Partial<ViewState>, opts: { replace?: boolean } = {}) {
    return apply({ ...state.value, ...partial }, opts)
  }

  /** Sets or (with `null`) removes one filter. Typing in the search box does not flood the history. */
  function setFilter(name: FilterName, value: string | null) {
    return patch({ filters: withFilter(state.value.filters, name, value) }, { replace: name === 'q' })
  }

  function clearFilters() {
    return patch({ filters: {} })
  }

  return { state, viewId, apply, patch, setFilter, clearFilters }
}
