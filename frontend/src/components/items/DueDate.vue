<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { CalendarDays } from '@lucide/vue'
import { formatDate, isOverdue } from '@/lib/format'

defineProps<{ date: string; done?: boolean }>()
const { t, locale } = useI18n()
</script>

<template>
  <span
    class="inline-flex shrink-0 items-center gap-1 text-xs"
    :class="!done && isOverdue(date) ? 'font-medium text-red-600' : 'text-muted-foreground'"
    :title="t('items.fields.dueDate')"
  >
    <CalendarDays class="h-3.5 w-3.5" aria-hidden="true" />
    <span class="sr-only">{{ t('items.fields.dueDate') }}:</span>{{ formatDate(date, locale) }}
  </span>
</template>
