<script setup lang="ts">
import { DropdownMenuItem } from 'radix-vue'
import { cn } from '@/lib/utils'

interface Props { disabled?: boolean; class?: string }
const props = defineProps<Props>()
const emit = defineEmits<{ select: [] }>()
</script>

<template>
  <DropdownMenuItem
    :disabled="disabled"
    :class="cn(
      'relative flex cursor-default select-none items-center gap-2 rounded-sm px-2 py-1.5 text-sm outline-none',
      'focus:bg-accent focus:text-accent-foreground data-[disabled]:pointer-events-none data-[disabled]:opacity-50',
      props.class,
    )"
    @select="emit('select')"
  >
    <slot />
  </DropdownMenuItem>
</template>
