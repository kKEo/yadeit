<script setup lang="ts">
import { computed } from 'vue'

/**
 * A small line chart drawn as SVG: one line per series over the same steps.
 * No library and no canvas, so it follows the theme and renders in tests.
 */
interface Series { label: string; values: number[]; class: string }
interface Props { title: string; series: Series[]; unit?: string; height?: number }
const props = withDefaults(defineProps<Props>(), { unit: '', height: 140 })

const WIDTH = 600
const PAD = 4

const max = computed(() => Math.max(1, ...props.series.flatMap((s) => s.values)))
const steps = computed(() => Math.max(1, ...props.series.map((s) => s.values.length)))

function points(values: number[]): string {
  const span = Math.max(1, steps.value - 1)
  return values.map((value, index) => {
    const x = PAD + (index / span) * (WIDTH - 2 * PAD)
    const y = props.height - PAD - (value / max.value) * (props.height - 2 * PAD)
    return `${x.toFixed(1)},${y.toFixed(1)}`
  }).join(' ')
}

const last = (values: number[]) => values[values.length - 1] ?? 0
const format = (value: number) => `${Number.isInteger(value) ? value : value.toFixed(1)}${props.unit ? ` ${props.unit}` : ''}`
/** For people who cannot see the line: where each series stands now and its peak. */
const summary = computed(() => props.series.map((s) => `${s.label}: ${format(last(s.values))}, max ${format(Math.max(0, ...s.values))}`).join('; '))
</script>

<template>
  <figure class="rounded-lg border p-3">
    <figcaption class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 text-sm">
      <span class="font-medium">{{ title }}</span>
      <span class="flex flex-wrap gap-x-3 text-xs text-muted-foreground">
        <span v-for="s in series" :key="s.label" class="inline-flex items-center gap-1">
          <svg class="h-2 w-3" :class="s.class" aria-hidden="true"><line x1="0" y1="4" x2="12" y2="4" stroke="currentColor" stroke-width="2" /></svg>
          {{ s.label }} <span class="tabular-nums text-foreground">{{ format(last(s.values)) }}</span>
        </span>
      </span>
    </figcaption>
    <div class="relative mt-2">
      <span class="absolute left-0 top-0 text-[10px] tabular-nums text-muted-foreground">{{ format(max) }}</span>
      <svg :viewBox="`0 0 ${WIDTH} ${height}`" class="w-full" preserveAspectRatio="none" role="img" :aria-label="`${title}. ${summary}`" :style="{ height: `${height}px` }">
        <line :x1="PAD" :x2="WIDTH - PAD" :y1="height - PAD" :y2="height - PAD" class="text-border" stroke="currentColor" />
        <polyline
          v-for="s in series" :key="s.label" :points="points(s.values)" :class="s.class"
          fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" vector-effect="non-scaling-stroke"
        />
      </svg>
    </div>
  </figure>
</template>
