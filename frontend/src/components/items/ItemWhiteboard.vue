<script setup lang="ts">
import { computed, defineAsyncComponent, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useQueryCache } from '@pinia/colada'
import { PenLine, Trash2 } from '@lucide/vue'
import * as api from '@/api/items'
import type { ItemDetail } from '@/api/types'
import { formatDateTime, formatRelative } from '@/lib/format'
import { useItemWhiteboard } from '@/queries/items'
import { useItemPartMutation } from '@/queries/itemMutations'
import { keys, useSlug } from '@/queries/keys'
import ConfirmDialog from '@/components/app/ConfirmDialog.vue'
import Alert from '@/components/ui/Alert.vue'
import Button from '@/components/ui/Button.vue'
import Skeleton from '@/components/ui/Skeleton.vue'

/**
 * The item described as a drawing: a picture of the whiteboard, and the way
 * into the editor. The picture is all that is loaded until someone edits.
 */
const props = defineProps<{ item: ItemDetail; canEdit: boolean }>()
const { t, locale } = useI18n()
const cache = useQueryCache()
const slug = useSlug()

// Loaded on demand: the editor brings the whole drawing library with it.
const WhiteboardEditor = defineAsyncComponent(() => import('./WhiteboardEditor.vue'))

const board = useItemWhiteboard(() => props.item.key, () => props.item.whiteboard !== null)
const editing = ref(false)
const removeOpen = ref(false)

// The picture is somebody's markup: as an image it is drawn, never run.
const picture = computed(() => (
  board.data.value ? `data:image/svg+xml;charset=utf-8,${encodeURIComponent(board.data.value.preview_svg)}` : null
))
const alt = computed(() => {
  const words = props.item.whiteboard?.text?.replace(/\s+/g, ' ').trim()
  const name = t('items.whiteboard.previewAlt', { key: props.item.key })
  return words ? `${name}: ${words.slice(0, 300)}` : name
})
const updated = computed(() => {
  const current = board.data.value
  if (!current) return null
  const when = formatRelative(current.updated_at, locale.value)
  return {
    text: current.updated_by ? t('items.whiteboard.updated', { when, name: current.updated_by.name }) : t('items.whiteboard.updatedAnonymous', { when }),
    title: formatDateTime(current.updated_at, locale.value),
  }
})

const remove = useItemPartMutation(() => props.item.key, (s, k) => api.deleteWhiteboard(s, k))

async function confirmRemove() {
  try {
    await remove.mutateAsync()
    cache.setQueryData(keys.itemDetail(slug.value, props.item.key), { ...props.item, whiteboard: null })
  } finally {
    removeOpen.value = false
  }
}
</script>

<template>
  <div class="space-y-2" data-item-whiteboard>
    <template v-if="!item.whiteboard">
      <p class="text-sm text-muted-foreground">{{ t('items.whiteboard.empty') }}</p>
      <Button v-if="canEdit" variant="outline" size="sm" class="h-8 gap-1.5" @click="editing = true">
        <PenLine class="h-3.5 w-3.5" />{{ t('items.whiteboard.draw') }}
      </Button>
    </template>

    <template v-else>
      <Skeleton v-if="board.status.value === 'pending'" class="h-48 w-full" aria-busy="true" />
      <Alert v-else-if="!picture" variant="destructive" :title="board.error.value?.message || t('items.whiteboard.loadFailed')" />
      <component
        :is="canEdit ? 'button' : 'div'" v-else :type="canEdit ? 'button' : undefined"
        :aria-label="canEdit ? t('items.whiteboard.open', { key: item.key }) : undefined"
        class="block w-full overflow-hidden rounded-md border bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        :class="canEdit ? 'cursor-pointer hover:border-ring' : ''"
        @click="canEdit && (editing = true)"
      >
        <img :src="picture" :alt="alt" class="mx-auto max-h-[28rem] w-full object-contain" />
      </component>

      <div class="flex flex-wrap items-center justify-between gap-2">
        <span v-if="updated" class="text-xs text-muted-foreground" :title="updated.title">{{ updated.text }}</span>
        <div v-if="canEdit" class="ml-auto flex items-center gap-1">
          <Button variant="ghost" size="sm" class="h-7 gap-1.5 px-2 text-xs text-muted-foreground" @click="editing = true">
            <PenLine class="h-3.5 w-3.5" />{{ t('items.whiteboard.edit') }}
          </Button>
          <Button variant="ghost" size="sm" class="h-7 gap-1.5 px-2 text-xs text-muted-foreground" @click="removeOpen = true">
            <Trash2 class="h-3.5 w-3.5" />{{ t('items.whiteboard.remove') }}
          </Button>
        </div>
      </div>
    </template>

    <WhiteboardEditor v-if="editing" :item="item" @close="editing = false" />
    <ConfirmDialog
      v-model:open="removeOpen" destructive
      :title="t('items.whiteboard.removeTitle', { key: item.key })" :description="t('items.whiteboard.removeDescription')"
      :confirm-label="t('items.whiteboard.removeConfirm')" :busy-label="t('items.whiteboard.removing')" :busy="remove.isLoading.value"
      @confirm="confirmRemove"
    />
  </div>
</template>
