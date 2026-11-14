<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ExternalLink, GitBranch, GitPullRequest, Globe, Palette, X } from '@lucide/vue'
import * as api from '@/api/items'
import { LINK_KINDS, type ItemDetail, type LinkKind } from '@/api/types'
import { designEmbedUrl } from '@/lib/designEmbed'
import { useItemPartMutation } from '@/queries/itemMutations'
import Button from '@/components/ui/Button.vue'
import Select from '@/components/ui/Select.vue'

const props = defineProps<{ item: ItemDetail; canEdit: boolean }>()
const { t } = useI18n()

const icons = { url: Globe, pr: GitPullRequest, branch: GitBranch, design: Palette }
const url = ref('')
// "auto" lets the server read the kind off the address (a Figma link is a design, a pull request a PR).
const kind = ref<LinkKind | 'auto'>('auto')
/** Ids of design links whose preview was asked for; nothing is loaded from the design tool before that. */
const previewing = ref<number[]>([])

function togglePreview(id: number) {
  previewing.value = previewing.value.includes(id) ? previewing.value.filter((i) => i !== id) : [...previewing.value, id]
}

const add = useItemPartMutation(() => props.item.key, (s, k, req: { url: string; kind?: LinkKind }) => api.addLink(s, k, req))
const remove = useItemPartMutation(() => props.item.key, (s, k, id: number) => api.removeLink(s, k, id))

/** Only web links become anchors; anything else is shown as plain text. */
function isWebUrl(value: string): boolean {
  return /^https?:\/\//i.test(value)
}

async function submit() {
  const value = url.value.trim()
  if (!value) return
  try {
    await add.mutateAsync(kind.value === 'auto' ? { url: value } : { url: value, kind: kind.value })
    url.value = ''
  } catch {
    // reported as a toast; keep what was typed
  }
}
</script>

<template>
  <section class="space-y-2">
    <h3 class="text-sm font-medium">{{ t('items.links.title') }}</h3>
    <ul v-if="item.links.length" class="space-y-1">
      <li v-for="link in item.links" :key="link.id" class="group flex flex-wrap items-center gap-2 text-sm" :data-link-kind="link.kind">
        <component :is="icons[link.kind]" class="h-4 w-4 shrink-0 text-muted-foreground" :aria-label="t(`items.links.kind.${link.kind}`)" />
        <a
          v-if="isWebUrl(link.url)" :href="link.url" target="_blank" rel="noopener noreferrer"
          class="inline-flex min-w-0 items-center gap-1 text-blue-600 underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        ><span class="truncate">{{ link.title || link.url }}</span><ExternalLink class="h-3 w-3 shrink-0" aria-hidden="true" /></a>
        <span v-else class="min-w-0 truncate">{{ link.title || link.url }}</span>
        <button
          v-if="designEmbedUrl(link.url)" type="button" :aria-expanded="previewing.includes(link.id)"
          class="rounded px-1 text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          @click="togglePreview(link.id)"
        >{{ t(previewing.includes(link.id) ? 'items.links.hidePreview' : 'items.links.showPreview') }}</button>
        <button
          v-if="canEdit" type="button" :aria-label="t('items.links.remove', { url: link.title || link.url })"
          class="ml-auto rounded p-1 text-muted-foreground opacity-0 hover:text-foreground focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring group-hover:opacity-100"
          @click="remove.mutate(link.id)"
        ><X class="h-3.5 w-3.5" /></button>
        <!-- Loaded only on request; sandboxed, and the design tool is not told where it is shown. -->
        <iframe
          v-if="previewing.includes(link.id) && designEmbedUrl(link.url)" :src="designEmbedUrl(link.url)!" :title="link.title || t('items.links.kind.design')"
          class="mt-1 h-80 w-full basis-full rounded-md border" loading="lazy" referrerpolicy="no-referrer"
          sandbox="allow-scripts allow-same-origin allow-popups" allowfullscreen
        />
      </li>
    </ul>
    <form v-if="canEdit" class="flex items-center gap-2" @submit.prevent="submit">
      <Select
        :model-value="kind" @update:model-value="kind = $event as LinkKind | 'auto'" class="h-8 w-32 shrink-0" :aria-label="t('items.links.kindLabel')"
        :options="[{ value: 'auto', label: t('items.links.kind.auto') }, ...LINK_KINDS.map((k) => ({ value: k, label: t(`items.links.kind.${k}`) }))]"
      />
      <input
        v-model="url" type="url" maxlength="2000" :aria-label="t('items.links.url')" placeholder="https://"
        class="h-8 min-w-0 flex-1 rounded-md border border-input bg-background px-2 text-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      />
      <Button type="submit" size="sm" variant="outline" class="h-8" :disabled="!url.trim() || add.isLoading.value">{{ t('items.links.add') }}</Button>
    </form>
  </section>
</template>
