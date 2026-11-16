<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useQueryCache } from '@pinia/colada'
import { X } from '@lucide/vue'
import { ApiError } from '@/api/client'
import * as api from '@/api/items'
import type { ItemDetail, Whiteboard } from '@/api/types'
import { blobToDataUrl } from '@/lib/dataUrl'
import type { WhiteboardCanvas, WhiteboardPicture } from '@/lib/whiteboardCanvas'
import { keys, useSlug } from '@/queries/keys'
import { useToastStore } from '@/stores/toast'
import ConfirmDialog from '@/components/app/ConfirmDialog.vue'
import Alert from '@/components/ui/Alert.vue'
import Button from '@/components/ui/Button.vue'

/**
 * The whiteboard of one item, over the whole window: a drawing needs the room
 * whether it was opened from the side panel or the full page. Nothing is saved
 * until Save; the drawing surface is loaded only when this opens.
 */
const props = defineProps<{ item: ItemDetail }>()
const emit = defineEmits<{ close: [] }>()
const { t, locale } = useI18n()
const cache = useQueryCache()
const slug = useSlug()
const toast = useToastStore()

const surface = ref<HTMLElement | null>(null)
const dialog = ref<HTMLElement | null>(null)
const status = ref<'loading' | 'ready' | 'failed'>('loading')
const dirty = ref(false)
const saving = ref(false)
const discardOpen = ref(false)
let canvas: WhiteboardCanvas | null = null
/** Version the drawing was opened at; 0 for a board that does not exist yet. */
let openedVersion = 0
let closed = false
/** Pictures already kept as attachments of the item, by their file id on the board. */
const attached = new Map<string, number>()
// The types the app shows in place; the server checks the bytes again.
const PICTURE_TYPES: Record<string, string> = { 'image/png': 'png', 'image/jpeg': 'jpg', 'image/gif': 'gif', 'image/webp': 'webp' }


/** The board's pictures, read from the item's attachments. One that is gone shows as missing on the board. */
async function loadPictures(board: Whiteboard | null): Promise<WhiteboardPicture[]> {
  const loaded = await Promise.all(Object.entries(board?.scene.files ?? {}).map(async ([id, file]) => {
    attached.set(id, file.attachment_id)
    try {
      const response = await fetch(api.attachmentUrl(slug.value, props.item.key, file.attachment_id), { credentials: 'same-origin' })
      if (!response.ok) return null
      const blob = await response.blob()
      return { id, mimeType: file.mime_type ?? blob.type, dataURL: await blobToDataUrl(blob) }
    } catch {
      return null
    }
  }))
  return loaded.filter((picture): picture is WhiteboardPicture => picture !== null)
}

/** Uploads the pictures that are new on the board; returns where each used picture is kept. */
async function attachPictures(pictures: WhiteboardPicture[]): Promise<Record<string, { attachment_id: number }>> {
  const files: Record<string, { attachment_id: number }> = {}
  for (const picture of pictures) {
    let id = attached.get(picture.id)
    if (id === undefined) {
      const extension = PICTURE_TYPES[picture.mimeType]
      if (!extension) throw new Error(t('items.whiteboard.pictureType'))
      const blob = await (await fetch(picture.dataURL)).blob()
      const file = new File([blob], `whiteboard-${picture.id.slice(0, 8)}.${extension}`, { type: picture.mimeType })
      id = (await api.uploadAttachment(slug.value, props.item.key, file)).id
      // Remembered at once, so saving again after a failure does not upload it twice.
      attached.set(picture.id, id)
    }
    files[picture.id] = { attachment_id: id }
  }
  return files
}

onMounted(async () => {
  document.documentElement.classList.add('overflow-hidden')
  dialog.value?.focus()
  try {
    // The editor's fonts come from the app itself, not from the CDN it would use by default.
    ;(window as unknown as { EXCALIDRAW_ASSET_PATH: string }).EXCALIDRAW_ASSET_PATH = '/excalidraw/'
    const [board, { mountWhiteboard }] = await Promise.all([
      props.item.whiteboard ? api.getWhiteboard(slug.value, props.item.key) : null,
      import('@/lib/whiteboardCanvas'),
    ])
    if (closed || !surface.value) return
    openedVersion = board?.version ?? 0
    const pictures = await loadPictures(board)
    if (closed || !surface.value) return
    canvas = mountWhiteboard(surface.value, {
      scene: board?.scene ?? null,
      pictures,
      language: locale.value === 'pl' ? 'pl-PL' : 'en',
      theme: document.documentElement.classList.contains('dark') ? 'dark' : 'light',
      onDirty: (value) => { dirty.value = value },
    })
    status.value = 'ready'
  } catch {
    status.value = 'failed'
  }
})

onBeforeUnmount(() => {
  closed = true
  canvas?.destroy()
  document.documentElement.classList.remove('overflow-hidden')
})

function requestClose() {
  if (saving.value) return
  if (dirty.value) discardOpen.value = true
  else emit('close')
}

function store(board: Whiteboard) {
  cache.setQueryData(keys.itemWhiteboard(slug.value, props.item.key), board)
  const detail = cache.getQueryData<ItemDetail>(keys.itemDetail(slug.value, props.item.key))
  if (detail) {
    cache.setQueryData(keys.itemDetail(slug.value, props.item.key), {
      ...detail, whiteboard: { version: board.version, text: board.text, updated_at: board.updated_at },
    })
  }
  void cache.invalidateQueries({ key: keys.itemActivity(slug.value, props.item.key) })
  // New pictures are new attachments of the item.
  void cache.invalidateQueries({ key: keys.itemDetail(slug.value, props.item.key) })
}

async function save() {
  if (!canvas || saving.value) return
  saving.value = true
  try {
    const drawing = await canvas.snapshot()
    if (!drawing) {
      toast.error(t('items.whiteboard.nothingToSave'))
      return
    }
    const files = await attachPictures(drawing.pictures)
    store(await api.saveWhiteboard(slug.value, props.item.key, {
      scene: { ...drawing.scene, files }, preview_svg: drawing.preview_svg, expected_version: openedVersion,
    }))
    toast.success(t('items.whiteboard.saved'))
    emit('close')
  } catch (err: unknown) {
    const conflict = err instanceof ApiError && err.status === 412
    toast.error(conflict ? t('items.whiteboard.changedElsewhere') : (err instanceof Error && err.message) || t('errors.generic'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div
      ref="dialog" role="dialog" aria-modal="true" tabindex="-1" :aria-label="`${item.key}: ${t('items.whiteboard.editorTitle')}`"
      class="fixed inset-0 z-50 flex flex-col bg-background outline-none"
    >
      <header class="flex shrink-0 items-center justify-between gap-3 border-b px-4 py-2">
        <div class="flex min-w-0 items-baseline gap-2">
          <span class="shrink-0 font-mono text-sm text-muted-foreground">{{ item.key }}</span>
          <h2 class="truncate text-sm font-semibold">{{ item.title }}</h2>
          <span class="hidden shrink-0 text-sm text-muted-foreground sm:inline">· {{ t('items.whiteboard.editorTitle') }}</span>
        </div>
        <div class="flex shrink-0 items-center gap-2">
          <span v-if="dirty" class="hidden text-xs text-muted-foreground sm:inline" role="status">{{ t('items.whiteboard.unsaved') }}</span>
          <Button size="sm" class="h-8" :disabled="status !== 'ready' || !dirty || saving" @click="save">
            {{ saving ? t('common.saving') : t('common.save') }}
          </Button>
          <Button variant="ghost" size="icon" class="h-8 w-8" :aria-label="t('common.close')" :title="t('common.close')" @click="requestClose">
            <X class="h-4 w-4" />
          </Button>
        </div>
      </header>

      <div class="relative min-h-0 flex-1">
        <!-- Excalidraw fills its parent, which therefore needs a real height. -->
        <div ref="surface" class="absolute inset-0" />
        <p v-if="status === 'loading'" class="absolute inset-0 flex items-center justify-center text-sm text-muted-foreground" aria-busy="true">
          {{ t('items.whiteboard.loading') }}
        </p>
        <div v-else-if="status === 'failed'" class="absolute inset-0 flex items-center justify-center p-6">
          <Alert variant="destructive" :title="t('items.whiteboard.loadFailed')" class="max-w-md" />
        </div>
      </div>

      <ConfirmDialog
        v-model:open="discardOpen" destructive
        :title="t('items.whiteboard.discardTitle')" :description="t('items.whiteboard.discardDescription')"
        :confirm-label="t('items.whiteboard.discard')" @confirm="emit('close')"
      />
    </div>
  </Teleport>
</template>
