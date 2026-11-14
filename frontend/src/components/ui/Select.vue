<script setup lang="ts">
import {
  SelectRoot, SelectTrigger, SelectValue, SelectPortal, SelectContent,
  SelectViewport, SelectItem, SelectItemText, SelectItemIndicator,
  SelectScrollUpButton, SelectScrollDownButton,
} from 'radix-vue'
import { Check, ChevronDown, ChevronUp } from '@lucide/vue'
import { cn } from '@/lib/utils'

interface SelectOption { value: string; label: string; disabled?: boolean }
interface Props { modelValue?: string; options: SelectOption[]; placeholder?: string; disabled?: boolean; class?: string }
const props = withDefaults(defineProps<Props>(), { placeholder: 'Select…' })
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
</script>

<template>
  <SelectRoot :model-value="modelValue" :disabled="disabled" @update:model-value="emit('update:modelValue', $event)">
    <SelectTrigger
      :class="cn(
        'flex h-10 w-full items-center justify-between rounded-md border border-input bg-background px-3 py-2',
        'text-sm ring-offset-background placeholder:text-muted-foreground',
        'focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2',
        'disabled:cursor-not-allowed disabled:opacity-50 [&>span]:line-clamp-1',
        props.class,
      )"
    >
      <SelectValue :placeholder="placeholder" />
      <ChevronDown class="h-4 w-4 opacity-50 shrink-0" />
    </SelectTrigger>
    <SelectPortal>
      <SelectContent
        class="relative z-50 max-h-96 min-w-[8rem] overflow-hidden rounded-md border bg-popover text-popover-foreground shadow-md
               data-[state=open]:animate-in data-[state=closed]:animate-out
               data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0
               data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95
               data-[side=bottom]:slide-in-from-top-2 data-[side=top]:slide-in-from-bottom-2"
        position="popper"
      >
        <SelectScrollUpButton class="flex cursor-default items-center justify-center py-1">
          <ChevronUp class="h-4 w-4" />
        </SelectScrollUpButton>
        <SelectViewport class="p-1">
          <SelectItem
            v-for="opt in options" :key="opt.value" :value="opt.value" :disabled="opt.disabled"
            class="relative flex w-full cursor-default select-none items-center rounded-sm py-1.5 pl-8 pr-2 text-sm outline-none
                   focus:bg-accent focus:text-accent-foreground
                   data-[disabled]:pointer-events-none data-[disabled]:opacity-50"
          >
            <span class="absolute left-2 flex h-3.5 w-3.5 items-center justify-center">
              <SelectItemIndicator><Check class="h-4 w-4" /></SelectItemIndicator>
            </span>
            <SelectItemText>{{ opt.label }}</SelectItemText>
          </SelectItem>
        </SelectViewport>
        <SelectScrollDownButton class="flex cursor-default items-center justify-center py-1">
          <ChevronDown class="h-4 w-4" />
        </SelectScrollDownButton>
      </SelectContent>
    </SelectPortal>
  </SelectRoot>
</template>
