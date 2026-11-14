<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Download, Paperclip, X } from '@lucide/vue'
import * as api from '@/api/items'
import type { ItemDetail } from '@/api/types'
import { formatBytes } from '@/lib/format'
import { useItemPartMutation } from '@/queries/itemMutations'
import { useSlug } from '@/queries/keys'
import Button from '@/components/ui/Button.vue'

const props = defineProps<{ item: ItemDetail; canEdit: boolean }>()
const { t } = useI18n()
const slug = useSlug()
const input = ref<HTMLInputElement | null>(null)

const upload = useItemPartMutation(() => props.item.key, (s, k, file: File) => api.uploadAttachment(s, k, file))
const remove = useItemPartMutation(() => props.item.key, (s, k, id: number) => api.removeAttachment(s, k, id))

const urlOf = (id: number) => api.attachmentUrl(slug.value, props.item.key, id)

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
    <ul v-if="item.attachments.length" class="grid gap-2 sm:grid-cols-2">
      <li v-for="file in item.attachments" :key="file.id" class="group flex items-center gap-2 rounded-md border p-2 text-sm">
        <a
          v-if="file.is_image" :href="urlOf(file.id)" target="_blank" rel="noopener noreferrer"
          class="shrink-0 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        ><img :src="urlOf(file.id)" :alt="file.name" loading="lazy" class="h-10 w-10 rounded object-cover" /></a>
        <Paperclip v-else class="mx-3 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
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
    <p v-else class="text-sm text-muted-foreground">{{ t('items.attachments.empty') }}</p>
  </section>
</template>
