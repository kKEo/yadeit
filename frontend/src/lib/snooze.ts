export interface SnoozeOption { id: 'laterToday' | 'tomorrow' | 'nextWeek'; until: string }

function at(base: Date, addDays: number, hour: number): Date {
  const date = new Date(base)
  date.setDate(date.getDate() + addDays)
  date.setHours(hour, 0, 0, 0)
  return date
}

/** Later today (in three hours), tomorrow at 9:00, next Monday at 9:00, as ISO timestamps. */
export function snoozeOptions(now: Date = new Date()): SnoozeOption[] {
  const untilMonday = ((8 - now.getDay()) % 7) || 7
  return [
    { id: 'laterToday', until: new Date(now.getTime() + 3 * 3_600_000).toISOString() },
    { id: 'tomorrow', until: at(now, 1, 9).toISOString() },
    { id: 'nextWeek', until: at(now, untilMonday, 9).toISOString() },
  ]
}
