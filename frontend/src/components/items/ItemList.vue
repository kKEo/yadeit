<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { ChevronDown, ChevronRight } from '@lucide/vue'
import type { ItemGroup } from '@/lib/grouping'
import GroupLabel from './GroupLabel.vue'
import ItemRow from './ItemRow.vue'

/** Rows in collapsible sections, one per group. */
defineProps<{ groups: ItemGroup[]; collapsed: ReadonlySet<string>; focusedKey: string | null; openKey: string | null }>()
const emit = defineEmits<{ open: [key: string]; focus: [key: string]; toggle: [groupId: string] }>()
const { t } = useI18n()
</script>

<template>
  <div class="overflow-hidden rounded-lg border bg-card">
    <section v-for="group in groups" :key="group.id" :aria-label="t('items.group.section')">
      <h2 v-if="group.by !== 'none'" class="border-b bg-muted/50 last:border-b-0">
        <button
          type="button" :aria-expanded="!collapsed.has(group.id)"
          class="flex h-9 w-full items-center gap-2 px-3 text-left text-sm font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
          @click="emit('toggle', group.id)"
        >
          <component :is="collapsed.has(group.id) ? ChevronRight : ChevronDown" class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
          <GroupLabel :group="group" />
          <span class="text-xs font-normal text-muted-foreground">{{ group.items.length }}</span>
        </button>
      </h2>
      <ul v-show="!collapsed.has(group.id)">
        <li v-for="item in group.items" :key="item.key">
          <ItemRow
            :item="item" :focused="item.key === focusedKey" :selected="item.key === openKey"
            @open="emit('open', item.key)" @focus="emit('focus', item.key)"
          />
        </li>
      </ul>
    </section>
  </div>
</template>
