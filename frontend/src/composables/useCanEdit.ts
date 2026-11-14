import { computed } from 'vue'
import { roleAtLeast } from '@/lib/roles'
import { useWorkspaceStore } from '@/stores/workspace'

/** Guests read and comment; everyone else may change items. */
export function useCanEdit() {
  const store = useWorkspaceStore()
  return computed(() => roleAtLeast(store.role, 'member'))
}
