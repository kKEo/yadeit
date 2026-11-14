<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ItemGroup } from '@/lib/grouping'
import { KIND_ICONS, PRIORITY_ICONS, STATE_GROUP_COLORS } from '@/lib/pickerOptions'

/** Icon or colour dot plus the name of a list section / board column. */
const props = defineProps<{ group: ItemGroup }>()
const { t } = useI18n()

const view = computed(() => {
  const g = props.group
  if (g.state) return { label: g.state.name, color: g.state.color }
  if (g.stateGroup) return { label: t(`state.${g.stateGroup}`), color: STATE_GROUP_COLORS[g.stateGroup] }
  if (g.priority) return { label: t(`priority.${g.priority}`), icon: PRIORITY_ICONS[g.priority] }
  if (g.kind) return { label: t(`kind.${g.kind}`), icon: KIND_ICONS[g.kind] }
  if (g.by === 'assignee') return { label: g.assignee?.name ?? t('items.unassigned') }
  return { label: t('items.group.all') }
})
</script>

<template>
  <span class="inline-flex min-w-0 items-center gap-2">
    <component :is="view.icon" v-if="view.icon" class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
    <span v-else-if="view.color" class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: view.color }" aria-hidden="true" />
    <span class="truncate">{{ view.label }}</span>
  </span>
</template>
