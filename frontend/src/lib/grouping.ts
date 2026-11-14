import {
  ITEM_KINDS, PRIORITIES, STATE_GROUPS,
  type GroupBy, type Item, type ItemKind, type Priority, type ProjectState, type StateGroup, type StateRef, type UserRef,
} from '@/api/types'

/** One list section or board column. Exactly one of the value fields is set, matching `by`. */
export interface ItemGroup {
  id: string
  by: GroupBy
  items: Item[]
  state?: StateRef
  stateGroup?: StateGroup
  priority?: Priority
  kind?: ItemKind
  /** `null` is the "unassigned" group. */
  assignee?: UserRef | null
}

export interface GroupingContext {
  /** States of the single selected project, in workflow order; without them items group by state group. */
  projectStates?: readonly ProjectState[] | null
  members?: readonly UserRef[]
  /** Board columns exist even when empty; list sections do not. */
  includeEmpty?: boolean
}

function bucket<T extends string | number>(items: readonly Item[], keyOf: (item: Item) => T): Map<T, Item[]> {
  const map = new Map<T, Item[]>()
  for (const item of items) {
    const key = keyOf(item)
    const list = map.get(key)
    if (list) list.push(item)
    else map.set(key, [item])
  }
  return map
}

export function groupItems(items: readonly Item[], by: GroupBy, ctx: GroupingContext = {}): ItemGroup[] {
  let groups: ItemGroup[]

  if (by === 'none') {
    return [{ id: 'all', by, items: [...items] }]
  } else if (by === 'state' && ctx.projectStates?.length) {
    const byState = bucket(items, (item) => item.state.id)
    groups = ctx.projectStates.map((state) => ({ id: `state:${state.id}`, by, state, items: byState.get(state.id) ?? [] }))
  } else if (by === 'state') {
    const byGroup = bucket(items, (item) => item.state.group)
    groups = STATE_GROUPS.map((stateGroup) => ({ id: `group:${stateGroup}`, by, stateGroup, items: byGroup.get(stateGroup) ?? [] }))
  } else if (by === 'priority') {
    const byPriority = bucket(items, (item) => item.priority)
    groups = PRIORITIES.map((priority) => ({ id: `priority:${priority}`, by, priority, items: byPriority.get(priority) ?? [] }))
  } else if (by === 'kind') {
    const byKind = bucket(items, (item) => item.kind)
    groups = ITEM_KINDS.map((kind) => ({ id: `kind:${kind}`, by, kind, items: byKind.get(kind) ?? [] }))
  } else {
    const byAssignee = bucket(items, (item) => item.assignee?.id ?? 0)
    // Members first, then anyone who appears on a row but is no longer a member.
    const people = new Map<number, UserRef>()
    for (const member of ctx.members ?? []) people.set(member.id, { id: member.id, name: member.name })
    for (const item of items) if (item.assignee && !people.has(item.assignee.id)) people.set(item.assignee.id, item.assignee)
    groups = [
      { id: 'assignee:none', by, assignee: null, items: byAssignee.get(0) ?? [] },
      ...[...people.values()].map((assignee) => ({ id: `assignee:${assignee.id}`, by, assignee, items: byAssignee.get(assignee.id) ?? [] })),
    ]
  }

  return ctx.includeEmpty ? groups : groups.filter((group) => group.items.length > 0)
}

/** Rows in the order they are shown, which is the order J/K moves through. */
export function flattenGroups(groups: readonly ItemGroup[], collapsed: ReadonlySet<string> = new Set()): Item[] {
  return groups.flatMap((group) => (collapsed.has(group.id) ? [] : group.items))
}
