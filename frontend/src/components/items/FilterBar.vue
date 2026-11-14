<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ListFilter, Search, X } from '@lucide/vue'
import type { FilterName, ItemFilters, Member, Project, ProjectDetail } from '@/api/types'
import { kindOptions, priorityOptions, stateGroupOptions, type PickerOption } from '@/lib/pickerOptions'
import { filterChips } from '@/lib/viewState'
import PropertyPicker from '@/components/app/PropertyPicker.vue'
import Button from '@/components/ui/Button.vue'
import DropdownMenu from '@/components/ui/DropdownMenu.vue'
import DropdownMenuItem from '@/components/ui/DropdownMenuItem.vue'
import Switch from '@/components/ui/Switch.vue'

/** Search box, one removable chip per active filter, and the menu that adds filters. */
interface Props {
  filters: ItemFilters
  projects: Project[]
  /** The selected project, when exactly one is filtered: its states and labels become filterable. */
  project: ProjectDetail | null
  members: Member[]
  /** Label names seen on loaded rows; used when no single project is selected. */
  rowLabels: string[]
}
const props = defineProps<Props>()
const emit = defineEmits<{ set: [name: FilterName, value: string | null]; clear: [] }>()
const { t } = useI18n()

type Dimension = Exclude<FilterName, 'q' | 'open'>
const DIMENSIONS: Dimension[] = ['project', 'state_group', 'state', 'priority', 'kind', 'assignee', 'label']

const options = computed<Record<Dimension, PickerOption[]>>(() => ({
  project: props.projects.map((p) => ({ value: p.key, label: p.name, hint: p.key })),
  state_group: stateGroupOptions(t),
  state: (props.project?.states ?? []).map((s) => ({ value: s.name, label: s.name, color: s.color })),
  priority: priorityOptions(t),
  kind: kindOptions(t),
  assignee: [
    { value: 'me', label: t('filters.me') },
    { value: 'none', label: t('items.unassigned') },
    ...props.members.map((m) => ({ value: String(m.id), label: m.name })),
  ],
  label: (props.project ? props.project.labels.map((l) => l.name) : props.rowLabels).map((name) => ({ value: name, label: name })),
}))

/** A state filter only makes sense inside one project. */
const available = computed(() => DIMENSIONS.filter((d) => d !== 'state' || props.project !== null))

const chips = computed(() => filterChips(props.filters).map((chip) => {
  const name = t(`filters.name.${chip.name}`)
  if (chip.name === 'open') return { ...chip, text: name }
  const label = options.value[chip.name as Dimension]?.find((o) => o.value === chip.value)?.label ?? chip.value
  return { ...chip, text: `${name}: ${label}` }
}))

const editing = ref<Dimension | null>(null)
const pickerOpen = computed({
  get: () => editing.value !== null,
  set: (open) => { if (!open) editing.value = null },
})

function pick(value: string | string[]) {
  if (editing.value) emit('set', editing.value, Array.isArray(value) ? value[0] ?? null : value)
}

// ── Search text: typed freely, applied after a short pause ──────────────────
const search = ref(props.filters.q ?? '')
const searchInput = ref<HTMLInputElement | null>(null)
let timer: ReturnType<typeof setTimeout> | null = null

watch(() => props.filters.q, (q) => {
  if ((q ?? '') !== search.value.trim()) search.value = q ?? ''
})

function onSearch(event: Event) {
  search.value = (event.target as HTMLInputElement).value
  if (timer) clearTimeout(timer)
  timer = setTimeout(() => emit('set', 'q', search.value.trim() || null), 250)
}

function clearSearch() {
  search.value = ''
  if (timer) clearTimeout(timer)
  emit('set', 'q', null)
}

defineExpose({ focusSearch: () => searchInput.value?.focus() })
</script>

<template>
  <div class="flex flex-wrap items-center gap-2">
    <div class="relative">
      <Search class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
      <input
        ref="searchInput" type="search" :value="search" :placeholder="t('filters.searchPlaceholder')" :aria-label="t('filters.search')"
        class="h-9 w-52 rounded-md border border-input bg-background pl-8 pr-2 text-sm ring-offset-background placeholder:text-muted-foreground
               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
        @input="onSearch" @keydown.esc.prevent="search ? clearSearch() : searchInput?.blur()"
      />
    </div>

    <DropdownMenu>
      <template #trigger>
        <Button variant="outline" size="sm" class="gap-2"><ListFilter class="h-4 w-4" />{{ t('filters.add') }}</Button>
      </template>
      <DropdownMenuItem v-for="dimension in available" :key="dimension" @select="editing = dimension">
        {{ t(`filters.name.${dimension}`) }}
      </DropdownMenuItem>
    </DropdownMenu>

    <label class="flex items-center gap-2 text-sm">
      <Switch
        :model-value="filters.open === '1'" :aria-label="t('filters.openOnly')"
        @update:model-value="emit('set', 'open', $event ? '1' : null)"
      />
      <span aria-hidden="true">{{ t('filters.openOnly') }}</span>
    </label>

    <ul v-if="chips.length" class="flex flex-wrap items-center gap-1.5" :aria-label="t('filters.active')">
      <li
        v-for="chip in chips" :key="chip.name" :data-filter="chip.name"
        class="inline-flex h-7 items-center rounded-full border bg-secondary text-xs text-secondary-foreground"
      >
        <button
          v-if="chip.name !== 'open'" type="button"
          class="h-full rounded-l-full pl-2.5 pr-1 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          @click="editing = chip.name as Dimension"
        >{{ chip.text }}</button>
        <span v-else class="pl-2.5 pr-1">{{ chip.text }}</span>
        <button
          type="button" :aria-label="t('filters.remove', { filter: chip.text })"
          class="mr-1 rounded-full p-0.5 hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          @click="emit('set', chip.name, null)"
        ><X class="h-3 w-3" /></button>
      </li>
      <li>
        <Button variant="ghost" size="sm" class="h-7 px-2 text-xs" @click="clearSearch(); emit('clear')">{{ t('filters.clearAll') }}</Button>
      </li>
    </ul>

    <PropertyPicker
      v-if="editing" v-model:open="pickerOpen" mode="dialog"
      :label="t(`filters.name.${editing}`)" :options="options[editing]" :model-value="filters[editing] ?? null"
      @update:model-value="pick"
    />
  </div>
</template>
