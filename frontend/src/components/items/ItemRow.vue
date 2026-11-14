<script setup lang="ts">
import type { Item } from '@/api/types'
import DueDate from './DueDate.vue'
import LabelChips from './LabelChips.vue'
import PriorityIcon from './PriorityIcon.vue'
import StateDot from './StateDot.vue'
import UserInitials from './UserInitials.vue'
import DelegateMark from './DelegateMark.vue'
import NeedsInputBadge from './NeedsInputBadge.vue'
import ProgressMarks from './ProgressMarks.vue'

defineProps<{ item: Item; focused: boolean; selected: boolean }>()
const emit = defineEmits<{ open: []; focus: [] }>()
</script>

<template>
  <button
    type="button" :data-item-key="item.key" :data-focused="focused" :aria-current="selected ? 'true' : undefined"
    class="flex h-10 w-full items-center gap-3 border-b px-3 text-left text-sm outline-none transition-colors last:border-b-0
           hover:bg-accent/50 focus-visible:bg-accent data-[focused=true]:bg-accent data-[focused=true]:shadow-[inset_2px_0_0_hsl(var(--primary))]"
    @click="emit('open')" @focus="emit('focus')"
  >
    <span class="w-20 shrink-0 truncate font-mono text-xs text-muted-foreground">{{ item.key }}</span>
    <StateDot :state="item.state" />
    <PriorityIcon :priority="item.priority" />
    <span class="min-w-0 flex-1 truncate" :class="item.completed_at ? 'text-muted-foreground' : ''">{{ item.title }}</span>
    <LabelChips :labels="item.labels" :max="2" class="hidden md:inline-flex" />
    <DueDate v-if="item.due_date" :date="item.due_date" :done="item.completed_at !== null" />
    <ProgressMarks :item="item" />
    <NeedsInputBadge v-if="item.needs_input" compact />
    <DelegateMark v-if="item.delegate" :delegate="item.delegate" />
    <UserInitials :user="item.assignee" />
  </button>
</template>
