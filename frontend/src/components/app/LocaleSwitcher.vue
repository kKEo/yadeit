<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { SUPPORTED_LOCALES, setLocale, type AppLocale } from '@/i18n'

const { t, locale } = useI18n()

function onChange(event: Event) {
  setLocale((event.target as HTMLSelectElement).value as AppLocale)
}
</script>

<template>
  <select
    :value="locale"
    :aria-label="t('common.language')"
    class="h-9 rounded-md border border-input bg-background px-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
    @change="onChange"
  >
    <option v-for="code in SUPPORTED_LOCALES" :key="code" :value="code">{{ t(`locale.${code}`) }}</option>
  </select>
</template>
