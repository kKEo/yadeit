<script setup lang="ts">
import type { WorkItemState } from '@/api/types'
import Badge from '@/components/ui/Badge.vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{ state: WorkItemState; class?: string }>()
const { t } = useI18n()

type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'outline' | 'success' | 'warning' | 'info'

const variant = computed((): BadgeVariant => {
  switch (props.state) {
    case 'completed': return 'success'
    case 'started':   return 'info'
    case 'unstarted': return 'secondary'
    case 'cancelled': return 'destructive'
    case 'backlog':
    default:          return 'outline'
  }
})

const label = computed(() => t(`state.${props.state}`))
</script>

<template>
  <Badge :variant="variant" :class="props.class">{{ label }}</Badge>
</template>
