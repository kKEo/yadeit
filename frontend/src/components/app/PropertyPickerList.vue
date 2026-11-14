<script setup lang="ts">
import { computed, nextTick, onMounted, ref, useId, watch } from 'vue'
import { Check } from '@lucide/vue'
import type { PickerOption } from '@/lib/pickerOptions'

/** The searchable, keyboard-driven option list inside a PropertyPicker. */
interface Props { options: PickerOption[]; selected: string[]; multiple?: boolean; label: string; searchPlaceholder: string; emptyText: string }
const props = defineProps<Props>()
const emit = defineEmits<{ choose: [value: string] }>()

const id = useId()
const search = ref('')
const active = ref(0)
const input = ref<HTMLInputElement | null>(null)
const list = ref<HTMLElement | null>(null)

const visible = computed(() => {
  const term = search.value.trim().toLowerCase()
  return term ? props.options.filter((o) => `${o.label} ${o.hint ?? ''}`.toLowerCase().includes(term)) : props.options
})

watch(visible, (options) => {
  active.value = Math.min(active.value, Math.max(options.length - 1, 0))
})

onMounted(() => {
  // Start on the current value so Enter keeps it and the arrows move from there.
  const current = props.options.findIndex((o) => props.selected.includes(o.value))
  active.value = Math.max(current, 0)
  void nextTick(() => input.value?.focus())
})

function move(delta: number) {
  if (visible.value.length === 0) return
  active.value = (active.value + delta + visible.value.length) % visible.value.length
  void nextTick(() => list.value?.querySelector('[data-active="true"]')?.scrollIntoView?.({ block: 'nearest' }))
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'ArrowDown') { event.preventDefault(); move(1) }
  else if (event.key === 'ArrowUp') { event.preventDefault(); move(-1) }
  else if (event.key === 'Home') { event.preventDefault(); active.value = 0 }
  else if (event.key === 'End') { event.preventDefault(); active.value = Math.max(visible.value.length - 1, 0) }
  else if (event.key === 'Enter') {
    event.preventDefault()
    const option = visible.value[active.value]
    if (option) emit('choose', option.value)
  }
}
</script>

<template>
  <div class="flex flex-col">
    <input
      ref="input" v-model="search" type="text" role="combobox" autocomplete="off"
      :aria-label="label" :placeholder="searchPlaceholder" aria-expanded="true" :aria-controls="`${id}-list`"
      :aria-activedescendant="visible[active] ? `${id}-${active}` : undefined"
      class="h-9 w-full border-b bg-transparent px-3 text-sm outline-none placeholder:text-muted-foreground"
      @keydown="onKeydown"
    />
    <ul
      :id="`${id}-list`" ref="list" role="listbox" :aria-label="label" :aria-multiselectable="multiple || undefined"
      class="max-h-64 overflow-y-auto p-1"
    >
      <li
        v-for="(option, index) in visible" :id="`${id}-${index}`" :key="option.value"
        role="option" :aria-selected="selected.includes(option.value)" :data-active="index === active"
        class="flex cursor-default select-none items-center gap-2 rounded-sm px-2 py-1.5 text-sm data-[active=true]:bg-accent data-[active=true]:text-accent-foreground"
        @mousemove="active = index" @click="emit('choose', option.value)"
      >
        <component :is="option.icon" v-if="option.icon" class="h-4 w-4 shrink-0 text-muted-foreground" />
        <span v-else-if="option.color" class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: option.color }" />
        <span class="min-w-0 flex-1 truncate">{{ option.label }}</span>
        <span v-if="option.hint" class="truncate text-xs text-muted-foreground">{{ option.hint }}</span>
        <Check class="h-4 w-4 shrink-0" :class="selected.includes(option.value) ? 'opacity-100' : 'opacity-0'" />
      </li>
      <li v-if="visible.length === 0" class="px-2 py-3 text-center text-sm text-muted-foreground">{{ emptyText }}</li>
    </ul>
  </div>
</template>
