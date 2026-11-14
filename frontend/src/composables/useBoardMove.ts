import { useQueryCache } from '@pinia/colada'
import { useI18n } from 'vue-i18n'
import type { Item } from '@/api/types'
import type { ItemGroup } from '@/lib/grouping'
import { useUpdateItem, type ItemPatch } from '@/queries/itemMutations'
import { useSlug } from '@/queries/keys'
import { ensureProject } from '@/queries/workspace'
import { useToastStore } from '@/stores/toast'

/** Turns "card dropped on column" into the property change the column stands for. */
export function useBoardMove() {
  const cache = useQueryCache()
  const slug = useSlug()
  const toast = useToastStore()
  const { t } = useI18n()
  const { update } = useUpdateItem()

  async function patchFor(item: Item, group: ItemGroup): Promise<ItemPatch | null> {
    if (group.state) return { state: group.state }
    if (group.priority) return { priority: group.priority }
    if (group.kind) return { kind: group.kind }
    if (group.by === 'assignee') return { assignee: group.assignee ?? null }
    if (group.stateGroup) {
      // Columns are state groups across projects: use the first state of that group in the item's own project.
      const project = await ensureProject(cache, slug.value, item.project.key)
      const state = project.states.find((s) => s.group === group.stateGroup)
      if (!state) {
        toast.error(t('items.board.noStateInGroup', { project: item.project.name, group: t(`state.${group.stateGroup}`) }))
        return null
      }
      return { state: { id: state.id, name: state.name, group: state.group, color: state.color } }
    }
    return null
  }

  async function move(item: Item, group: ItemGroup): Promise<boolean> {
    try {
      const patch = await patchFor(item, group)
      return patch ? await update(item.key, patch) : false
    } catch (err: unknown) {
      toast.error(err instanceof Error ? err.message : t('errors.update'))
      return false
    }
  }

  return { move, patchFor }
}
