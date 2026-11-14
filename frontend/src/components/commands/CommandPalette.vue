<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { refDebounced } from '@vueuse/core'
import { FileText, MessageSquare, Search } from '@lucide/vue'
import { useCommands, type Command } from '@/composables/useCommands'
import { useItemPeek } from '@/composables/useItemPeek'
import { useRouter } from 'vue-router'
import type { SearchResult } from '@/api/types'
import { useSearch } from '@/queries/items'
import { useSlug } from '@/queries/keys'
import { useUiStore } from '@/stores/ui'
import Dialog from '@/components/ui/Dialog.vue'
import Kbd from '@/components/ui/Kbd.vue'
import StateDot from '@/components/items/StateDot.vue'

/** Cmd/Ctrl+K: run a command or jump to an item by typing part of its title or its key. */
const { t } = useI18n()
const ui = useUiStore()
const { commands } = useCommands()
const { open: openItem } = useItemPeek()

const query = ref('')
const debounced = refDebounced(query, 200)
const search = useSearch(() => (ui.paletteOpen ? debounced.value : ''))
const router = useRouter()
const slug = useSlug()
const active = ref(0)
const list = ref<HTMLElement | null>(null)

interface Row { id: string; group: string; command?: Command; hit?: SearchResult; run: () => void }

function hitRow(hit: SearchResult, index: number): Row {
  if (hit.type === 'page') {
    return { id: `page-${hit.id}`, group: t('commands.group.pages'), hit, run: () => void router.push({ name: 'doc', params: { slug: slug.value, id: hit.id } }) }
  }
  const group = hit.type === 'item' ? t('commands.group.items') : t('commands.group.comments')
  return { id: `${hit.type}-${hit.key}-${index}`, group, hit, run: () => void openItem(hit.key) }
}

const rows = computed<Row[]>(() => {
  const term = query.value.trim().toLowerCase()
  const matching = term ? commands.value.filter((c) => c.label.toLowerCase().includes(term)) : commands.value
  const result: Row[] = matching.map((command) => ({ id: command.id, group: t(command.group), command, run: command.run }))
  if (term && debounced.value.trim().toLowerCase() === term) {
    result.push(...(search.data.value ?? []).map(hitRow))
  }
  return result
})

watch(rows, (value) => { active.value = Math.min(active.value, Math.max(value.length - 1, 0)) })
watch(query, () => { active.value = 0 })
watch(() => ui.paletteOpen, (open) => { if (open) { query.value = ''; active.value = 0 } })

function run(row: Row | undefined) {
  if (!row) return
  ui.paletteOpen = false
  row.run()
}

function move(delta: number) {
  if (rows.value.length === 0) return
  active.value = (active.value + delta + rows.value.length) % rows.value.length
  void nextTick(() => list.value?.querySelector('[data-active="true"]')?.scrollIntoView?.({ block: 'nearest' }))
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'ArrowDown') { event.preventDefault(); move(1) }
  else if (event.key === 'ArrowUp') { event.preventDefault(); move(-1) }
  else if (event.key === 'Enter') { event.preventDefault(); run(rows.value[active.value]) }
}
</script>

<template>
  <Dialog v-model:open="ui.paletteOpen" :title="t('commands.title')" :close-label="t('common.close')" class="top-[20%] max-w-xl translate-y-0 gap-0 p-0 [&>div:first-child]:sr-only [&>button]:hidden">
    <div class="flex items-center gap-2 border-b px-3">
      <Search class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
      <input
        v-model="query" type="text" role="combobox" autocomplete="off" autofocus aria-expanded="true" aria-controls="command-list"
        :aria-activedescendant="rows[active] ? `command-${rows[active].id}` : undefined"
        :aria-label="t('commands.title')" :placeholder="t('commands.placeholder')"
        class="h-12 w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground" @keydown="onKeydown"
      />
    </div>
    <ul id="command-list" ref="list" role="listbox" :aria-label="t('commands.title')" class="max-h-80 overflow-y-auto p-1">
      <template v-for="(row, index) in rows" :key="row.id">
        <li v-if="index === 0 || rows[index - 1].group !== row.group" role="presentation" class="px-2 pb-1 pt-2 text-xs font-medium text-muted-foreground">
          {{ row.group }}
        </li>
        <li
          :id="`command-${row.id}`" role="option" :aria-selected="index === active" :data-active="index === active"
          class="flex cursor-default select-none items-center gap-2 rounded-sm px-2 py-1.5 text-sm data-[active=true]:bg-accent data-[active=true]:text-accent-foreground"
          @mousemove="active = index" @click="run(row)"
        >
          <template v-if="row.hit">
            <StateDot v-if="row.hit.type === 'item'" :state="row.hit.state" />
            <component :is="row.hit.type === 'page' ? FileText : MessageSquare" v-else class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            <span v-if="row.hit.type !== 'page'" class="shrink-0 font-mono text-xs text-muted-foreground">{{ row.hit.key }}</span>
            <span class="min-w-0 flex-1">
              <span class="block truncate">{{ row.hit.title }}</span>
              <span v-if="row.hit.snippet" class="block truncate text-xs text-muted-foreground">{{ row.hit.snippet }}</span>
            </span>
          </template>
          <template v-else-if="row.command">
            <component :is="row.command.icon" v-if="row.command.icon" class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            <span class="min-w-0 flex-1 truncate">{{ row.command.label }}</span>
            <Kbd v-if="row.command.shortcut" :keys="row.command.shortcut" />
          </template>
        </li>
      </template>
      <li v-if="rows.length === 0" class="px-2 py-6 text-center text-sm text-muted-foreground">
        {{ search.asyncStatus.value === 'loading' ? t('commands.searching') : t('commands.empty') }}
      </li>
    </ul>
  </Dialog>
</template>
