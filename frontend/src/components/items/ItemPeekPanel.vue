<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useItemPeek } from '@/composables/useItemPeek'
import ItemDetail from './ItemDetail.vue'

/** The right-hand side panel for `?item=KEY`. Not modal: the page behind stays usable and in place. */
const { t } = useI18n()
const { peekKey, open, close, expand } = useItemPeek()

const panel = ref<HTMLElement | null>(null)

// Pickers, menus and dialogs opened from the panel are teleported outside it; they are not "outside".
const KEEP_OPEN = '[role="menu"],[role="listbox"],[role="dialog"],[role="alertdialog"],[data-radix-popper-content-wrapper],[data-sonner-toaster]'

function onPointerDown(e: PointerEvent) {
  if (!peekKey.value || !(e.target instanceof Element) || !e.target.isConnected) return
  if (panel.value?.contains(e.target) || e.target.closest(KEEP_OPEN)) return
  close()
}

onMounted(() => document.addEventListener('pointerdown', onPointerDown))
onBeforeUnmount(() => document.removeEventListener('pointerdown', onPointerDown))
</script>

<template>
  <Transition
    enter-active-class="transition duration-150 ease-out" enter-from-class="translate-x-full opacity-0"
    leave-active-class="transition duration-100 ease-in" leave-to-class="translate-x-full opacity-0"
  >
    <aside
      v-if="peekKey" ref="panel" :aria-label="t('items.detail.panel', { key: peekKey })"
      class="fixed bottom-0 right-0 top-[var(--app-header-height,3.5rem)] z-30 flex w-full max-w-xl flex-col border-l bg-background shadow-xl"
    >
      <ItemDetail :key="peekKey" :item-key="peekKey" variant="peek" @close="close" @open="open" @expand="expand" @deleted="close" />
    </aside>
  </Transition>
</template>
