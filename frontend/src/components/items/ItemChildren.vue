<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { Plus } from '@lucide/vue'
import type { ItemDetail } from '@/api/types'
import { useUiStore } from '@/stores/ui'
import Button from '@/components/ui/Button.vue'
import Progress from '@/components/ui/Progress.vue'
import StateDot from './StateDot.vue'

defineProps<{ item: ItemDetail; canEdit: boolean }>()
const emit = defineEmits<{ open: [key: string] }>()
const { t } = useI18n()
const ui = useUiStore()
</script>

<template>
  <section class="space-y-2">
    <div class="flex items-center justify-between gap-2">
      <h3 class="flex items-center gap-2 text-sm font-medium">
        {{ t('items.children.title') }}
        <span v-if="item.sub_items" class="text-xs font-normal text-muted-foreground">{{ t('items.subItemsDone', item.sub_items) }}</span>
      </h3>
      <Button v-if="canEdit" variant="outline" size="sm" class="h-8 gap-1.5" @click="ui.openQuickCapture(item.key)">
        <Plus class="h-3.5 w-3.5" />{{ t('items.children.add') }}
      </Button>
    </div>
    <Progress v-if="item.sub_items" :model-value="Math.round((item.sub_items.done / item.sub_items.total) * 100)" class="h-1.5" />
    <ul v-if="item.children.length" class="divide-y rounded-md border">
      <li v-for="child in item.children" :key="child.key">
        <button
          type="button" class="flex h-9 w-full items-center gap-2 px-2 text-left text-sm hover:bg-accent/50 focus-visible:bg-accent focus-visible:outline-none"
          @click="emit('open', child.key)"
        >
          <StateDot :state="child.state" />
          <span class="shrink-0 font-mono text-xs text-muted-foreground">{{ child.key }}</span>
          <span class="truncate">{{ child.title }}</span>
        </button>
      </li>
    </ul>
  </section>
</template>
