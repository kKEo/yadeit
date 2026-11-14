<script setup lang="ts">
import { computed } from 'vue'
import { keyCaps } from '@/composables/useShortcuts'

/** Key caps for a shortcut such as "g i" or "mod+k". */
const props = defineProps<{ keys: string }>()
const steps = computed(() => keyCaps(props.keys))
</script>

<template>
  <span class="inline-flex items-center gap-1 whitespace-nowrap" aria-hidden="true">
    <template v-for="(step, i) in steps" :key="i">
      <kbd
        v-for="cap in step" :key="cap"
        class="inline-flex h-5 min-w-5 items-center justify-center rounded border bg-muted px-1 font-sans text-[11px] font-medium text-muted-foreground"
      >{{ cap }}</kbd>
    </template>
  </span>
</template>
