<script setup lang="ts">
import { PopoverContent, PopoverPortal, PopoverRoot, PopoverTrigger } from 'radix-vue'

/** A small popover under a header chip, for properties that are typed rather than picked from a list. */
defineProps<{ label: string; disabled?: boolean }>()
const open = defineModel<boolean>('open', { default: false })
</script>

<template>
  <PopoverRoot v-model:open="open">
    <PopoverTrigger as-child :disabled="disabled"><slot name="trigger" /></PopoverTrigger>
    <PopoverPortal>
      <PopoverContent
        align="start" :side-offset="4" :aria-label="label"
        class="z-50 w-64 rounded-md border bg-popover p-3 text-popover-foreground shadow-md
               data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0"
      >
        <slot />
      </PopoverContent>
    </PopoverPortal>
  </PopoverRoot>
</template>
