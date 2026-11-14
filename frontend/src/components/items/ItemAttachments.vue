<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { onKeyStroke } from '@vueuse/core'
import { ChevronLeft, ChevronRight, Download, Paperclip, X } from '@lucide/vue'
import * as api from '@/api/items'
import type { ItemDetail } from '@/api/types'
import { formatBytes } from '@/lib/format'
import { useItemPartMutation } from '@/queries/itemMutations'
import { useSlug } from '@/queries/keys'
import Button from '@/components/ui/Button.vue'
import Dialog from '@/components/ui/Dialog.vue'

const props = defineProps<{ item: ItemDetail; canEdit: boolean }>()
const { t } = useI18n()
const slug = useSlug()
const input = ref<HTMLInputElement | null>(null)

const upload = useItemPartMutation(() => props.item.key, (s, k, file: File) => api.uploadAttachment(s, k, file))
const remove = useItemPartMutation(() => props.item.key, (s, k, id: number) => api.removeAttachment(s, k, id))

const urlOf = (id: number) => api.attachmentUrl(slug.value, props.item.key, id)

// Images form a gallery that opens in a lightbox; other files stay a list.
const images = computed(() => props.item.attachments.filter((file) => file.is_image))
const files = computed(() => props.item.attachments.filter((file) => !file.is_image))
const viewing = ref<number | null>(null)
const viewed = computed(() => images.value.find((file) => file.id === viewing.value) ?? null)
const lightboxOpen = computed({
  get: () => viewed.value !== null,
  set: (open) => { if (!open) viewing.value = null },
})

// Arrow keys page through the gallery while the lightbox is open.
onKeyStroke(['ArrowLeft', 'ArrowRight'], (event) => {
  if (viewed.value === null) return
  event.preventDefault()
  step(event.key === 'ArrowLeft' ? -1 : 1)
})

function step(delta: number) {
  const index = images.value.findIndex((file) => file.id === viewing.value)
  if (index === -1 || images.value.length < 2) return
  viewing.value = images.value[(index + delta + images.value.length) % images.value.length].id
}

async function onFiles(event: Event) {
  const target = event.target as HTMLInputElement
  for (const file of Array.from(target.files ?? [])) {
    // One at a time: a failed file is reported and the rest still go up.
    await upload.mutateAsync(file).catch(() => undefined)
  }
  target.value = ''
}
</script>

<template>
  <section class="space-y-2">
    <div class="flex items-center justify-between gap-2">
      <h3 class="text-sm font-medium">{{ t('items.attachments.title') }}</h3>
      <template v-if="canEdit">
        <input ref="input" type="file" multiple class="sr-only" tabindex="-1" :aria-label="t('items.attachments.upload')" @change="onFiles" />
        <Button variant="outline" size="sm" class="h-8 gap-1.5" :disabled="upload.isLoading.value" @click="input?.click()">
          <Paperclip class="h-3.5 w-3.5" />{{ upload.isLoading.value ? t('items.attachments.uploading') : t('items.attachments.upload') }}
        </Button>
      </template>
    </div>
    <ul v-if="images.length" class="grid grid-cols-3 gap-2 sm:grid-cols-4" data-gallery>
      <li v-for="file in images" :key="file.id" class="group relative">
        <button
          type="button" class="block w-full overflow-hidden rounded-md border focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          :aria-label="t('items.attachments.view', { name: file.name })" @click="viewing = file.id"
        ><img :src="urlOf(file.id)" :alt="file.name" loading="lazy" class="aspect-square w-full object-cover transition-transform group-hover:scale-105" /></button>
        <button
          v-if="canEdit" type="button" :aria-label="t('items.attachments.remove', { name: file.name })"
          class="absolute right-1 top-1 rounded bg-background/90 p-1 text-muted-foreground opacity-0 shadow hover:text-foreground focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring group-hover:opacity-100"
          @click="remove.mutate(file.id)"
        ><X class="h-3.5 w-3.5" /></button>
      </li>
    </ul>
    <ul v-if="files.length" class="grid gap-2 sm:grid-cols-2">
      <li v-for="file in files" :key="file.id" class="group flex items-center gap-2 rounded-md border p-2 text-sm">
        <Paperclip class="mx-3 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
        <span class="min-w-0 flex-1">
          <a
            :href="urlOf(file.id)" :download="file.name" class="flex items-center gap-1 underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            :aria-label="t('items.attachments.download', { name: file.name })"
          ><span class="truncate">{{ file.name }}</span><Download class="h-3 w-3 shrink-0" aria-hidden="true" /></a>
          <span class="block text-xs text-muted-foreground">{{ formatBytes(file.size) }}</span>
        </span>
        <button
          v-if="canEdit" type="button" :aria-label="t('items.attachments.remove', { name: file.name })"
          class="rounded p-1 text-muted-foreground opacity-0 hover:text-foreground focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring group-hover:opacity-100"
          @click="remove.mutate(file.id)"
        ><X class="h-3.5 w-3.5" /></button>
      </li>
    </ul>
    <p v-if="item.attachments.length === 0" class="text-sm text-muted-foreground">{{ t('items.attachments.empty') }}</p>

    <Dialog
      v-model:open="lightboxOpen" :title="viewed?.name ?? ''" :close-label="t('common.close')" class="max-w-4xl"
    >
      <div v-if="viewed" class="space-y-3">
        <img :src="urlOf(viewed.id)" :alt="viewed.name" class="mx-auto max-h-[70vh] max-w-full rounded-md object-contain" data-lightbox-image />
        <div class="flex items-center justify-between gap-2 text-sm">
          <span class="flex items-center gap-1">
            <Button variant="outline" size="sm" :disabled="images.length < 2" :aria-label="t('items.attachments.previous')" @click="step(-1)"><ChevronLeft class="h-4 w-4" /></Button>
            <Button variant="outline" size="sm" :disabled="images.length < 2" :aria-label="t('items.attachments.next')" @click="step(1)"><ChevronRight class="h-4 w-4" /></Button>
            <span class="ml-2 text-xs text-muted-foreground">{{ images.findIndex((f) => f.id === viewed!.id) + 1 }} / {{ images.length }} · {{ formatBytes(viewed.size) }}</span>
          </span>
          <a :href="urlOf(viewed.id)" :download="viewed.name" class="inline-flex items-center gap-1 text-primary underline-offset-2 hover:underline">
            <Download class="h-3.5 w-3.5" />{{ t('items.attachments.download', { name: viewed.name }) }}
          </a>
        </div>
      </div>
    </Dialog>
  </section>
</template>
