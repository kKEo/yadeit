<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { X } from '@lucide/vue'
import * as api from '@/api/items'
import { RELATION_TYPES, type ItemDetail } from '@/api/types'
import { useItemPartMutation } from '@/queries/itemMutations'
import Button from '@/components/ui/Button.vue'
import Select from '@/components/ui/Select.vue'
import StateDot from './StateDot.vue'

type AddableType = (typeof RELATION_TYPES)[number]

const props = defineProps<{ item: ItemDetail; canEdit: boolean }>()
const emit = defineEmits<{ open: [key: string] }>()
const { t } = useI18n()

const type = ref<AddableType>('blocks')
const target = ref('')

const add = useItemPartMutation(() => props.item.key, (s, k, req: { type: AddableType; item: string }) => api.addRelation(s, k, req))
const remove = useItemPartMutation(() => props.item.key, (s, k, id: number) => api.removeRelation(s, k, id))

async function submit() {
  const key = target.value.trim().toUpperCase()
  if (!key) return
  try {
    await add.mutateAsync({ type: type.value, item: key })
    target.value = ''
  } catch {
    // reported as a toast; keep what was typed
  }
}
</script>

<template>
  <section class="space-y-2">
    <h3 class="text-sm font-medium">{{ t('items.relations.title') }}</h3>
    <ul v-if="item.relations.length" class="space-y-1">
      <li v-for="relation in item.relations" :key="`${relation.type}-${relation.id}`" class="group flex items-center gap-2 text-sm">
        <span class="w-28 shrink-0 text-xs text-muted-foreground">{{ t(`relation.${relation.type}`) }}</span>
        <button
          type="button" class="flex min-w-0 items-center gap-2 rounded text-left hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          @click="emit('open', relation.item.key)"
        >
          <StateDot v-if="relation.item.state" :state="relation.item.state" />
          <span class="shrink-0 font-mono text-xs text-muted-foreground">{{ relation.item.key }}</span>
          <span class="truncate">{{ relation.item.title }}</span>
        </button>
        <button
          v-if="canEdit" type="button" :aria-label="t('items.relations.remove', { key: relation.item.key })"
          class="ml-auto rounded p-1 text-muted-foreground opacity-0 hover:text-foreground focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring group-hover:opacity-100"
          @click="remove.mutate(relation.id)"
        ><X class="h-3.5 w-3.5" /></button>
      </li>
    </ul>
    <form v-if="canEdit" class="flex items-center gap-2" @submit.prevent="submit">
      <Select
        :model-value="type" @update:model-value="type = $event as AddableType" class="h-8 w-36 shrink-0" :aria-label="t('items.relations.type')"
        :options="RELATION_TYPES.map((r) => ({ value: r, label: t(`relation.${r}`) }))"
      />
      <input
        v-model="target" type="text" :aria-label="t('items.relations.key')" :placeholder="t('items.relations.keyPlaceholder')"
        class="h-8 min-w-0 flex-1 rounded-md border border-input bg-background px-2 font-mono text-sm uppercase placeholder:font-sans placeholder:normal-case placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      />
      <Button type="submit" size="sm" variant="outline" class="h-8" :disabled="!target.trim() || add.isLoading.value">{{ t('items.relations.add') }}</Button>
    </form>
  </section>
</template>
