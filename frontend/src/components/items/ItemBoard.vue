<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { GroupBy, Item } from '@/api/types'
import type { ItemGroup } from '@/lib/grouping'
import GroupLabel from './GroupLabel.vue'
import ItemCard from './ItemCard.vue'

/**
 * One column per group; each column scrolls on its own. Dragging a card onto
 * another column asks the parent to change the grouped property.
 */
const props = defineProps<{
  groups: ItemGroup[]; groupBy: GroupBy; focusedKey: string | null; openKey: string | null; canEdit: boolean
}>()
const emit = defineEmits<{ open: [key: string]; focus: [key: string]; move: [item: Item, group: ItemGroup] }>()
const { t } = useI18n()

const DRAG_TYPE = 'application/x-yadeit-item'
const dragging = ref<{ item: Item; from: string } | null>(null)
const over = ref<string | null>(null)

function onDragStart(event: DragEvent, item: Item, group: ItemGroup) {
  dragging.value = { item, from: group.id }
  event.dataTransfer?.setData(DRAG_TYPE, item.key)
  event.dataTransfer?.setData('text/plain', item.key)
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move'
}

function onDragOver(event: DragEvent, group: ItemGroup) {
  if (!dragging.value || dragging.value.from === group.id) return
  event.preventDefault()
  if (event.dataTransfer) event.dataTransfer.dropEffect = 'move'
  over.value = group.id
}

function onDrop(group: ItemGroup) {
  const drag = dragging.value
  dragging.value = null
  over.value = null
  if (drag && drag.from !== group.id) emit('move', drag.item, group)
}

function onDragEnd() {
  dragging.value = null
  over.value = null
}
</script>

<template>
  <div class="flex h-[calc(100vh-15rem)] min-h-[20rem] gap-3 overflow-x-auto pb-2">
    <section
      v-for="group in groups" :key="group.id" :aria-label="t('items.board.column')" :data-column="group.id"
      class="flex w-72 shrink-0 flex-col rounded-lg border bg-muted/40 transition-colors"
      :class="over === group.id ? 'border-primary bg-accent' : ''"
      @dragover="onDragOver($event, group)" @drop.prevent="onDrop(group)"
    >
      <h2 class="flex h-10 shrink-0 items-center justify-between gap-2 border-b px-3 text-sm font-medium">
        <GroupLabel :group="group" />
        <span class="text-xs font-normal text-muted-foreground">{{ group.items.length }}</span>
      </h2>
      <ul v-if="props.groupBy !== 'none' || group.items.length" class="min-h-0 flex-1 space-y-2 overflow-y-auto p-2">
        <li v-for="item in group.items" :key="item.key" class="[contain-intrinsic-size:auto_6rem] [content-visibility:auto]">
          <ItemCard
            :item="item" :group-by="groupBy" :focused="item.key === focusedKey" :selected="item.key === openKey"
            :draggable="canEdit && groupBy !== 'none'"
            @open="emit('open', item.key)" @focus="emit('focus', item.key)"
            @dragstart="onDragStart($event, item, group)" @dragend="onDragEnd"
          />
        </li>
        <li v-if="group.items.length === 0" class="px-2 py-6 text-center text-xs text-muted-foreground">
          {{ t('items.board.emptyColumn') }}
        </li>
      </ul>
    </section>
  </div>
</template>
