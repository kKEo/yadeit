<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import LocaleSwitcher from '@/components/app/LocaleSwitcher.vue'
import { SquareKanban } from '@lucide/vue'

/** Centered single-card page used by the sign-in, onboarding and email-link screens. */
const { t } = useI18n()
</script>

<template>
  <div class="flex min-h-screen flex-col items-center justify-center bg-gradient-to-br from-slate-50 to-slate-100 px-4 py-16">
    <div class="absolute right-4 top-4"><LocaleSwitcher /></div>
    <div class="mb-8 flex items-center gap-2 text-slate-800">
      <SquareKanban class="h-7 w-7" />
      <span class="text-xl font-bold tracking-tight">{{ t('app.name') }}</span>
    </div>
    <main class="w-full max-w-md"><slot /></main>
  </div>
</template>
