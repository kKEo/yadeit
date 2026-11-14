<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { Bot } from '@lucide/vue'
import type { UserRef } from '@/api/types'

/** Shows that an agent is doing the work on an item. */
defineProps<{ delegate: UserRef }>()
const { t } = useI18n()
</script>

<template>
  <span class="inline-flex shrink-0 items-center text-muted-foreground" :title="t('items.delegatedTo', { name: delegate.name })">
    <Bot class="h-4 w-4" aria-hidden="true" />
    <span class="sr-only">{{ t('items.delegatedTo', { name: delegate.name }) }}</span>
  </span>
</template>
