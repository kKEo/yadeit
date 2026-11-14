<script setup lang="ts">
import type { LabelRef } from '@/api/types'

defineProps<{ labels: LabelRef[]; max?: number }>()
</script>

<template>
  <span v-if="labels.length" class="inline-flex min-w-0 items-center gap-1">
    <span
      v-for="label in labels.slice(0, max ?? labels.length)" :key="label.id"
      class="inline-flex max-w-[8rem] items-center gap-1 rounded-full border px-1.5 py-0.5 text-[11px] leading-none text-muted-foreground"
    >
      <span class="h-1.5 w-1.5 shrink-0 rounded-full" :style="{ backgroundColor: label.color }" aria-hidden="true" />
      <span class="truncate">{{ label.name }}</span>
    </span>
    <span v-if="max && labels.length > max" class="text-[11px] text-muted-foreground">+{{ labels.length - max }}</span>
  </span>
</template>
