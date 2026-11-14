<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { UserRef } from '@/api/types'
import { initials } from '@/lib/format'

defineProps<{ user: UserRef | null }>()
const { t } = useI18n()
</script>

<template>
  <span
    class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold"
    :class="user ? 'bg-secondary text-secondary-foreground' : 'border border-dashed text-muted-foreground'"
    :title="user?.name ?? t('items.unassigned')"
  >
    <span aria-hidden="true">{{ user ? initials(user.name) : '–' }}</span>
    <span class="sr-only">{{ user?.name ?? t('items.unassigned') }}</span>
  </span>
</template>
