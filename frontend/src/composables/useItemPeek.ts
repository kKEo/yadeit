import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { queryString } from '@/lib/redirect'
import { useUiStore } from '@/stores/ui'

/** Opening and closing the item side panel (`?item=KEY`), and which item keyboard actions apply to. */
export function useItemPeek() {
  const route = useRoute()
  const router = useRouter()
  const ui = useUiStore()

  const isDetailPage = computed(() => route.name === 'item-detail')
  /** Item shown in the side panel, if any. */
  const peekKey = computed(() => (isDetailPage.value ? null : queryString(route.query, 'item') || null))
  /** The item on screen (panel or full page), else the row the cursor is on. */
  const activeKey = computed(() => (
    isDetailPage.value ? String(route.params.key) : peekKey.value ?? (route.name === 'items' ? ui.focusedKey : null)
  ))

  function open(key: string) {
    if (isDetailPage.value) return router.push({ name: 'item-detail', params: { slug: route.params.slug, key } })
    return router.push({ query: { ...route.query, item: key } })
  }

  function close() {
    if (!peekKey.value) return
    const query = { ...route.query }
    delete query.item
    return router.push({ query })
  }

  return { peekKey, activeKey, isDetailPage, open, close }
}
