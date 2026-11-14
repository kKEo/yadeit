<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useWorkItemsStore } from '@/stores/workItems'
import { WORK_ITEM_STATES, type WorkItemState } from '@/api/types'
import { formatDateTime } from '@/lib/format'
import StatusBadge from '@/components/app/StatusBadge.vue'
import Alert from '@/components/ui/Alert.vue'
import Button from '@/components/ui/Button.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import Table from '@/components/ui/Table.vue'
import TableHeader from '@/components/ui/TableHeader.vue'
import TableBody from '@/components/ui/TableBody.vue'
import TableRow from '@/components/ui/TableRow.vue'
import TableHead from '@/components/ui/TableHead.vue'
import TableCell from '@/components/ui/TableCell.vue'
import { Plus, RefreshCw } from '@lucide/vue'

type StateFilter = 'all' | WorkItemState

const REFRESH_MS = 10_000
const COLUMNS = 6

const store = useWorkItemsStore()
const router = useRouter()
const { t, locale } = useI18n()

const stateFilter = ref<StateFilter>('all')
// Once extra pages are appended, auto-refresh pauses so it does not collapse the list.
const extraPages = ref(false)
const filters = computed<StateFilter[]>(() => ['all', ...WORK_ITEM_STATES])
const initialLoading = computed(() => store.loading && store.list.length === 0)

let timer: ReturnType<typeof setInterval> | null = null

function params() {
  return stateFilter.value === 'all' ? {} : { state: stateFilter.value }
}

async function load() {
  extraPages.value = false
  await store.fetchList(params())
}

async function loadMore() {
  if (!store.nextCursor) return
  extraPages.value = true
  await store.fetchList({ ...params(), cursor: store.nextCursor })
}

function setFilter(value: StateFilter) {
  if (stateFilter.value === value) return
  stateFilter.value = value
  store.list = []
  void load()
}

function open(key: string) {
  void router.push({ name: 'item-detail', params: { key } })
}

onMounted(() => {
  void load()
  timer = setInterval(() => {
    if (!extraPages.value && !document.hidden) void store.fetchList(params())
  }, REFRESH_MS)
})
onUnmounted(() => {
  if (timer) clearInterval(timer)
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold tracking-tight">{{ t('items.title') }}</h1>
        <p class="text-sm text-muted-foreground">{{ t('items.count', store.list.length) }}</p>
      </div>
      <div class="flex items-center gap-2">
        <Button variant="outline" size="sm" :disabled="store.loading" @click="load">
          <RefreshCw class="mr-2 h-4 w-4" :class="{ 'animate-spin': store.loading }" />{{ t('common.refresh') }}
        </Button>
        <Button size="sm" @click="router.push({ name: 'create-item' })">
          <Plus class="mr-2 h-4 w-4" />{{ t('items.new') }}
        </Button>
      </div>
    </div>

    <div class="flex flex-wrap gap-1.5">
      <button
        v-for="f in filters" :key="f" type="button"
        class="rounded-full border px-3 py-1 text-sm font-medium transition-colors"
        :class="stateFilter === f
          ? 'border-primary bg-primary text-primary-foreground'
          : 'bg-background text-muted-foreground hover:bg-accent hover:text-accent-foreground'"
        :aria-pressed="stateFilter === f"
        @click="setFilter(f)"
      >{{ f === 'all' ? t('items.filterAll') : t(`state.${f}`) }}</button>
    </div>

    <Alert v-if="store.error" variant="destructive" :title="store.error" />

    <div class="rounded-md border">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead class="w-28">{{ t('items.fields.key') }}</TableHead>
            <TableHead>{{ t('items.fields.title') }}</TableHead>
            <TableHead>{{ t('items.fields.state') }}</TableHead>
            <TableHead>{{ t('items.fields.priority') }}</TableHead>
            <TableHead>{{ t('items.fields.assignee') }}</TableHead>
            <TableHead>{{ t('items.fields.updatedAt') }}</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <template v-if="initialLoading">
            <TableRow v-for="i in 5" :key="i">
              <TableCell v-for="c in COLUMNS" :key="c"><Skeleton class="w-full max-w-[10rem]" /></TableCell>
            </TableRow>
          </template>
          <TableRow v-else-if="store.list.length === 0">
            <TableCell :colspan="COLUMNS" class="py-10 text-center text-muted-foreground">
              {{ t('items.empty') }}
              <RouterLink :to="{ name: 'create-item' }" class="ml-1 font-medium text-primary underline-offset-4 hover:underline">
                {{ t('items.emptyCta') }}
              </RouterLink>
            </TableCell>
          </TableRow>
          <template v-else>
            <TableRow
              v-for="item in store.list" :key="item.key"
              class="cursor-pointer" tabindex="0"
              @click="open(item.key)" @keydown.enter="open(item.key)"
            >
              <TableCell class="whitespace-nowrap font-mono text-xs">{{ item.key }}</TableCell>
              <TableCell class="font-medium">{{ item.title }}</TableCell>
              <TableCell><StatusBadge :state="item.state" /></TableCell>
              <TableCell>{{ t(`priority.${item.priority}`) }}</TableCell>
              <TableCell :class="item.assignee ? '' : 'text-muted-foreground'">
                {{ item.assignee?.name ?? t('items.unassigned') }}
              </TableCell>
              <TableCell class="whitespace-nowrap text-muted-foreground">
                {{ formatDateTime(item.updated_at, locale) }}
              </TableCell>
            </TableRow>
          </template>
        </TableBody>
      </Table>
    </div>

    <div v-if="store.nextCursor" class="flex justify-center">
      <Button variant="outline" size="sm" :disabled="store.loading" @click="loadMore">
        {{ t('items.loadMore') }}
      </Button>
    </div>
  </div>
</template>
