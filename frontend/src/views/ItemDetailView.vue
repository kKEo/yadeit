<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ArrowLeft } from '@lucide/vue'
import ItemDetail from '@/components/items/ItemDetail.vue'

/** `/w/:slug/items/:key`: the same content as the side panel, full page. */
const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const itemKey = computed(() => String(route.params.key).toUpperCase())
const itemsRoute = computed(() => ({ name: 'items', params: { slug: route.params.slug } }))

function open(key: string) {
  void router.push({ name: 'item-detail', params: { slug: route.params.slug, key } })
}
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-3">
    <RouterLink
      :to="itemsRoute"
      class="inline-flex items-center gap-1.5 rounded text-sm text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    ><ArrowLeft class="h-4 w-4" />{{ t('items.title') }}</RouterLink>
    <div class="flex flex-col rounded-lg border bg-card">
      <ItemDetail :key="itemKey" :item-key="itemKey" variant="page" @open="open" @deleted="router.push(itemsRoute)" />
    </div>
  </div>
</template>
