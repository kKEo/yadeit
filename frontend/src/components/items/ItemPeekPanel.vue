<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useItemPeek } from '@/composables/useItemPeek'
import { useSlug } from '@/queries/keys'
import ItemDetail from './ItemDetail.vue'

/** The right-hand side panel for `?item=KEY`. Not modal: the page behind stays usable and in place. */
const router = useRouter()
const { t } = useI18n()
const slug = useSlug()
const { peekKey, open, close } = useItemPeek()

function expand() {
  if (peekKey.value) void router.push({ name: 'item-detail', params: { slug: slug.value, key: peekKey.value } })
}
</script>

<template>
  <Transition
    enter-active-class="transition duration-150 ease-out" enter-from-class="translate-x-full opacity-0"
    leave-active-class="transition duration-100 ease-in" leave-to-class="translate-x-full opacity-0"
  >
    <aside
      v-if="peekKey" :aria-label="t('items.detail.panel', { key: peekKey })"
      class="fixed bottom-0 right-0 top-14 z-30 flex w-full max-w-xl flex-col border-l bg-background shadow-xl"
    >
      <ItemDetail :key="peekKey" :item-key="peekKey" variant="peek" @close="close" @open="open" @expand="expand" @deleted="close" />
    </aside>
  </Transition>
</template>
