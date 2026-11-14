<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { Priority } from '@/api/types'
import { PRIORITY_ICONS } from '@/lib/pickerOptions'

defineProps<{ priority: Priority }>()
const { t } = useI18n()
</script>

<template>
  <span class="inline-flex shrink-0" :title="t(`priority.${priority}`)">
    <component
      :is="PRIORITY_ICONS[priority]" class="h-4 w-4" aria-hidden="true"
      :class="priority === 'urgent' ? 'text-red-600' : priority === 'none' ? 'text-muted-foreground/50' : 'text-muted-foreground'"
    />
    <span class="sr-only">{{ t(`priority.${priority}`) }}</span>
  </span>
</template>
