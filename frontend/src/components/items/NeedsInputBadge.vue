<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { MessageCircleQuestion } from '@lucide/vue'

/** Marks an item whose work is paused on a question to a person. `compact` shows the icon only. */
defineProps<{ compact?: boolean }>()
const { t } = useI18n()
</script>

<template>
  <span
    class="inline-flex shrink-0 items-center gap-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-900 dark:bg-amber-900/40 dark:text-amber-200"
    :title="t('items.needsInput')"
  >
    <MessageCircleQuestion class="h-3.5 w-3.5" aria-hidden="true" />
    <span :class="compact ? 'sr-only' : ''">{{ t('items.needsInput') }}</span>
  </span>
</template>
