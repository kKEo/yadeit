<script setup lang="ts">
import { computed, watch } from 'vue'
import { RouterLink, RouterView } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useWorkspaceStore } from '@/stores/workspace'
import AuthLayout from '@/layouts/AuthLayout.vue'
import AppHeader from '@/components/app/AppHeader.vue'
import VerifyEmailBanner from '@/components/app/VerifyEmailBanner.vue'
import Alert from '@/components/ui/Alert.vue'
import Card from '@/components/ui/Card.vue'
import CardHeader from '@/components/ui/CardHeader.vue'
import CardTitle from '@/components/ui/CardTitle.vue'
import CardDescription from '@/components/ui/CardDescription.vue'
import CardFooter from '@/components/ui/CardFooter.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import ProfileDialog from '@/components/app/ProfileDialog.vue'
import CommandPalette from '@/components/commands/CommandPalette.vue'
import ShortcutsHelpDialog from '@/components/commands/ShortcutsHelpDialog.vue'
import ItemPeekPanel from '@/components/items/ItemPeekPanel.vue'
import ItemPropertyShortcut from '@/components/items/ItemPropertyShortcut.vue'
import QuickCaptureDialog from '@/components/items/QuickCaptureDialog.vue'
import { useRealtime } from '@/composables/useRealtime'
import { useWorkspaceShortcuts } from '@/composables/useWorkspaceShortcuts'

const store = useWorkspaceStore()
const { t } = useI18n()

// Null for a moment while navigating away from the workspace; nothing must be loaded then.
const slug = computed(() => store.currentSlug)

watch(slug, (value) => {
  if (value) void store.load(value)
}, { immediate: true })

useWorkspaceShortcuts()
useRealtime()
</script>

<template>
  <AuthLayout v-if="store.notFound">
    <Card class="shadow-lg">
      <CardHeader>
        <CardTitle>{{ t('workspace.notFound.title') }}</CardTitle>
        <CardDescription>{{ t('workspace.notFound.description') }}</CardDescription>
      </CardHeader>
      <CardFooter>
        <RouterLink :to="{ name: 'home' }" class="text-sm font-medium text-primary underline-offset-4 hover:underline">
          {{ t('common.goHome') }}
        </RouterLink>
      </CardFooter>
    </Card>
  </AuthLayout>

  <div v-else-if="slug" class="min-h-screen bg-background">
    <AppHeader :slug="slug" />
    <VerifyEmailBanner />
    <main class="mx-auto max-w-[100rem] space-y-4 px-4 py-6">
      <Alert v-if="store.loadError" variant="destructive" :title="store.loadError" />
      <div v-if="!store.current && store.loading" class="space-y-3" aria-busy="true">
        <Skeleton class="h-8 w-1/3" />
        <Skeleton class="h-40 w-full" />
      </div>
      <!-- Keyed by slug so a view never shows another workspace's data after a switch. -->
      <RouterView v-else-if="store.current" :key="slug" />
    </main>
    <template v-if="store.current">
      <ItemPeekPanel :key="slug" />
      <QuickCaptureDialog />
      <ItemPropertyShortcut />
      <CommandPalette />
      <ShortcutsHelpDialog />
      <ProfileDialog />
    </template>
  </div>
</template>
