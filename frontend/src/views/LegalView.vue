<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { SquareKanban } from '@lucide/vue'
import { OPERATOR_EMAIL, OPERATOR_NAME, OPERATOR_SET } from '@/lib/operator'
import LocaleSwitcher from '@/components/app/LocaleSwitcher.vue'
import PublicFooter from '@/components/app/PublicFooter.vue'
import Alert from '@/components/ui/Alert.vue'

/**
 * Terms of service and privacy policy. The texts are a starting template that
 * describes what the software does; whoever runs an installation has them
 * reviewed and completed before opening it to the public.
 */
const props = defineProps<{ document: 'terms' | 'privacy' }>()
const { t, tm, rt } = useI18n()

interface Section { title: string; body: string[] }
const sections = computed(() => tm(`legal.${props.document}.sections`) as unknown as Section[])
const named = { operator: OPERATOR_NAME, email: OPERATOR_EMAIL }
</script>

<template>
  <div class="flex min-h-screen flex-col bg-background">
    <header class="mx-auto flex w-full max-w-3xl items-center justify-between gap-4 px-4 py-4">
      <RouterLink :to="{ name: 'home' }" class="flex items-center gap-2">
        <SquareKanban class="h-6 w-6" />
        <span class="text-lg font-bold tracking-tight">{{ t('app.name') }}</span>
      </RouterLink>
      <LocaleSwitcher />
    </header>
    <main class="mx-auto w-full max-w-3xl flex-1 space-y-6 px-4 pb-16 pt-6">
      <h1 class="text-3xl font-bold tracking-tight">{{ t(`legal.${document}.title`) }}</h1>
      <Alert v-if="!OPERATOR_SET" variant="warning" :title="t('legal.template')" data-testid="legal-template" />
      <p class="text-muted-foreground">{{ t(`legal.${document}.intro`, named) }}</p>
      <section v-for="(section, index) in sections" :key="index" class="space-y-2" data-testid="legal-section">
        <h2 class="text-xl font-semibold">{{ index + 1 }}. {{ rt(section.title) }}</h2>
        <p v-for="(paragraph, n) in section.body" :key="n" class="leading-relaxed">{{ rt(paragraph, named) }}</p>
      </section>
    </main>
    <PublicFooter />
  </div>
</template>
