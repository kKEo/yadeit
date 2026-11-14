<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { Ban, ListTree, Megaphone } from '@lucide/vue'
import type { Item } from '@/api/types'

/** Small signs on a row or card: the item is blocked, and how far its sub-items are. */
defineProps<{ item: Pick<Item, 'blocked' | 'sub_items' | 'demand'> }>()
const { t } = useI18n()
</script>

<template>
  <span
    v-if="item.demand > 0" class="inline-flex shrink-0 items-center gap-1 text-xs tabular-nums text-muted-foreground"
    :title="t('items.demand', item.demand)" data-mark="demand"
  >
    <Megaphone class="h-3.5 w-3.5" aria-hidden="true" />{{ item.demand }}<span class="sr-only">{{ t('items.demand', item.demand) }}</span>
  </span>
  <span v-if="item.blocked" class="inline-flex shrink-0 items-center text-destructive" :title="t('items.blocked')" data-mark="blocked">
    <Ban class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">{{ t('items.blocked') }}</span>
  </span>
  <span
    v-if="item.sub_items" class="inline-flex shrink-0 items-center gap-1 text-xs tabular-nums text-muted-foreground"
    :title="t('items.subItemsDone', item.sub_items)" data-mark="sub-items"
  >
    <ListTree class="h-3.5 w-3.5" aria-hidden="true" />{{ item.sub_items.done }}/{{ item.sub_items.total }}
    <span class="sr-only">{{ t('items.subItemsDone', item.sub_items) }}</span>
  </span>
</template>
