<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useQueryCache } from '@pinia/colada'
import { Bell, BellOff, Maximize2, Trash2, X } from '@lucide/vue'
import { ApiError } from '@/api/client'
import * as api from '@/api/items'
import { useCanEdit } from '@/composables/useCanEdit'
import { useItemDetail } from '@/queries/items'
import { useDeleteItem, useItemPartMutation, useUpdateItem } from '@/queries/itemMutations'
import { keys, useSlug } from '@/queries/keys'
import { useToastStore } from '@/stores/toast'
import ConfirmDialog from '@/components/app/ConfirmDialog.vue'
import Alert from '@/components/ui/Alert.vue'
import Button from '@/components/ui/Button.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import Tabs from '@/components/ui/Tabs.vue'
import ItemActivity from './ItemActivity.vue'
import ItemRuns from './ItemRuns.vue'
import ItemAttachments from './ItemAttachments.vue'
import ItemChecklist from './ItemChecklist.vue'
import ItemChildren from './ItemChildren.vue'
import ItemComments from './ItemComments.vue'
import ItemDescription from './ItemDescription.vue'
import ItemLinks from './ItemLinks.vue'
import ItemProperties from './ItemProperties.vue'
import ItemRelations from './ItemRelations.vue'

/** Everything about one item; the side panel and the full page both show this. */
const props = defineProps<{ itemKey: string; variant: 'peek' | 'page' }>()
const emit = defineEmits<{ close: []; open: [key: string]; expand: []; deleted: [] }>()
const { t } = useI18n()
const canEdit = useCanEdit()
const cache = useQueryCache()
const slug = useSlug()
const toast = useToastStore()
const detail = useItemDetail(() => props.itemKey)
const { update } = useUpdateItem()
const deleteItem = useDeleteItem()

const title = ref('')
watch(() => detail.data.value?.title, (value) => { title.value = value ?? '' }, { immediate: true })

function saveTitle() {
  const item = detail.data.value
  const next = title.value.trim()
  if (!item || next === item.title) return
  if (next === '') { title.value = item.title; return }
  void update(item.key, { title: next })
}

const subscription = useItemPartMutation(() => props.itemKey, (s, k, subscribed: boolean) => api.setSubscription(s, k, subscribed), ['detail'])

function toggleSubscription() {
  const item = detail.data.value
  if (!item) return
  cache.setQueryData(keys.itemDetail(slug.value, item.key), { ...item, subscribed: !item.subscribed })
  subscription.mutate(!item.subscribed)
}

const tab = ref('comments')
const deleteOpen = ref(false)

async function confirmDelete() {
  try {
    await deleteItem.mutateAsync(props.itemKey)
    deleteOpen.value = false
    emit('deleted')
  } catch (err: unknown) {
    deleteOpen.value = false
    toast.error(err instanceof Error ? err.message : t('errors.delete'))
  }
}

const notFound = () => detail.error.value instanceof ApiError && detail.error.value.status === 404
</script>

<template>
  <article class="flex min-h-0 flex-1 flex-col" :aria-label="itemKey">
    <header class="flex shrink-0 items-center justify-between gap-2 border-b px-4 py-2">
      <span class="font-mono text-sm text-muted-foreground">{{ itemKey }}</span>
      <div class="flex items-center gap-1">
        <Button
          v-if="detail.data.value" variant="ghost" size="sm" class="h-8 gap-1.5 px-2" :aria-pressed="detail.data.value.subscribed"
          @click="toggleSubscription"
        >
          <component :is="detail.data.value.subscribed ? Bell : BellOff" class="h-4 w-4" />
          <span class="hidden sm:inline">{{ detail.data.value.subscribed ? t('items.detail.subscribed') : t('items.detail.subscribe') }}</span>
          <span class="sr-only sm:hidden">{{ detail.data.value.subscribed ? t('items.detail.unsubscribe') : t('items.detail.subscribe') }}</span>
        </Button>
        <Button v-if="canEdit && detail.data.value" variant="ghost" size="icon" class="h-8 w-8" :aria-label="t('items.detail.deleteItem')" @click="deleteOpen = true">
          <Trash2 class="h-4 w-4" />
        </Button>
        <template v-if="variant === 'peek'">
          <Button variant="ghost" size="icon" class="h-8 w-8" :aria-label="t('items.detail.openFull')" @click="emit('expand')"><Maximize2 class="h-4 w-4" /></Button>
          <Button variant="ghost" size="icon" class="h-8 w-8" :aria-label="t('common.close')" @click="emit('close')"><X class="h-4 w-4" /></Button>
        </template>
      </div>
    </header>

    <div class="min-h-0 flex-1 overflow-y-auto p-4">
      <div v-if="detail.status.value === 'pending'" class="space-y-3" aria-busy="true">
        <Skeleton class="h-8 w-2/3" /><Skeleton class="h-24 w-full" /><Skeleton class="h-40 w-full" />
      </div>
      <Alert v-else-if="!detail.data.value" variant="destructive" :title="notFound() ? t('items.detail.notFound') : (detail.error.value?.message ?? t('errors.fetchOne'))" />
      <div v-else class="grid gap-6" :class="variant === 'page' ? 'lg:grid-cols-[minmax(0,1fr)_20rem]' : ''">
        <div class="min-w-0 space-y-6">
          <input
            v-model="title" type="text" maxlength="255" :disabled="!canEdit" :aria-label="t('items.fields.title')"
            class="-mx-2 w-[calc(100%+1rem)] rounded-md border border-transparent bg-transparent px-2 py-1 text-xl font-semibold tracking-tight
                   hover:border-input focus-visible:border-input focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:hover:border-transparent"
            @blur="saveTitle" @keydown.enter.prevent="($event.target as HTMLInputElement).blur()"
          />
          <ItemDescription :description="detail.data.value.description" :can-edit="canEdit" @save="update(itemKey, { description: $event })" />
          <ItemProperties v-if="variant === 'peek'" :item="detail.data.value" :can-edit="canEdit" class="rounded-lg border p-3" @open="emit('open', $event)" />
          <ItemChecklist :item="detail.data.value" :can-edit="canEdit" />
          <ItemChildren :item="detail.data.value" :can-edit="canEdit" @open="emit('open', $event)" />
          <ItemRelations :item="detail.data.value" :can-edit="canEdit" @open="emit('open', $event)" />
          <ItemLinks :item="detail.data.value" :can-edit="canEdit" />
          <ItemAttachments :item="detail.data.value" :can-edit="canEdit" />
          <Tabs
            v-model="tab"
            :tabs="[
              { value: 'comments', label: t('items.comments.title', { n: detail.data.value.comment_count }) },
              { value: 'activity', label: t('items.activity.title') },
              ...(showRuns ? [{ value: 'runs', label: t('items.runs.title') }] : []),
            ]"
          >
            <template #comments><ItemComments :item-key="itemKey" /></template>
            <template #activity><ItemActivity v-if="tab === 'activity'" :item-key="itemKey" /></template>
            <template #runs><ItemRuns v-if="tab === 'runs'" :item-key="itemKey" /></template>
          </Tabs>
        </div>
        <aside v-if="variant === 'page'" class="lg:sticky lg:top-0 lg:self-start">
          <ItemProperties :item="detail.data.value" :can-edit="canEdit" class="rounded-lg border p-3" @open="emit('open', $event)" />
        </aside>
      </div>
    </div>

    <ConfirmDialog
      v-model:open="deleteOpen" destructive
      :title="t('items.detail.deleteTitle', { key: itemKey })" :description="t('items.detail.deleteDescription')"
      :confirm-label="t('items.detail.deleteItem')" :busy-label="t('items.detail.deleting')" :busy="deleteItem.isLoading.value"
      @confirm="confirmDelete"
    />
  </article>
</template>
