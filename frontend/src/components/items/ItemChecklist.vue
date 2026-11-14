<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useQueryCache } from '@pinia/colada'
import { Plus, X } from '@lucide/vue'
import * as api from '@/api/items'
import type { ChecklistEntry, ItemDetail } from '@/api/types'
import { useItemPartMutation } from '@/queries/itemMutations'
import { keys, useSlug } from '@/queries/keys'
import Checkbox from '@/components/ui/Checkbox.vue'

const props = defineProps<{ item: ItemDetail; canEdit: boolean }>()
const { t } = useI18n()
const cache = useQueryCache()
const slug = useSlug()
const text = ref('')

const done = computed(() => props.item.checklist.filter((entry) => entry.is_done).length)

const add = useItemPartMutation(() => props.item.key, (s, k, value: string) => api.addChecklistEntry(s, k, value))
const toggle = useItemPartMutation(() => props.item.key, (s, k, entry: ChecklistEntry) => api.updateChecklistEntry(s, k, entry.id, { is_done: !entry.is_done }))
const remove = useItemPartMutation(() => props.item.key, (s, k, id: number) => api.removeChecklistEntry(s, k, id))

function tick(entry: ChecklistEntry) {
  // Show the tick at once; the refetch after the request settles confirms or corrects it.
  cache.setQueryData(keys.itemDetail(slug.value, props.item.key), {
    ...props.item,
    checklist: props.item.checklist.map((e) => (e.id === entry.id ? { ...e, is_done: !e.is_done } : e)),
  })
  toggle.mutate(entry)
}

function submit() {
  const value = text.value.trim()
  if (!value) return
  text.value = ''
  add.mutate(value)
}
</script>

<template>
  <section class="space-y-2">
    <h3 class="flex items-center gap-2 text-sm font-medium">
      {{ t('items.checklist.title') }}
      <span v-if="item.checklist.length" class="text-xs font-normal text-muted-foreground">{{ done }}/{{ item.checklist.length }}</span>
    </h3>
    <ul v-if="item.checklist.length" class="space-y-1">
      <li v-for="entry in item.checklist" :key="entry.id" class="group flex items-center gap-2 text-sm">
        <Checkbox :id="`check-${entry.id}`" :model-value="entry.is_done" :disabled="!canEdit" @update:model-value="tick(entry)" />
        <label :for="`check-${entry.id}`" class="min-w-0 flex-1 break-words" :class="entry.is_done ? 'text-muted-foreground line-through' : ''">{{ entry.text }}</label>
        <button
          v-if="canEdit" type="button" :aria-label="t('items.checklist.remove', { text: entry.text })"
          class="rounded p-1 text-muted-foreground opacity-0 hover:text-foreground focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring group-hover:opacity-100"
          @click="remove.mutate(entry.id)"
        ><X class="h-3.5 w-3.5" /></button>
      </li>
    </ul>
    <form v-if="canEdit" class="flex items-center gap-2" @submit.prevent="submit">
      <Plus class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
      <input
        v-model="text" type="text" maxlength="500" :aria-label="t('items.checklist.add')" :placeholder="t('items.checklist.add')"
        class="h-8 min-w-0 flex-1 rounded-md border border-transparent bg-transparent px-2 text-sm placeholder:text-muted-foreground hover:border-input focus-visible:border-input focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      />
    </form>
  </section>
</template>
