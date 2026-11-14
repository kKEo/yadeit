<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import LocaleSwitcher from '@/components/app/LocaleSwitcher.vue'
import UserMenu from '@/components/app/UserMenu.vue'
import WorkspaceSwitcher from '@/components/app/WorkspaceSwitcher.vue'
import { SquareKanban } from '@lucide/vue'

/** Shell header of a workspace: switcher, section navigation, language and account. */
const props = defineProps<{ slug: string }>()
const { t } = useI18n()

const links = computed(() => [
  { name: 'workspace', label: t('nav.projects') },
  { name: 'workspace-members', label: t('nav.members') },
  { name: 'workspace-settings', label: t('nav.settings') },
])
</script>

<template>
  <header class="sticky top-0 z-40 border-b bg-background/95 backdrop-blur">
    <div class="mx-auto flex h-14 max-w-6xl items-center justify-between gap-4 px-4">
      <div class="flex min-w-0 items-center gap-3">
        <RouterLink :to="{ name: 'home' }" class="flex items-center gap-2" :aria-label="t('app.name')">
          <SquareKanban class="h-5 w-5" />
          <span class="hidden font-semibold tracking-tight sm:inline">{{ t('app.name') }}</span>
        </RouterLink>
        <WorkspaceSwitcher />
      </div>
      <div class="flex shrink-0 items-center gap-2">
        <LocaleSwitcher />
        <UserMenu />
      </div>
    </div>
    <nav class="mx-auto flex max-w-6xl gap-1 overflow-x-auto px-4" :aria-label="t('nav.label')">
      <RouterLink
        v-for="link in links" :key="link.name"
        :to="{ name: link.name, params: { slug: props.slug } }"
        class="-mb-px whitespace-nowrap border-b-2 border-transparent px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
        exact-active-class="!border-primary !text-foreground"
      >{{ link.label }}</RouterLink>
    </nav>
  </header>
</template>
