<script setup lang="ts">
import type { StateRef } from '@/api/types'

/** A state's colour with its name as the accessible text. */
defineProps<{ state: StateRef; showName?: boolean }>()
</script>

<template>
  <span class="inline-flex min-w-0 items-center gap-1.5" :title="state.name">
    <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: state.color }" aria-hidden="true" />
    <span :class="showName ? 'truncate' : 'sr-only'">{{ state.name }}</span>
  </span>
</template>
