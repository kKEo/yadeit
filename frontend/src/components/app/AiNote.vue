<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { Sparkles } from '@lucide/vue'

/** The line that goes with everything the model produced: it is a suggestion, to be checked. */
const { t } = useI18n()
</script>

<template>
  <p class="flex items-center gap-1.5 text-xs text-muted-foreground" data-ai-note>
    <Sparkles class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ t('ai.label') }}
  </p>
</template>
