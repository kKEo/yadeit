import type { ActivityEntry } from '@/api/types'

type Translate = (key: string, named?: Record<string, unknown>) => string

/** Fields whose values are shown translated (priority.low → "Low") rather than raw. */
const TRANSLATED: Record<string, string> = { priority: 'priority', kind: 'kind' }

function shown(field: string, value: unknown, t: Translate): string {
  if (value === null || value === undefined || value === '') return t('activity.nothing')
  if (Array.isArray(value)) return value.length ? value.join(', ') : t('activity.nothing')
  const prefix = TRANSLATED[field]
  return prefix ? t(`${prefix}.${String(value)}`) : String(value)
}

function isChange(value: unknown): value is { from: unknown; to: unknown } {
  return typeof value === 'object' && value !== null && 'from' in value && 'to' in value
}

/** Human-readable lines for one history entry, e.g. "Ada changed state from Todo to Done". */
export function describeActivity(entry: ActivityEntry, t: Translate): string[] {
  const actor = entry.actor?.name ?? t('activity.someone')
  const changes = entry.changes ?? {}

  if (entry.event === 'updated') {
    const lines = Object.entries(changes).filter(([, change]) => isChange(change)).map(([field, change]) => {
      const { from, to } = change as { from: unknown; to: unknown }
      // Long text is not worth printing twice.
      if (field === 'description') return t('activity.changedDescription', { actor })
      return t('activity.changed', {
        actor, field: t(`activity.field.${field}`), from: shown(field, from, t), to: shown(field, to, t),
      })
    })
    return lines.length ? lines : [t('activity.event.updated', { actor })]
  }

  const named: Record<string, unknown> = { actor, ...changes }
  if (entry.event === 'relation_added' && typeof changes.type === 'string') named.type = t(`relation.${changes.type}`)
  const key = `activity.event.${entry.event}`
  const line = t(key, named)
  return [line === key ? t('activity.event.other', { actor, event: entry.event }) : line]
}
