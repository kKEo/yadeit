<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useQueryCache } from '@pinia/colada'
import { useItemPeek } from '@/composables/useItemPeek'
import { useItemProperties } from '@/composables/useItemProperties'
import { findCachedItem } from '@/queries/itemCache'
import { useSlug } from '@/queries/keys'
import { useUiStore } from '@/stores/ui'
import PropertyPicker from '@/components/app/PropertyPicker.vue'

/**
 * S / P / A / L on a row that is only focused (no detail on screen): the same
 * PropertyPicker, shown as a dialog because there is no trigger to anchor to.
 * When the item's detail is open, its own properties column answers instead.
 */
const { t } = useI18n()
const ui = useUiStore()
const cache = useQueryCache()
const slug = useSlug()
const { peekKey, isDetailPage, activeKey } = useItemPeek()

const request = computed(() => {
  const current = ui.propertyRequest
  if (!current) return null
  const shownInDetail = current.key === peekKey.value || (isDetailPage.value && current.key === activeKey.value)
  return shownInDetail ? null : current
})
const item = computed(() => (request.value ? findCachedItem(cache, slug.value, request.value.key) ?? null : null))
const { options, values, apply } = useItemProperties(() => item.value)

const open = computed({
  get: () => request.value !== null && item.value !== null,
  set: (value) => { if (!value) ui.propertyRequest = null },
})
</script>

<template>
  <PropertyPicker
    v-if="request && item" v-model:open="open" mode="dialog"
    :label="t(`items.fields.${request.property}`)" :description="`${item.key} · ${item.title}`"
    :options="options[request.property]" :model-value="values[request.property]" :multiple="request.property === 'labels'"
    @update:model-value="apply(request.property, $event)"
  />
</template>
