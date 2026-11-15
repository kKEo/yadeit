import { describe, expect, it } from 'vitest'
import { activity, nextMoment, nextOfficeStart, SIM_START, SimClock } from './clock.ts'
import { Scheduler } from './scheduler.ts'

const HOUR = 3600
const DAY = 86400

describe('SimClock', () => {
  it('runs faster than the wall clock by the speed', () => {
    const clock = new SimClock(10, 0)
    expect(clock.now(0)).toBe(SIM_START)
    expect(clock.now(60_000)).toBe(SIM_START + 600)
  })

  it('changes speed without a jump, and stands still at zero', () => {
    const clock = new SimClock(10, 0)
    clock.setSpeed(100, 60_000)
    expect(clock.now(60_000)).toBe(SIM_START + 600)
    expect(clock.now(61_000)).toBe(SIM_START + 700)
    clock.setSpeed(0, 61_000)
    expect(clock.now(999_000)).toBe(SIM_START + 700)
  })
})

describe('the working day', () => {
  it('knows office hours, evenings, nights and weekends', () => {
    expect(activity(10 * HOUR, 'workday')).toBe(1)
    expect(activity(20 * HOUR, 'workday')).toBe(0)
    expect(activity(5 * DAY + 10 * HOUR, 'workday')).toBe(0)
    expect(activity(20 * HOUR, 'realistic')).toBe(0.2)
    expect(activity(3 * HOUR, 'realistic')).toBe(0.03)
    expect(activity(3 * HOUR, 'continuous')).toBe(1)
  })

  it('moves what falls after hours to the next working morning', () => {
    expect(nextOfficeStart(18 * HOUR)).toBe(DAY + 9 * HOUR)
    // Friday evening goes to Monday.
    expect(nextOfficeStart(4 * DAY + 18 * HOUR)).toBe(7 * DAY + 9 * HOUR)
    expect(nextMoment(16.9 * HOUR, 1200, 'workday', 0)).toBe(DAY + 9 * HOUR)
    expect(nextMoment(10 * HOUR, 60, 'workday', 0)).toBe(10 * HOUR + 60)
    // Half as active means pauses twice as long; here five times.
    expect(nextMoment(20 * HOUR, 60, 'realistic', 0)).toBe(20 * HOUR + 300)
  })
})

describe('Scheduler', () => {
  function actor(pause: number, takes: Promise<void> = Promise.resolve()) {
    const done: string[] = []
    return { due: 0, busy: false, done, next: () => ({ action: 'x', pause }), act: (a: string) => { done.push(a); return takes } }
  }

  it('starts an action when its moment has come and plans the next from the intended moment', () => {
    const clock = new SimClock(10, 0)
    const lags: number[] = []
    const scheduler = new Scheduler<string>(clock, 'continuous', (ms) => lags.push(ms))
    const a = actor(100)
    scheduler.add(a, SIM_START + 50)

    expect(scheduler.tick(4_000)).toBe(0)
    // 6 s of wall time is 60 simulated seconds: ten past the moment, which is one wall second late.
    expect(scheduler.tick(6_000)).toBe(1)
    expect(lags).toEqual([1000])
    expect(a.due).toBe(SIM_START + 150)
  })

  it('never runs two actions of one member at once', async () => {
    const clock = new SimClock(10, 0)
    const scheduler = new Scheduler<string>(clock, 'continuous', () => {})
    let release = () => {}
    const a = actor(10, new Promise<void>((resolve) => { release = resolve }))
    scheduler.add(a, SIM_START)

    expect(scheduler.tick(1_000)).toBe(1)
    expect(scheduler.tick(5_000)).toBe(0)
    release()
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect(scheduler.tick(5_000)).toBe(1)
    expect(a.done).toHaveLength(2)
  })

  it('gives up catching up when far behind, and says so', () => {
    const clock = new SimClock(10, 0)
    const scheduler = new Scheduler<string>(clock, 'continuous', () => {})
    const a = actor(5)
    scheduler.add(a, SIM_START)

    scheduler.tick(120_000)
    expect(scheduler.skipped).toBe(1)
    expect(a.due).toBe(SIM_START + 1200 + 5)
  })

  it('does nothing while the run is paused', () => {
    const clock = new SimClock(0, 0)
    const scheduler = new Scheduler<string>(clock, 'continuous', () => {})
    scheduler.add(actor(5), 0)
    expect(scheduler.tick(60_000)).toBe(0)
  })
})
