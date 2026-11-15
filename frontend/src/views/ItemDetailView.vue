<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useItemPeek } from '@/composables/useItemPeek'
import ItemDetail from '@/components/items/ItemDetail.vue'

/** `/w/:slug/items/:key`: the side panel's content expanded to the full page, with an address of its own. */
const route = useRoute()
const router = useRouter()
const { open, collapse } = useItemPeek()

const itemKey = computed(() => String(route.params.key).toUpperCase())
const itemsRoute = computed(() => ({ name: 'items', params: { slug: route.params.slug } }))
</script>

<template>
  <!-- Capped so the text column stays readable on a wide window. -->
  <div class="mx-auto max-w-6xl">
    <ItemDetail :key="itemKey" :item-key="itemKey" variant="page" @open="open" @collapse="collapse" @deleted="router.push(itemsRoute)" />
  </div>
</template>
