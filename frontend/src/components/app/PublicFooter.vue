<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'

/** Footer of the pages people see signed out: the legal pages and the docs for agents. */
const { t } = useI18n()
const year = new Date().getFullYear()
</script>

<template>
  <footer class="border-t">
    <div class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3 px-4 py-6 text-sm text-muted-foreground">
      <span>© {{ year }} {{ t('app.name') }}</span>
      <nav class="flex flex-wrap gap-4" :aria-label="t('landing.footer')">
        <RouterLink :to="{ name: 'terms' }" class="underline-offset-4 hover:underline">{{ t('legal.terms.title') }}</RouterLink>
        <RouterLink :to="{ name: 'privacy' }" class="underline-offset-4 hover:underline">{{ t('legal.privacy.title') }}</RouterLink>
        <a href="/llms.txt" class="underline-offset-4 hover:underline">{{ t('landing.forAgents') }}</a>
      </nav>
    </div>
  </footer>
</template>
