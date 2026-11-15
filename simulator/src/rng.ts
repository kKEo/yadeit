/** Random numbers that repeat: the same seed gives the same sequence, so a run can be played again. */
export class Rng {
  private state: number

  constructor(seed: number) {
    this.state = seed >>> 0 || 1
  }

  /** 0 <= x < 1 (mulberry32). */
  next(): number {
    this.state = (this.state + 0x6d2b79f5) >>> 0
    let t = this.state
    t = Math.imul(t ^ (t >>> 15), t | 1)
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61)
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }

  int(min: number, max: number): number {
    return min + Math.floor(this.next() * (max - min + 1))
  }

  chance(probability: number): boolean {
    return this.next() < probability
  }

  pick<T>(list: readonly T[]): T | undefined {
    return list.length === 0 ? undefined : list[Math.floor(this.next() * list.length)]
  }

  /** Picks a key in proportion to its weight. */
  weighted<K extends string>(weights: Readonly<Partial<Record<K, number>>>): K {
    const entries = (Object.entries(weights) as [K, number][]).filter(([, w]) => w > 0)
    let roll = this.next() * entries.reduce((sum, [, w]) => sum + w, 0)
    for (const [key, weight] of entries) {
      roll -= weight
      if (roll < 0) return key
    }
    return entries[entries.length - 1]![0]
  }

  /** A pause around `mean`, most of them shorter and a few much longer, like people's. */
  pause(mean: number): number {
    return -Math.log(1 - this.next()) * mean
  }
}

/** A seed for one member that differs from every other member's but is the same on every replay. */
export function seedFor(runSeed: number, memberId: number, stream: number): number {
  return (Math.imul(runSeed + 1, 2654435761) ^ Math.imul(memberId + 1, 40503) ^ Math.imul(stream + 1, 97)) >>> 0
}
