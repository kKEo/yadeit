import { computed } from 'vue'
import { useQuery } from '@pinia/colada'
import { getAiStatus } from '@/api/ai'
import { useCanEdit } from '@/composables/useCanEdit'
import { keys, useSlug } from '@/queries/keys'

/**
 * Whether the built-in AI help can be offered here: the workspace has it
 * switched on, a provider key is available, and the viewer may edit. Buttons
 * that call the model are simply not shown otherwise.
 */
export function useAi() {
  const slug = useSlug()
  const canEdit = useCanEdit()
  const status = useQuery(() => ({
    key: keys.ai(slug.value),
    query: () => getAiStatus(slug.value),
    enabled: slug.value !== '',
    staleTime: 60_000,
  }))
  const available = computed(() => canEdit.value && status.data.value?.enabled === true && status.data.value.configured)

  return { status, available }
}
