<script setup lang="ts">
import { cn } from '@/lib/utils'
interface Props { class?: string; rows?: number; modelValue?: string; placeholder?: string; disabled?: boolean }
const props = withDefaults(defineProps<Props>(), { rows: 3 })
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
</script>

<template>
  <textarea
    :rows="rows" :value="modelValue" :placeholder="placeholder" :disabled="disabled"
    :class="cn(
      'flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background',
      'placeholder:text-muted-foreground',
      'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
      'disabled:cursor-not-allowed disabled:opacity-50',
      props.class,
    )"
    @input="emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
  />
</template>
