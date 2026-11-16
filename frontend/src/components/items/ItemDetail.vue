<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useQueryCache } from '@pinia/colada'
import { ApiError } from '@/api/client'
import * as api from '@/api/items'
import { useCanEdit } from '@/composables/useCanEdit'
import { useItemDetail } from '@/queries/items'
import { useDeleteItem, useItemPartMutation, useUpdateItem } from '@/queries/itemMutations'
import { keys, useSlug } from '@/queries/keys'
import { formatDateTime, formatRelative } from '@/lib/format'
import { useToastStore } from '@/stores/toast'
import ConfirmDialog from '@/components/app/ConfirmDialog.vue'
import Alert from '@/components/ui/Alert.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import Tabs from '@/components/ui/Tabs.vue'
import { useMembers } from '@/queries/workspace'
import ItemActivity from './ItemActivity.vue'
import ItemAi from './ItemAi.vue'
import ItemRuns from './ItemRuns.vue'
import ItemAttachments from './ItemAttachments.vue'
import ItemChecklist from './ItemChecklist.vue'
import ItemChildren from './ItemChildren.vue'
import ItemComments from './ItemComments.vue'
import ItemDescription from './ItemDescription.vue'
import ItemHeader from './ItemHeader.vue'
import ItemLinks from './ItemLinks.vue'
import ItemProperties from './ItemProperties.vue'
import ItemRelations from './ItemRelations.vue'
import ItemWhiteboard from './ItemWhiteboard.vue'

/**
 * Everything about one item; the side panel and the full page both show this.
 * The panel carries every property in the header; the page has the width for a details column.
 */
const props = defineProps<{ itemKey: string; variant: 'peek' | 'page' }>()
const emit = defineEmits<{ close: []; open: [key: string]; expand: []; collapse: []; deleted: [] }>()
const { t, locale } = useI18n()
const canEdit = useCanEdit()
const cache = useQueryCache()
const slug = useSlug()
const toast = useToastStore()
const detail = useItemDetail(() => props.itemKey)
const { update } = useUpdateItem()
const deleteItem = useDeleteItem()

const subscription = useItemPartMutation(() => props.itemKey, (s, k, subscribed: boolean) => api.setSubscription(s, k, subscribed), ['detail'])

function toggleSubscription() {
  const item = detail.data.value
  if (!item) return
  cache.setQueryData(keys.itemDetail(slug.value, item.key), { ...item, subscribed: !item.subscribed })
  subscription.mutate(!item.subscribed)
}

const tab = ref('comments')
// The runs tab is noise in a workspace without agents.
const members = useMembers()
const showRuns = computed(() => detail.data.value?.delegate != null || (members.data.value ?? []).some((m) => m.type === 'agent'))
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

const dates = computed(() => {
  const item = detail.data.value
  if (!item) return []
  return [
    { label: t('items.fields.createdAt'), value: item.created_at },
    { label: t('items.fields.updatedAt'), value: item.updated_at },
    ...(item.completed_at ? [{ label: t('items.fields.completedAt'), value: item.completed_at }] : []),
  ]
})

const notFound = () => detail.error.value instanceof ApiError && detail.error.value.status === 404
</script>

<template>
  <article class="flex min-h-0 flex-1 flex-col" :aria-label="itemKey">
    <ItemHeader
      :item-key="itemKey" :item="detail.data.value ?? null" :can-edit="canEdit" :variant="variant"
      @open="emit('open', $event)" @expand="emit('expand')" @collapse="emit('collapse')" @close="emit('close')"
      @delete="deleteOpen = true" @toggle-subscription="toggleSubscription"
    />

    <div :class="variant === 'page' ? 'pt-6' : 'min-h-0 flex-1 overflow-y-auto p-4'">
      <div v-if="detail.status.value === 'pending'" class="space-y-3" aria-busy="true">
        <Skeleton class="h-8 w-2/3" /><Skeleton class="h-24 w-full" /><Skeleton class="h-40 w-full" />
      </div>
      <Alert v-else-if="!detail.data.value" variant="destructive" :title="notFound() ? t('items.detail.notFound') : (detail.error.value?.message ?? t('errors.fetchOne'))" />
      <div v-else class="grid gap-8" :class="variant === 'page' ? 'lg:grid-cols-[minmax(0,1fr)_18rem]' : ''">
        <div class="min-w-0 space-y-6">
          <ItemDescription
            :description="detail.data.value.description" :can-edit="canEdit" :has-whiteboard="detail.data.value.whiteboard !== null"
            @save="update(itemKey, { description: $event })"
          >
            <template #whiteboard><ItemWhiteboard :item="detail.data.value" :can-edit="canEdit" /></template>
          </ItemDescription>
          <ItemAi :item="detail.data.value" />
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
          <p v-if="variant === 'peek'" class="flex flex-wrap gap-x-1.5 border-t pt-3 text-xs text-muted-foreground" data-item-meta>
            <span>{{ detail.data.value.project.name }}</span>
            <template v-for="date in dates" :key="date.label">
              <span aria-hidden="true">·</span>
              <span :title="formatDateTime(date.value, locale)">{{ date.label }} {{ formatRelative(date.value, locale) }}</span>
            </template>
          </p>
        </div>
        <aside v-if="variant === 'page'" class="lg:sticky lg:top-[calc(var(--app-header-height,3.5rem)+9.5rem)] lg:self-start lg:border-l lg:pl-4">
          <ItemProperties :item="detail.data.value" :can-edit="canEdit" />
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
