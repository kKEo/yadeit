<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import type { ItemDetail } from '@/api/types'
import { parseViewState } from '@/lib/viewState'
import { useToastStore } from '@/stores/toast'
import { useUiStore } from '@/stores/ui'
import Dialog from '@/components/ui/Dialog.vue'
import QuickCaptureForm from './QuickCaptureForm.vue'

/** "C" from anywhere in a workspace: a new item in a few keystrokes. */
const route = useRoute()
const { t } = useI18n()
const ui = useUiStore()
const toast = useToastStore()

// Filters only describe "where the user is" on the items page.
const filters = computed(() => (route.name === 'items' ? parseViewState(route.query).filters : {}))

function onCreated(item: ItemDetail) {
  ui.focusedKey = item.key
  toast.success(t('capture.created', { key: item.key }))
}
</script>

<template>
  <Dialog
    v-model:open="ui.quickCaptureOpen" :title="t('items.create.title')" :description="t('items.create.description')"
    :close-label="t('common.close')" class="max-w-xl"
  >
    <QuickCaptureForm
      v-if="ui.quickCaptureOpen" :filters="filters" :parent="ui.captureParent"
      @created="onCreated" @done="ui.quickCaptureOpen = false"
    />
  </Dialog>
</template>
