import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Item, ItemDetail, ItemKind, Priority } from '@/api/types'
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
  // People are assigned (they answer for the work); agents are delegated to (they do it).
  const everyone = computed(() => members.data.value ?? [])
  const people = computed(() => everyone.value.filter((m) => m.type !== 'agent'))
  const agents = computed(() => everyone.value.filter((m) => m.type === 'agent'))
  const delegateOptions = computed<PickerOption[]>(() => [
    { value: '', label: t('items.noDelegate') },
    ...agents.value.map((a) => ({ value: String(a.id), label: a.name })),
  ])

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

  function delegateTo(value: string | string[]) {
    const current = item()
    const id = Array.isArray(value) ? value[0] ?? '' : value
    const agent = agents.value.find((a) => String(a.id) === id)
    if (current && (id === '' || agent)) void update(current.key, { delegate: agent ? { id: agent.id, name: agent.name } : null })
  }

  function apply(property: ItemProperty, value: string | string[]) {
    const current = item()
    const patch = toPatch(property, value)
    if (current && patch) void update(current.key, patch)
  }

  // A project that uses cycles offers its current one; an item keeps showing an older cycle it is still in.
  const cycleOptions = computed<PickerOption[]>(() => {
    const current = project.data.value?.current_cycle ?? null
    const mine = item()?.cycle ?? null
    return [
      { value: '', label: t('items.noCycle') },
      ...(current ? [{ value: String(current.number), label: current.name }] : []),
      ...(mine && mine.number !== current?.number ? [{ value: String(mine.number), label: mine.name }] : []),
    ]
  })
  const hasCycles = computed(() => project.data.value?.current_cycle != null || item()?.cycle != null)

  function setCycle(value: string | string[]) {
    const target = item()
    if (!target) return
    const picked = Array.isArray(value) ? value[0] ?? '' : value
    const current = project.data.value?.current_cycle ?? null
    if (picked === '') void update(target.key, { cycle: null })
    else if (current && picked === String(current.number)) void update(target.key, { cycle: current })
  }

  function setDueDate(value: string | null) {
    const target = item()
    if (target && (value || null) !== target.due_date) void update(target.key, { due_date: value || null })
  }

  /** Saves a typed parent key; resolves to false when nothing changed or the server refused it. */
  async function setParent(value: string): Promise<boolean> {
    const target = item()
    const next = value.trim().toUpperCase() || null
    if (!target || next === target.parent) return false
    return update(target.key, { parent: next })
  }

  /** Keys of the open items this one waits for (known for a loaded detail only). */
  const blockers = computed(() => ((item() as ItemDetail | null | undefined)?.relations ?? [])
    .filter((r) => r.type === 'blocked_by' && r.item.state.group !== 'completed' && r.item.state.group !== 'cancelled')
    .map((r) => r.item.key).join(', '))

  return {
    options, values, apply, update, states, labels, members: people, agents, delegateOptions, delegateTo,
    cycleOptions, hasCycles, setCycle, setDueDate, setParent, blockers,
  }
}
