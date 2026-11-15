<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ItemDetail } from '@/api/types'
import { useItemProperties } from '@/composables/useItemProperties'
import { formatDateTime } from '@/lib/format'
import { useUiStore } from '@/stores/ui'
import PropertyPicker from '@/components/app/PropertyPicker.vue'
import LabelChips from './LabelChips.vue'

/**
 * The details column of the full page: the properties the header leaves out
 * there (labels, agent, cycle) and what is only read (project, dates).
 */
const props = defineProps<{ item: ItemDetail; canEdit: boolean }>()
const { t, locale } = useI18n()
const ui = useUiStore()
const { options, values, apply, agents, delegateOptions, delegateTo, cycleOptions, hasCycles, setCycle } = useItemProperties(() => props.item)

const labelsOpen = ref(false)
const delegateOpen = ref(false)
const cycleOpen = ref(false)

// L (or the command palette) asks for the labels picker of the item on screen.
watch(() => ui.propertyRequest, (request) => {
  if (!request || request.key !== props.item.key || request.property !== 'labels' || !props.canEdit) return
  labelsOpen.value = true
  ui.propertyRequest = null
}, { immediate: true })

const dates = () => [
  { label: t('items.fields.createdAt'), value: props.item.created_at },
  { label: t('items.fields.updatedAt'), value: props.item.updated_at },
  ...(props.item.completed_at ? [{ label: t('items.fields.completedAt'), value: props.item.completed_at }] : []),
]
</script>

<template>
  <section :aria-label="t('items.detail.details')">
    <h3 class="mb-2 px-2 text-sm font-medium">{{ t('items.detail.details') }}</h3>
    <dl class="grid grid-cols-[6rem_minmax(0,1fr)] items-center gap-x-2 gap-y-1 text-sm">
      <dt class="px-2 text-muted-foreground">{{ t('items.fields.labels') }}</dt>
      <dd>
        <PropertyPicker
          v-model:open="labelsOpen" :label="t('items.fields.labels')" :options="options.labels" :model-value="values.labels" multiple
          :disabled="!canEdit" :placeholder="t('items.noLabels')" @update:model-value="apply('labels', $event)"
        >
          <template v-if="item.labels.length" #trigger>
            <button
              type="button" :disabled="!canEdit" :aria-label="`${t('items.fields.labels')}: ${item.labels.map((l) => l.name).join(', ')}`"
              class="flex min-h-8 w-full flex-wrap items-center gap-1 rounded-md px-2 py-1 text-left hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none"
            ><LabelChips :labels="item.labels" class="flex-wrap" /></button>
          </template>
        </PropertyPicker>
      </dd>

      <template v-if="agents.length > 0 || item.delegate">
        <dt class="px-2 text-muted-foreground">{{ t('items.fields.delegate') }}</dt>
        <dd>
          <PropertyPicker
            v-model:open="delegateOpen" :label="t('items.fields.delegate')" :options="delegateOptions"
            :model-value="item.delegate ? String(item.delegate.id) : ''" :disabled="!canEdit" @update:model-value="delegateTo($event)"
          />
        </dd>
      </template>

      <template v-if="hasCycles">
        <dt class="px-2 text-muted-foreground">{{ t('items.fields.cycle') }}</dt>
        <dd>
          <PropertyPicker
            v-model:open="cycleOpen" :label="t('items.fields.cycle')" :options="cycleOptions"
            :model-value="item.cycle ? String(item.cycle.number) : ''" :disabled="!canEdit" @update:model-value="setCycle($event)"
          />
        </dd>
      </template>

      <dt class="px-2 text-muted-foreground">{{ t('items.fields.project') }}</dt>
      <dd class="truncate px-2 py-1.5">{{ item.project.name }}</dd>

      <template v-for="date in dates()" :key="date.label">
        <dt class="px-2 text-muted-foreground">{{ date.label }}</dt>
        <dd class="truncate px-2 py-1.5 text-muted-foreground">{{ formatDateTime(date.value, locale) }}</dd>
      </template>
    </dl>
  </section>
</template>
