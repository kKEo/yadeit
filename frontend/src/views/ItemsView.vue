<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ListTodo, Plus, SearchX } from '@lucide/vue'
import type { ViewState } from '@/api/types'
import { useBoardMove } from '@/composables/useBoardMove'
import { useCanEdit } from '@/composables/useCanEdit'
import { useItemPeek } from '@/composables/useItemPeek'
import { useShortcuts } from '@/composables/useShortcuts'
import { useViewState } from '@/composables/useViewState'
import { flattenGroups, groupItems } from '@/lib/grouping'
import { listParams } from '@/lib/viewState'
import { useItemList } from '@/queries/items'
import { useSlug } from '@/queries/keys'
import { useMembers, useProject, useProjects } from '@/queries/workspace'
import { useUiStore } from '@/stores/ui'
import EmptyState from '@/components/app/EmptyState.vue'
import FilterBar from '@/components/items/FilterBar.vue'
import ItemBoard from '@/components/items/ItemBoard.vue'
import ItemList from '@/components/items/ItemList.vue'
import ItemsToolbar from '@/components/items/ItemsToolbar.vue'
import ViewsMenu from '@/components/items/ViewsMenu.vue'
import Alert from '@/components/ui/Alert.vue'
import Button from '@/components/ui/Button.vue'
import Kbd from '@/components/ui/Kbd.vue'
import Skeleton from '@/components/ui/Skeleton.vue'

/** The items page: one view state (in the URL), shown as a grouped list or a board. */
const { t } = useI18n()
const router = useRouter()
const slug = useSlug()
const ui = useUiStore()
const canEdit = useCanEdit()
const { state, viewId, apply, patch, setFilter, clearFilters } = useViewState()
const { peekKey, open } = useItemPeek()
const { move } = useBoardMove()

const list = useItemList(() => listParams(state.value))
const projects = useProjects()
const members = useMembers()
const project = useProject(() => state.value.filters.project)

const selectedProject = computed(() => (
  state.value.filters.project && project.data.value?.key === state.value.filters.project ? project.data.value : null
))

const groups = computed(() => groupItems(list.items.value, state.value.group_by, {
  projectStates: selectedProject.value?.states,
  members: members.data.value,
  includeEmpty: state.value.layout === 'board',
}))

const collapsed = ref(new Set<string>())
function toggleGroup(id: string) {
  const next = new Set(collapsed.value)
  if (!next.delete(id)) next.add(id)
  collapsed.value = next
}

/** Rows in display order: what J/K walks through. */
const ordered = computed(() => flattenGroups(groups.value, state.value.layout === 'list' ? collapsed.value : undefined))
const rowLabels = computed(() => [...new Set(list.items.value.flatMap((item) => item.labels.map((label) => label.name)))].sort())
const hasFilters = computed(() => Object.keys(state.value.filters).length > 0)
const noProjects = computed(() => projects.status.value === 'success' && (projects.data.value?.length ?? 0) === 0)

function moveFocus(delta: number) {
  const rows = ordered.value
  if (rows.length === 0) return
  const index = rows.findIndex((item) => item.key === ui.focusedKey)
  const next = index === -1 ? (delta > 0 ? 0 : rows.length - 1) : Math.min(Math.max(index + delta, 0), rows.length - 1)
  ui.focusedKey = rows[next].key
}

watch(() => ui.focusedKey, (key) => {
  if (!key) return
  void nextTick(() => document.querySelector(`[data-item-key="${CSS.escape(key)}"]`)?.scrollIntoView?.({ block: 'nearest' }))
})

// With the panel open, J/K step through items without closing it.
watch(() => ui.focusedKey, (key) => {
  if (key && peekKey.value && key !== peekKey.value) void router.replace({ query: { ...router.currentRoute.value.query, item: key } })
})

function openItem(key: string) {
  ui.focusedKey = key
  void open(key)
}

const filterBar = ref<InstanceType<typeof FilterBar> | null>(null)
const openFocused = () => { if (ui.focusedKey) openItem(ui.focusedKey) }
const hasFocus = () => ui.focusedKey !== null

useShortcuts([
  { keys: '/', group: 'shortcuts.group.list', description: 'shortcuts.search', handler: () => filterBar.value?.focusSearch() },
  { keys: 'j', group: 'shortcuts.group.list', description: 'shortcuts.next', handler: () => moveFocus(1) },
  { keys: 'k', group: 'shortcuts.group.list', description: 'shortcuts.previous', handler: () => moveFocus(-1) },
  { keys: 'arrowdown', group: 'shortcuts.group.list', description: 'shortcuts.next', hidden: true, handler: () => moveFocus(1) },
  { keys: 'arrowup', group: 'shortcuts.group.list', description: 'shortcuts.previous', hidden: true, handler: () => moveFocus(-1) },
  { keys: 'enter', group: 'shortcuts.group.list', description: 'shortcuts.open', when: hasFocus, handler: openFocused },
  { keys: 'space', group: 'shortcuts.group.list', description: 'shortcuts.open', hidden: true, when: hasFocus, handler: openFocused },
])

function applyView(next: ViewState, view: string | null) {
  void apply(next, { view })
}
</script>

<template>
  <div class="space-y-3" :class="peekKey ? 'xl:pr-[36rem]' : ''">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="flex min-w-0 items-center gap-3">
        <h1 class="text-xl font-semibold tracking-tight">{{ t('items.title') }}</h1>
        <ViewsMenu :state="state" :view-id="viewId" @apply="applyView" />
      </div>
      <div class="flex items-center gap-2">
        <ItemsToolbar :state="state" @change="patch($event)" />
        <Button v-if="canEdit" size="sm" class="gap-2" @click="ui.openQuickCapture()">
          <Plus class="h-4 w-4" /><span class="hidden sm:inline">{{ t('items.new') }}</span><Kbd keys="c" class="hidden opacity-70 lg:inline-flex" />
        </Button>
      </div>
    </div>

    <FilterBar
      ref="filterBar" :filters="state.filters" :projects="projects.data.value ?? []" :project="selectedProject"
      :members="members.data.value ?? []" :row-labels="rowLabels" @set="setFilter" @clear="clearFilters"
    />

    <Alert v-if="list.error.value" variant="destructive" :title="list.error.value.message || t('errors.fetchList')" />

    <div v-if="list.status.value === 'pending'" class="space-y-2" aria-busy="true">
      <Skeleton v-for="i in 6" :key="i" class="h-10 w-full" />
    </div>

    <EmptyState
      v-else-if="list.items.value.length === 0 && noProjects" :icon="ListTodo"
      :title="t('items.empty.noProjectsTitle')" :description="t('items.empty.noProjectsDescription')"
      :action-label="t('items.empty.goToProjects')" shortcut="g p" @action="router.push({ name: 'projects', params: { slug } })"
    />
    <EmptyState
      v-else-if="list.items.value.length === 0 && hasFilters" :icon="SearchX"
      :title="t('items.empty.filteredTitle')" :description="t('items.empty.filteredDescription')"
      :action-label="t('filters.clearAll')" @action="clearFilters"
    />
    <EmptyState
      v-else-if="list.items.value.length === 0 && !list.error.value" :icon="ListTodo"
      :title="t('items.empty.title')" :description="t('items.empty.description')"
      :action-label="canEdit ? t('items.create.submit') : undefined" shortcut="c" @action="ui.openQuickCapture()"
    />

    <template v-else-if="list.items.value.length">
      <ItemBoard
        v-if="state.layout === 'board'" :groups="groups" :group-by="state.group_by" :focused-key="ui.focusedKey" :open-key="peekKey" :can-edit="canEdit"
        @open="openItem" @focus="ui.focusedKey = $event" @move="move"
      />
      <ItemList
        v-else :groups="groups" :collapsed="collapsed" :focused-key="ui.focusedKey" :open-key="peekKey"
        @open="openItem" @focus="ui.focusedKey = $event" @toggle="toggleGroup"
      />
      <div class="flex items-center justify-between gap-3 text-sm text-muted-foreground">
        <span>{{ t('items.count', list.items.value.length) }}</span>
        <Button v-if="list.hasNextPage.value" variant="outline" size="sm" :disabled="list.asyncStatus.value === 'loading'" @click="list.loadNextPage()">
          {{ t('items.loadMore') }}
        </Button>
      </div>
    </template>
  </div>
</template>
