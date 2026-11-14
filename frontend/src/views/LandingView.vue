<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Bot, FileText, Keyboard, MessageSquareHeart, SquareKanban } from '@lucide/vue'
import LocaleSwitcher from '@/components/app/LocaleSwitcher.vue'
import PublicFooter from '@/components/app/PublicFooter.vue'
import Button from '@/components/ui/Button.vue'

/** What a visitor sees before signing in: what the tool is, in one screen, and the way in. */
const { t } = useI18n()

const features = [
  { key: 'fast', icon: Keyboard },
  { key: 'agents', icon: Bot },
  { key: 'feedback', icon: MessageSquareHeart },
  { key: 'docs', icon: FileText },
] as const
</script>

<template>
  <div class="flex min-h-screen flex-col bg-background">
    <header class="mx-auto flex w-full max-w-5xl items-center justify-between gap-4 px-4 py-4">
      <span class="flex items-center gap-2">
        <SquareKanban class="h-6 w-6" />
        <span class="text-lg font-bold tracking-tight">{{ t('app.name') }}</span>
      </span>
      <div class="flex items-center gap-2">
        <LocaleSwitcher />
        <RouterLink :to="{ name: 'login' }"><Button variant="ghost" size="sm">{{ t('auth.submit') }}</Button></RouterLink>
      </div>
    </header>

    <main class="flex-1">
      <section class="mx-auto max-w-3xl px-4 pb-16 pt-12 text-center sm:pt-20">
        <h1 class="text-balance text-4xl font-bold tracking-tight sm:text-5xl">{{ t('landing.headline') }}</h1>
        <p class="mx-auto mt-5 max-w-2xl text-pretty text-lg text-muted-foreground">{{ t('landing.lead') }}</p>
        <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
          <RouterLink :to="{ name: 'register' }"><Button size="lg">{{ t('landing.start') }}</Button></RouterLink>
          <RouterLink :to="{ name: 'login' }"><Button size="lg" variant="outline">{{ t('auth.submit') }}</Button></RouterLink>
        </div>
        <p class="mt-4 text-sm text-muted-foreground">{{ t('landing.free') }}</p>
      </section>

      <section class="border-t bg-muted/30">
        <ul class="mx-auto grid max-w-5xl gap-6 px-4 py-12 sm:grid-cols-2">
          <li v-for="feature in features" :key="feature.key" class="rounded-lg border bg-background p-5" data-testid="landing-feature">
            <component :is="feature.icon" class="h-6 w-6 text-primary" />
            <h2 class="mt-3 font-semibold">{{ t(`landing.features.${feature.key}.title`) }}</h2>
            <p class="mt-1 text-sm text-muted-foreground">{{ t(`landing.features.${feature.key}.text`) }}</p>
          </li>
        </ul>
      </section>
    </main>

    <PublicFooter />
  </div>
</template>
