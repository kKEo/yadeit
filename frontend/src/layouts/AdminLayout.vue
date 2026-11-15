<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ShieldCheck } from '@lucide/vue'
import { getStatus } from '@/api/simulator'
import LocaleSwitcher from '@/components/app/LocaleSwitcher.vue'
import ProfileDialog from '@/components/app/ProfileDialog.vue'
import UserMenu from '@/components/app/UserMenu.vue'

/** Shell of the operator area: the people who run the installation. */
const { t } = useI18n()

const base = [
  { name: 'admin', label: 'admin.nav.workspaces' },
  { name: 'admin-users', label: 'admin.nav.users' },
  { name: 'admin-mail', label: 'admin.nav.mail' },
  { name: 'admin-broadcasts', label: 'admin.nav.broadcasts' },
  { name: 'admin-audit', label: 'admin.nav.audit' },
]
// The Traffic lab has a tab only where it is switched on.
const simulator = ref(false)
// A run's page belongs to the Traffic lab tab as well.
const route = useRoute()
const insideSim = computed(() => String(route.name ?? '').startsWith('admin-sim'))
const links = computed(() => (simulator.value ? [...base, { name: 'admin-sim', label: 'admin.nav.sim' }] : base))
onMounted(async () => { simulator.value = (await getStatus()) !== null })
</script>

<template>
  <div class="min-h-screen bg-background">
    <header class="sticky top-0 z-40 border-b bg-background/95 backdrop-blur">
      <div class="mx-auto flex h-14 max-w-[100rem] items-center justify-between gap-4 px-4">
        <div class="flex min-w-0 items-center gap-3">
          <ShieldCheck class="h-5 w-5" />
          <span class="font-semibold tracking-tight">{{ t('admin.title') }}</span>
          <RouterLink :to="{ name: 'home' }" class="text-sm text-muted-foreground underline-offset-4 hover:underline">
            {{ t('admin.back') }}
          </RouterLink>
        </div>
        <div class="flex shrink-0 items-center gap-2">
          <LocaleSwitcher />
          <UserMenu />
        </div>
      </div>
      <nav class="mx-auto flex max-w-[100rem] gap-1 overflow-x-auto px-4" :aria-label="t('admin.title')">
        <RouterLink
          v-for="link in links" :key="link.name" :to="{ name: link.name }"
          class="-mb-px inline-flex items-center whitespace-nowrap border-b-2 border-transparent px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
exact-active-class="!border-primary !text-foreground"
          :class="link.name === 'admin-sim' && insideSim ? '!border-primary !text-foreground' : ''"
        >{{ t(link.label) }}</RouterLink>
      </nav>
    </header>
    <main class="mx-auto max-w-[100rem] space-y-4 px-4 py-6">
      <RouterView />
    </main>
    <ProfileDialog />
  </div>
</template>
