export type DayMode = 'realistic' | 'workday' | 'continuous'

const DAY = 86400
const WEEK = 7 * DAY
/** Every run begins on a Monday at nine. */
export const SIM_START = 9 * 3600

/**
 * Simulated time. It runs `speed` times faster than the wall clock; the
 * speed can change while it runs without the time jumping.
 */
export class SimClock {
  private anchorWall: number
  private anchorSim: number
  private currentSpeed: number

  constructor(speed: number, wallNow: number = Date.now()) {
    this.anchorWall = wallNow
    this.anchorSim = SIM_START
    this.currentSpeed = speed
  }

  get speed(): number {
    return this.currentSpeed
  }

  /** Simulated seconds since Monday 00:00 of the first week. */
  now(wallNow: number = Date.now()): number {
    return this.anchorSim + ((wallNow - this.anchorWall) / 1000) * this.currentSpeed
  }

  setSpeed(speed: number, wallNow: number = Date.now()): void {
    if (speed === this.currentSpeed) return
    this.anchorSim = this.now(wallNow)
    this.anchorWall = wallNow
    this.currentSpeed = speed
  }

  /** Moves simulated time forward at once: the night between two working days, which nobody needs to sit through. */
  jumpTo(sim: number, wallNow: number = Date.now()): void {
    if (sim <= this.now(wallNow)) return
    this.anchorSim = sim
    this.anchorWall = wallNow
  }

  /** Wall milliseconds until the simulated moment `sim` (negative when it has passed). */
  wallUntil(sim: number, wallNow: number = Date.now()): number {
    return ((sim - this.now(wallNow)) / this.currentSpeed) * 1000
  }
}

/** Days since the run began, counted from its first Monday. */
export const dayOf = (sim: number) => Math.floor(sim / DAY)

const isWeekend = (sim: number) => Math.floor((sim % WEEK) / DAY) >= 5
const hourOf = (sim: number) => (sim % DAY) / 3600

/** How busy people are at a simulated moment: 1 in office hours, less or nothing outside them. */
export function activity(sim: number, mode: DayMode): number {
  if (mode === 'continuous') return 1
  const hour = hourOf(sim)
  const office = hour >= 9 && hour < 17
  if (mode === 'workday') return office && !isWeekend(sim) ? 1 : 0
  const level = office ? 1 : (hour >= 7 && hour < 9) || (hour >= 17 && hour < 22) ? 0.2 : 0.03
  return isWeekend(sim) ? level * 0.15 : level
}

/** The next weekday nine o'clock at or after `sim`. */
export function nextOfficeStart(sim: number): number {
  let day = Math.floor(sim / DAY)
  if (hourOf(sim) >= 9) day += 1
  while (isWeekend(day * DAY)) day += 1
  return day * DAY + 9 * 3600
}

/**
 * When someone who would act again after `pause` seconds really does: a
 * pause stretches while people are less active, and in "workday" mode what
 * would fall outside office hours waits for the next morning.
 */
export function nextMoment(from: number, pause: number, mode: DayMode, jitter: number): number {
  const level = activity(from, mode)
  if (level === 0) return nextOfficeStart(from) + jitter * 1800
  const at = from + pause / level
  return mode === 'workday' && activity(at, mode) === 0 ? nextOfficeStart(at) + jitter * 1800 : at
}
