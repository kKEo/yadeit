<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ItemDetail } from '@/api/types'
import { useItemProperties } from '@/composables/useItemProperties'
import { formatDateTime } from '@/lib/format'
import { useUiStore, type ItemProperty } from '@/stores/ui'
import PropertyPicker from '@/components/app/PropertyPicker.vue'
import LabelChips from './LabelChips.vue'

/** The properties column: every picker-edited property goes through PropertyPicker. */
const props = defineProps<{ item: ItemDetail; canEdit: boolean }>()
const emit = defineEmits<{ open: [key: string] }>()
const { t, locale } = useI18n()
const ui = useUiStore()
const { options, values, apply, update } = useItemProperties(() => props.item)

const PICKERS: ItemProperty[] = ['state', 'priority', 'assignee', 'labels', 'kind']
const openPicker = ref<ItemProperty | null>(null)

// S / P / A / L (or the command palette) ask for a picker of the item on screen: open it in place.
watch(() => ui.propertyRequest, (request) => {
  if (!request || request.key !== props.item.key || !props.canEdit) return
  openPicker.value = request.property
  ui.propertyRequest = null
}, { immediate: true })

const parentDraft = ref(props.item.parent ?? '')
watch(() => props.item.parent, (parent) => { parentDraft.value = parent ?? '' })

function saveParent() {
  const next = parentDraft.value.trim().toUpperCase() || null
  if (next !== props.item.parent) void update(props.item.key, { parent: next }).then((ok) => { if (!ok) parentDraft.value = props.item.parent ?? '' })
}

function saveDueDate(event: Event) {
  const value = (event.target as HTMLInputElement).value || null
  if (value !== props.item.due_date) void update(props.item.key, { due_date: value })
}

const fieldClass = 'h-8 w-full rounded-md border border-transparent bg-transparent px-2 text-sm hover:border-input focus-visible:border-input focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-70'
const dates = computed(() => [
  { label: t('items.fields.createdAt'), value: props.item.created_at },
  { label: t('items.fields.updatedAt'), value: props.item.updated_at },
  ...(props.item.completed_at ? [{ label: t('items.fields.completedAt'), value: props.item.completed_at }] : []),
])
</script>

<template>
  <dl class="grid grid-cols-[6.5rem_minmax(0,1fr)] items-center gap-x-2 gap-y-1 text-sm">
    <template v-for="property in PICKERS" :key="property">
      <dt class="text-muted-foreground">{{ t(`items.fields.${property}`) }}</dt>
      <dd>
        <PropertyPicker
          :open="openPicker === property" :label="t(`items.fields.${property}`)" :options="options[property]"
          :model-value="values[property]" :multiple="property === 'labels'" :disabled="!canEdit"
          :placeholder="property === 'labels' ? t('items.noLabels') : undefined"
          @update:open="openPicker = $event ? property : null" @update:model-value="apply(property, $event)"
        >
          <template v-if="property === 'labels' && item.labels.length" #trigger>
            <button
              type="button" :disabled="!canEdit" :aria-label="`${t('items.fields.labels')}: ${item.labels.map((l) => l.name).join(', ')}`"
              class="flex min-h-8 w-full flex-wrap items-center gap-1 rounded-md px-2 py-1 text-left hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none"
            ><LabelChips :labels="item.labels" class="flex-wrap" /></button>
          </template>
        </PropertyPicker>
      </dd>
    </template>

    <dt><label :for="`due-${item.key}`" class="text-muted-foreground">{{ t('items.fields.dueDate') }}</label></dt>
    <dd><input :id="`due-${item.key}`" type="date" :value="item.due_date ?? ''" :disabled="!canEdit" :class="fieldClass" @change="saveDueDate" /></dd>

    <dt><label :for="`parent-${item.key}`" class="text-muted-foreground">{{ t('items.fields.parent') }}</label></dt>
    <dd class="flex items-center gap-1">
      <input
        :id="`parent-${item.key}`" v-model="parentDraft" type="text" :disabled="!canEdit" :placeholder="t('items.detail.parentPlaceholder')"
        :class="[fieldClass, 'font-mono uppercase placeholder:normal-case placeholder:font-sans']"
        @blur="saveParent" @keydown.enter.prevent="($event.target as HTMLInputElement).blur()"
      />
      <button
        v-if="item.parent" type="button" class="shrink-0 rounded px-1.5 text-xs text-primary underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        @click="emit('open', item.parent)"
      >{{ t('items.detail.openParent') }}</button>
    </dd>

    <dt class="text-muted-foreground">{{ t('items.fields.project') }}</dt>
    <dd class="truncate px-2 py-1.5">{{ item.project.name }}</dd>

    <template v-for="date in dates" :key="date.label">
      <dt class="text-muted-foreground">{{ date.label }}</dt>
      <dd class="truncate px-2 py-1.5 text-muted-foreground">{{ formatDateTime(date.value, locale) }}</dd>
    </template>
  </dl>
</template>
