<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { getPublicPage } from '@/api/pages'
import type { PublicPage } from '@/api/types'
import { formatDateTime } from '@/lib/format'
import MarkdownView from '@/components/items/MarkdownView.vue'
import Skeleton from '@/components/ui/Skeleton.vue'

/** A page shared by link, for readers who are not signed in. Shows the page and nothing else of the workspace. */
const route = useRoute()
const { t, locale } = useI18n()
const loading = ref(true)
const page = ref<PublicPage | null>(null)

onMounted(async () => {
  try {
    page.value = await getPublicPage(String(route.params.token))
  } catch {
    page.value = null
  }
  loading.value = false
})
</script>

<template>
  <main class="mx-auto max-w-3xl px-4 py-10">
    <div v-if="loading" class="space-y-3" aria-busy="true"><Skeleton class="h-9 w-2/3" /><Skeleton class="h-64 w-full" /></div>
    <p v-else-if="!page" class="text-muted-foreground">{{ t('docs.publicNotFound') }}</p>
    <article v-else class="space-y-6">
      <header class="space-y-1">
        <h1 class="text-3xl font-semibold tracking-tight">{{ page.title }}</h1>
        <p class="text-xs text-muted-foreground">{{ formatDateTime(page.updated_at, locale) }}</p>
      </header>
      <MarkdownView v-if="page.body" :source="page.body" />
      <footer class="border-t pt-4 text-xs text-muted-foreground">{{ t('docs.publicFooter') }}</footer>
    </article>
  </main>
</template>
