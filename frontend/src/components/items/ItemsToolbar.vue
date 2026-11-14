<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowUpDown, Check, Columns3, Layers, LayoutList } from '@lucide/vue'
import { GROUPINGS, SORT_FIELDS, type GroupBy, type ViewLayout, type ViewState } from '@/api/types'
import Button from '@/components/ui/Button.vue'
import DropdownMenu from '@/components/ui/DropdownMenu.vue'
import DropdownMenuItem from '@/components/ui/DropdownMenuItem.vue'
import DropdownMenuLabel from '@/components/ui/DropdownMenuLabel.vue'
import DropdownMenuSeparator from '@/components/ui/DropdownMenuSeparator.vue'

/** Layout toggle, group-by menu and sort menu. */
const props = defineProps<{ state: ViewState }>()
const emit = defineEmits<{ change: [partial: Partial<ViewState>] }>()
const { t } = useI18n()

const sortField = computed(() => props.state.sort.replace(/^-/, ''))
const descending = computed(() => props.state.sort.startsWith('-'))

const layouts: { value: ViewLayout; icon: typeof LayoutList }[] = [
  { value: 'list', icon: LayoutList },
  { value: 'board', icon: Columns3 },
]

function setGroup(group_by: GroupBy) {
  emit('change', { group_by })
}

function setSort(field: string, desc: boolean) {
  emit('change', { sort: `${desc ? '-' : ''}${field}` })
}
</script>

<template>
  <div class="flex items-center gap-2">
    <div class="inline-flex rounded-md border p-0.5" role="group" :aria-label="t('items.toolbar.layout')">
      <button
        v-for="layout in layouts" :key="layout.value" type="button"
        :aria-pressed="state.layout === layout.value" :aria-label="t(`items.layout.${layout.value}`)" :title="t(`items.layout.${layout.value}`)"
        class="inline-flex h-7 w-8 items-center justify-center rounded-sm text-muted-foreground outline-none
               hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring aria-pressed:bg-secondary aria-pressed:text-foreground"
        @click="emit('change', { layout: layout.value })"
      ><component :is="layout.icon" class="h-4 w-4" /></button>
    </div>

    <DropdownMenu align="end">
      <template #trigger>
        <Button variant="outline" size="sm" class="gap-2" :aria-label="t('items.toolbar.groupBy', { group: t(`items.group.by.${state.group_by}`) })">
          <Layers class="h-4 w-4" /><span class="hidden sm:inline">{{ t(`items.group.by.${state.group_by}`) }}</span>
        </Button>
      </template>
      <DropdownMenuLabel>{{ t('items.toolbar.groupLabel') }}</DropdownMenuLabel>
      <DropdownMenuItem v-for="group in GROUPINGS" :key="group" @select="setGroup(group)">
        <Check class="h-4 w-4" :class="state.group_by === group ? 'opacity-100' : 'opacity-0'" />{{ t(`items.group.by.${group}`) }}
      </DropdownMenuItem>
    </DropdownMenu>

    <DropdownMenu align="end">
      <template #trigger>
        <Button variant="outline" size="sm" class="gap-2" :aria-label="t('items.toolbar.sortBy', { field: t(`items.sort.${sortField}`) })">
          <ArrowUpDown class="h-4 w-4" /><span class="hidden sm:inline">{{ t(`items.sort.${sortField}`) }}</span>
        </Button>
      </template>
      <DropdownMenuLabel>{{ t('items.toolbar.sortLabel') }}</DropdownMenuLabel>
      <DropdownMenuItem v-for="field in SORT_FIELDS" :key="field" @select="setSort(field, descending)">
        <Check class="h-4 w-4" :class="sortField === field ? 'opacity-100' : 'opacity-0'" />{{ t(`items.sort.${field}`) }}
      </DropdownMenuItem>
      <DropdownMenuSeparator />
      <DropdownMenuItem @select="setSort(sortField, false)">
        <Check class="h-4 w-4" :class="!descending ? 'opacity-100' : 'opacity-0'" />{{ t('items.sort.ascending') }}
      </DropdownMenuItem>
      <DropdownMenuItem @select="setSort(sortField, true)">
        <Check class="h-4 w-4" :class="descending ? 'opacity-100' : 'opacity-0'" />{{ t('items.sort.descending') }}
      </DropdownMenuItem>
    </DropdownMenu>
  </div>
</template>
