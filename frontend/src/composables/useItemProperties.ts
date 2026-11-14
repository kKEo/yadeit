import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Item, ItemKind, Priority } from '@/api/types'
import { kindOptions, labelOptions, memberOptions, priorityOptions, stateOptions, type PickerOption } from '@/lib/pickerOptions'
import { useUpdateItem, type ItemPatch } from '@/queries/itemMutations'
import { useMembers, useProject } from '@/queries/workspace'
import type { ItemProperty } from '@/stores/ui'

/**
 * Options, current values and the save action for an item's picker-edited
 * properties. Values are strings (ids for state, assignee and labels).
 */
export function useItemProperties(item: () => Item | null | undefined) {
  const { t } = useI18n()
  const project = useProject(() => item()?.project.key)
  const members = useMembers()
  const { update } = useUpdateItem()

  const states = computed(() => project.data.value?.states ?? [])
  const labels = computed(() => project.data.value?.labels ?? [])
  const people = computed(() => members.data.value ?? [])

  const options = computed<Record<ItemProperty, PickerOption[]>>(() => ({
    state: stateOptions(states.value, t),
    priority: priorityOptions(t),
    assignee: memberOptions(people.value, t),
    labels: labelOptions(labels.value),
    kind: kindOptions(t),
  }))

  const values = computed<Record<ItemProperty, string | string[]>>(() => {
    const current = item()
    return {
      state: current ? String(current.state.id) : '',
      priority: current?.priority ?? 'none',
      assignee: current?.assignee ? String(current.assignee.id) : '',
      labels: current?.labels.map((l) => String(l.id)) ?? [],
      kind: current?.kind ?? 'task',
    }
  })

  /** The patch a picked value stands for, or null when it cannot be resolved (data still loading). */
  function toPatch(property: ItemProperty, value: string | string[]): ItemPatch | null {
    if (property === 'labels') {
      const ids = Array.isArray(value) ? value : [value]
      return { labels: labels.value.filter((l) => ids.includes(String(l.id))) }
    }
    const single = Array.isArray(value) ? value[0] ?? '' : value
    if (property === 'priority') return { priority: single as Priority }
    if (property === 'kind') return { kind: single as ItemKind }
    if (property === 'assignee') {
      if (single === '') return { assignee: null }
      const member = people.value.find((m) => String(m.id) === single)
      return member ? { assignee: { id: member.id, name: member.name } } : null
    }
    const state = states.value.find((s) => String(s.id) === single)
    return state ? { state: { id: state.id, name: state.name, group: state.group, color: state.color } } : null
  }

  function apply(property: ItemProperty, value: string | string[]) {
    const current = item()
    const patch = toPatch(property, value)
    if (current && patch) void update(current.key, patch)
  }

  return { options, values, apply, update, states, labels, members: people }
}
