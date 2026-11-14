import type { Component } from 'vue'
import {
  Bug, CircleDashed, FlaskConical, Minus, Palette, SignalHigh, SignalLow, SignalMedium, Sparkles, SquareCheck, TriangleAlert,
} from '@lucide/vue'
import {
  ITEM_KINDS, PRIORITIES, STATE_GROUPS,
  type ItemKind, type LabelRef, type Priority, type StateGroup, type StateRef, type UserRef,
} from '@/api/types'

export interface PickerOption { value: string; label: string; color?: string | null; icon?: Component; hint?: string }

type Translate = (key: string) => string

export const PRIORITY_ICONS: Record<Priority, Component> = {
  urgent: TriangleAlert, high: SignalHigh, medium: SignalMedium, low: SignalLow, none: Minus,
}

export const KIND_ICONS: Record<ItemKind, Component> = {
  task: SquareCheck, bug: Bug, feature: Sparkles, design: Palette, research: FlaskConical,
}

/** Colours for state groups when no single project (and so no custom state colour) applies. */
export const STATE_GROUP_COLORS: Record<StateGroup, string> = {
  triage: '#a78bfa', backlog: '#94a3b8', unstarted: '#64748b', started: '#f59e0b', completed: '#22c55e', cancelled: '#ef4444',
}

export const priorityOptions = (t: Translate): PickerOption[] => (
  PRIORITIES.map((p) => ({ value: p, label: t(`priority.${p}`), icon: PRIORITY_ICONS[p] }))
)

export const kindOptions = (t: Translate): PickerOption[] => (
  ITEM_KINDS.map((k) => ({ value: k, label: t(`kind.${k}`), icon: KIND_ICONS[k] }))
)

export const stateGroupOptions = (t: Translate): PickerOption[] => (
  STATE_GROUPS.map((g) => ({ value: g, label: t(`state.${g}`), color: STATE_GROUP_COLORS[g] }))
)

/** States by id, labelled with their own names. */
export const stateOptions = (states: readonly StateRef[], t: Translate): PickerOption[] => (
  states.map((s) => ({ value: String(s.id), label: s.name, color: s.color, hint: t(`state.${s.group}`) }))
)

export const labelOptions = (labels: readonly LabelRef[]): PickerOption[] => (
  labels.map((l) => ({ value: String(l.id), label: l.name, color: l.color }))
)

/** Members by id; `unassigned` adds the "nobody" choice with an empty value. */
export function memberOptions(members: readonly UserRef[], t: Translate, unassigned = true): PickerOption[] {
  const people = members.map((m) => ({ value: String(m.id), label: m.name }))
  return unassigned ? [{ value: '', label: t('items.unassigned'), icon: CircleDashed }, ...people] : people
}
