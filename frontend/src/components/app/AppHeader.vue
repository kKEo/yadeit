<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import LocaleSwitcher from '@/components/app/LocaleSwitcher.vue'
import UserMenu from '@/components/app/UserMenu.vue'
import WorkspaceSwitcher from '@/components/app/WorkspaceSwitcher.vue'
import { Search, SquareKanban } from '@lucide/vue'
import { useInboxUnreadCount } from '@/queries/inbox'
import { useUiStore } from '@/stores/ui'
import Kbd from '@/components/ui/Kbd.vue'

/** Shell header of a workspace: switcher, section navigation, language and account. */
const props = defineProps<{ slug: string }>()
const { t } = useI18n()

const ui = useUiStore()
const unread = useInboxUnreadCount()

const links = computed(() => [
  { name: 'items', label: t('nav.items'), badge: 0 },
  { name: 'inbox', label: t('nav.inbox'), badge: unread.data.value ?? 0 },
  { name: 'projects', label: t('nav.projects') },
  { name: 'docs', label: t('nav.docs') },
  { name: 'workspace-members', label: t('nav.members') },
  { name: 'agents', label: t('nav.agents') },
  { name: 'workspace-settings', label: t('nav.settings') },
])
</script>

<template>
  <header class="sticky top-0 z-40 border-b bg-background/95 backdrop-blur">
    <div class="mx-auto flex h-14 max-w-[100rem] items-center justify-between gap-4 px-4">
      <div class="flex min-w-0 items-center gap-3">
        <RouterLink :to="{ name: 'home' }" class="flex items-center gap-2" :aria-label="t('app.name')">
          <SquareKanban class="h-5 w-5" />
          <span class="hidden font-semibold tracking-tight sm:inline">{{ t('app.name') }}</span>
        </RouterLink>
        <WorkspaceSwitcher />
      </div>
      <div class="flex shrink-0 items-center gap-2">
        <button
          type="button" :aria-label="t('commands.title')"
          class="hidden h-9 items-center gap-2 rounded-md border border-input px-2.5 text-sm text-muted-foreground hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring md:inline-flex"
          @click="ui.paletteOpen = true"
        ><Search class="h-4 w-4" /><span>{{ t('commands.open') }}</span><Kbd keys="mod+k" /></button>
        <LocaleSwitcher />
        <UserMenu />
      </div>
    </div>
    <nav class="mx-auto flex max-w-[100rem] gap-1 overflow-x-auto px-4" :aria-label="t('nav.label')">
      <RouterLink
        v-for="link in links" :key="link.name"
        :to="{ name: link.name, params: { slug: props.slug } }"
        class="-mb-px inline-flex items-center gap-1.5 whitespace-nowrap border-b-2 border-transparent px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
        active-class="!border-primary !text-foreground"
      >
        {{ link.label }}
        <span
          v-if="link.badge" data-testid="inbox-badge"
          class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-[11px] font-semibold leading-none text-primary-foreground"
        >{{ link.badge > 99 ? '99+' : link.badge }}<span class="sr-only"> {{ t('inbox.unreadSuffix') }}</span></span>
      </RouterLink>
    </nav>
  </header>
</template>
