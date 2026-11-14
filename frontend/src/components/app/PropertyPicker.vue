<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { PopoverContent, PopoverPortal, PopoverRoot, PopoverTrigger } from 'radix-vue'
import { ChevronsUpDown } from '@lucide/vue'
import type { PickerOption } from '@/lib/pickerOptions'
import Dialog from '@/components/ui/Dialog.vue'
import PropertyPickerList from './PropertyPickerList.vue'

/**
 * The one way to pick a property value: a popover (or, when opened from the
 * keyboard without a trigger on screen, a dialog) with a searchable list.
 * Single choice closes on pick; `multiple` toggles values and stays open.
 */
interface Props {
  options: PickerOption[]
  /** A value, or an array of values when `multiple`. */
  modelValue: string | string[] | null
  /** Names the property, e.g. "State"; used as the accessible name. */
  label: string
  multiple?: boolean
  disabled?: boolean
  mode?: 'popover' | 'dialog'
  /** Shown on the default trigger when nothing is selected. */
  placeholder?: string
  /** Dialog mode: a line under the title, e.g. the item being changed. */
  description?: string
}
const props = withDefaults(defineProps<Props>(), { mode: 'popover' })
const emit = defineEmits<{ 'update:modelValue': [value: string | string[]] }>()
const { t } = useI18n()

const isOpen = defineModel<boolean>('open', { default: false })

const selected = computed(() => (
  Array.isArray(props.modelValue) ? props.modelValue : props.modelValue === null ? [] : [props.modelValue]
))
const selectedOptions = computed(() => props.options.filter((o) => selected.value.includes(o.value)))
const summary = computed(() => selectedOptions.value.map((o) => o.label).join(', ') || props.placeholder || t('picker.none'))

function choose(value: string) {
  if (props.multiple) {
    const next = selected.value.includes(value) ? selected.value.filter((v) => v !== value) : [...selected.value, value]
    emit('update:modelValue', next)
    return
  }
  emit('update:modelValue', value)
  isOpen.value = false
}

const listProps = computed(() => ({
  options: props.options,
  selected: selected.value,
  multiple: props.multiple,
  label: props.label,
  searchPlaceholder: t('picker.search', { property: props.label.toLowerCase() }),
  emptyText: t('picker.empty'),
}))
</script>

<template>
  <Dialog
    v-if="mode === 'dialog'" v-model:open="isOpen"
    :title="label" :description="description" :close-label="t('common.close')" class="max-w-sm gap-3 p-4"
  >
    <div class="-mx-4 -mb-4 border-t">
      <PropertyPickerList v-if="isOpen" v-bind="listProps" @choose="choose" />
    </div>
  </Dialog>

  <PopoverRoot v-else v-model:open="isOpen">
    <PopoverTrigger as-child :disabled="disabled">
      <slot name="trigger" :selected="selectedOptions" :summary="summary" :open="isOpen">
        <button
          type="button" :disabled="disabled" :aria-label="`${label}: ${summary}`"
          class="flex h-8 w-full items-center justify-between gap-2 rounded-md px-2 text-left text-sm hover:bg-accent
                 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-70"
        >
          <span class="flex min-w-0 items-center gap-2">
            <component :is="selectedOptions[0].icon" v-if="selectedOptions[0]?.icon" class="h-4 w-4 shrink-0 text-muted-foreground" />
            <span
              v-else-if="selectedOptions[0]?.color && !multiple"
              class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: selectedOptions[0].color }"
            />
            <span class="truncate" :class="selectedOptions.length ? '' : 'text-muted-foreground'">{{ summary }}</span>
          </span>
          <ChevronsUpDown v-if="!disabled" class="h-3.5 w-3.5 shrink-0 opacity-50" />
        </button>
      </slot>
    </PopoverTrigger>
    <PopoverPortal>
      <PopoverContent
        align="start" :side-offset="4" :aria-label="label"
        class="z-50 w-64 overflow-hidden rounded-md border bg-popover text-popover-foreground shadow-md
               data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0"
      >
        <PropertyPickerList v-bind="listProps" @choose="choose" />
      </PopoverContent>
    </PopoverPortal>
  </PopoverRoot>
</template>
