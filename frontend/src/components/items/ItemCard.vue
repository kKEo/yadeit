<script setup lang="ts">
import type { GroupBy, Item } from '@/api/types'
import DueDate from './DueDate.vue'
import LabelChips from './LabelChips.vue'
import PriorityIcon from './PriorityIcon.vue'
import StateDot from './StateDot.vue'
import UserInitials from './UserInitials.vue'

defineProps<{ item: Item; focused: boolean; selected: boolean; draggable: boolean; groupBy: GroupBy }>()
const emit = defineEmits<{ open: []; focus: []; dragstart: [event: DragEvent]; dragend: [] }>()
</script>

<template>
  <button
    type="button" :draggable="draggable" :data-item-key="item.key" :data-focused="focused" :aria-current="selected ? 'true' : undefined"
    class="block w-full rounded-md border bg-card p-2.5 text-left text-sm shadow-sm outline-none transition-colors
           hover:border-foreground/30 focus-visible:ring-2 focus-visible:ring-ring data-[focused=true]:ring-2 data-[focused=true]:ring-ring"
    :class="draggable ? 'cursor-grab active:cursor-grabbing' : ''"
    @click="emit('open')" @focus="emit('focus')" @dragstart="emit('dragstart', $event)" @dragend="emit('dragend')"
  >
    <span class="flex items-center justify-between gap-2">
      <span class="truncate font-mono text-xs text-muted-foreground">{{ item.key }}</span>
      <UserInitials v-if="groupBy !== 'assignee'" :user="item.assignee" />
    </span>
    <span class="mt-1 line-clamp-3 block break-words" :class="item.completed_at ? 'text-muted-foreground' : ''">{{ item.title }}</span>
    <span class="mt-2 flex flex-wrap items-center gap-2">
      <StateDot v-if="groupBy !== 'state'" :state="item.state" show-name class="text-xs text-muted-foreground" />
      <PriorityIcon v-if="groupBy !== 'priority'" :priority="item.priority" />
      <DueDate v-if="item.due_date" :date="item.due_date" :done="item.completed_at !== null" />
      <LabelChips :labels="item.labels" :max="2" />
    </span>
  </button>
</template>
