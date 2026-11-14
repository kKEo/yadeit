import { ref, type Ref } from 'vue'
import type { CursorPage } from '@/api/types'
import i18n from '@/i18n'

/** A list that is loaded page by page with a "load more" button. */
export function usePagedList<T>(fetchPage: (cursor: string | null) => Promise<CursorPage<T>>) {
  const items = ref([]) as Ref<T[]>
  const cursor = ref<string | null>(null)
  const loading = ref(false)
  const loaded = ref(false)
  const error = ref<string | null>(null)
  // A reload while a page is on its way wins: the late answer is dropped.
  let generation = 0

  async function run(from: string | null) {
    const mine = ++generation
    loading.value = true
    error.value = null
    try {
      const page = await fetchPage(from)
      if (mine !== generation) return
      items.value = from === null ? page.data : [...items.value, ...page.data]
      cursor.value = page.next_cursor
      loaded.value = true
    } catch (err: unknown) {
      if (mine === generation) error.value = err instanceof Error ? err.message : i18n.global.t('errors.generic')
    } finally {
      if (mine === generation) loading.value = false
    }
  }

  return {
    items, loading, loaded, error,
    hasMore: () => cursor.value !== null,
    reload: () => run(null),
    loadMore: () => (cursor.value !== null && !loading.value ? run(cursor.value) : Promise.resolve()),
  }
}
