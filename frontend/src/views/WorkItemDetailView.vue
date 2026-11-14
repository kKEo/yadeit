<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useWorkItemsStore } from '@/stores/workItems'
import {
  WORK_ITEM_PRIORITIES, WORK_ITEM_STATES,
  type WorkItemPriority, type WorkItemState,
} from '@/api/types'
import { formatDateTime } from '@/lib/format'
import ConfirmDialog from '@/components/app/ConfirmDialog.vue'
import StatusBadge from '@/components/app/StatusBadge.vue'
import Alert from '@/components/ui/Alert.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import CardHeader from '@/components/ui/CardHeader.vue'
import CardTitle from '@/components/ui/CardTitle.vue'
import CardContent from '@/components/ui/CardContent.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import Select from '@/components/ui/Select.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import Textarea from '@/components/ui/Textarea.vue'
import { ArrowLeft, CircleDot, Clock, Flag, RefreshCw, Trash2, User } from '@lucide/vue'

type Tab = 'description' | 'edit'

const POLL_MS = 15_000

const store = useWorkItemsStore()
const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()

const key = computed(() => String(route.params.key))
const item = computed(() => (store.current?.key === key.value ? store.current : null))
const isTerminal = computed(() => item.value?.state === 'completed' || item.value?.state === 'cancelled')

const activeTab = ref<Tab>('description')
const tabs = computed<{ value: Tab; label: string }[]>(() => [
  { value: 'description', label: t('items.detail.tabDescription') },
  { value: 'edit', label: t('items.detail.tabEdit') },
])

const stateOptions = computed(() => WORK_ITEM_STATES.map((value) => ({ value, label: t(`state.${value}`) })))
const priorityOptions = computed(() =>
  WORK_ITEM_PRIORITIES.map((value) => ({ value, label: t(`priority.${value}`) })),
)

// ── Edit form ────────────────────────────────────────────────────────────────
const editTitle = ref('')
const editDescription = ref('')
const editPriority = ref<WorkItemPriority>('none')
const saving = ref(false)
const saved = ref(false)
const editValid = computed(() => editTitle.value.trim().length > 0)

function seedEditForm() {
  if (!item.value) return
  editTitle.value = item.value.title
  editDescription.value = item.value.description ?? ''
  editPriority.value = item.value.priority
}

function switchTab(tab: Tab) {
  saved.value = false
  if (tab === 'edit') {
    store.clearError()
    seedEditForm()
  }
  activeTab.value = tab
}

async function save() {
  if (!editValid.value || saving.value) return
  saving.value = true
  saved.value = false
  const ok = await store.update(key.value, {
    title: editTitle.value.trim(),
    description: editDescription.value,
    priority: editPriority.value,
  })
  saving.value = false
  if (ok) {
    saved.value = true
    activeTab.value = 'description'
  }
}

// ── State change ─────────────────────────────────────────────────────────────
const changingState = ref(false)

async function changeState(next: string) {
  if (!item.value || next === item.value.state || changingState.value) return
  saved.value = false
  changingState.value = true
  await store.update(key.value, { state: next as WorkItemState })
  changingState.value = false
}

// ── Delete ───────────────────────────────────────────────────────────────────
const deleteOpen = ref(false)
const deleting = ref(false)

async function confirmDelete() {
  deleting.value = true
  const ok = await store.remove(key.value)
  deleting.value = false
  deleteOpen.value = false
  if (ok) await router.push({ name: 'items' })
}

// ── Loading / polling ────────────────────────────────────────────────────────
function refresh() {
  return store.fetchOne(key.value)
}

watch(key, () => {
  activeTab.value = 'description'
  saved.value = false
  void refresh()
}, { immediate: true })

// Poll while the item can still change, but never under an open form or dialog.
const timer = setInterval(() => {
  const idle = activeTab.value === 'description' && !deleteOpen.value && !changingState.value
  if (item.value && !isTerminal.value && idle && !document.hidden) void refresh()
}, POLL_MS)
onUnmounted(() => clearInterval(timer))
</script>

<template>
  <div class="space-y-4">
    <div class="flex min-w-0 items-center gap-3">
      <Button variant="ghost" size="sm" @click="router.push({ name: 'items' })">
        <ArrowLeft class="mr-2 h-4 w-4" />{{ t('common.back') }}
      </Button>
      <span class="truncate font-mono text-sm font-semibold">{{ key }}</span>
      <StatusBadge v-if="item" :state="item.state" />
      <Button variant="ghost" size="icon" :aria-label="t('common.refresh')" :disabled="store.loading" @click="refresh">
        <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': store.loading }" />
      </Button>
    </div>

    <Alert v-if="store.error" variant="destructive" :title="store.error" />
    <Alert v-else-if="saved" variant="success" :title="t('items.detail.saved')" />

    <div v-if="!item && store.loading" class="grid gap-6 lg:grid-cols-3">
      <div class="space-y-3 lg:col-span-1">
        <Skeleton class="h-40 w-full" />
        <Skeleton class="h-28 w-full" />
      </div>
      <div class="space-y-3 lg:col-span-2">
        <Skeleton class="h-8 w-2/3" />
        <Skeleton class="h-64 w-full" />
      </div>
    </div>

    <div v-else-if="item" class="grid gap-6 lg:grid-cols-3">
      <div class="space-y-6 lg:col-span-1">
        <Card>
          <CardHeader><CardTitle class="text-base">{{ t('items.detail.details') }}</CardTitle></CardHeader>
          <CardContent class="space-y-4 text-sm">
            <div class="flex items-start gap-2">
              <CircleDot class="mt-0.5 h-4 w-4 text-muted-foreground" />
              <div>
                <p class="text-xs text-muted-foreground">{{ t('items.fields.state') }}</p>
                <StatusBadge :state="item.state" class="mt-0.5" />
              </div>
            </div>
            <div class="flex items-start gap-2">
              <Flag class="mt-0.5 h-4 w-4 text-muted-foreground" />
              <div>
                <p class="text-xs text-muted-foreground">{{ t('items.fields.priority') }}</p>
                <p class="font-medium">{{ t(`priority.${item.priority}`) }}</p>
              </div>
            </div>
            <div class="flex items-start gap-2">
              <User class="mt-0.5 h-4 w-4 text-muted-foreground" />
              <div>
                <p class="text-xs text-muted-foreground">{{ t('items.fields.assignee') }}</p>
                <p class="font-medium">{{ item.assignee?.name ?? t('items.unassigned') }}</p>
              </div>
            </div>
            <div class="flex items-start gap-2">
              <Clock class="mt-0.5 h-4 w-4 text-muted-foreground" />
              <div>
                <p class="text-xs text-muted-foreground">{{ t('items.fields.updatedAt') }}</p>
                <p class="font-medium">{{ formatDateTime(item.updated_at, locale) }}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle class="text-base">{{ t('items.detail.actions') }}</CardTitle></CardHeader>
          <CardContent class="space-y-4">
            <div class="space-y-1.5">
              <Label>{{ t('items.detail.changeState') }}</Label>
              <Select
                :model-value="item.state" :options="stateOptions" :disabled="changingState"
                @update:model-value="changeState"
              />
            </div>
            <Button variant="destructive" class="w-full" @click="deleteOpen = true">
              <Trash2 class="mr-2 h-4 w-4" />{{ t('items.detail.deleteItem') }}
            </Button>
          </CardContent>
        </Card>
      </div>

      <div class="space-y-4 lg:col-span-2">
        <h1 class="text-2xl font-semibold tracking-tight">{{ item.title }}</h1>

        <div class="inline-flex gap-1.5 rounded-lg border bg-muted p-1">
          <button
            v-for="tab in tabs" :key="tab.value" type="button"
            class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
            :class="activeTab === tab.value
              ? 'bg-background text-foreground shadow-sm'
              : 'text-muted-foreground hover:text-foreground'"
            @click="switchTab(tab.value)"
          >{{ tab.label }}</button>
        </div>

        <Card v-if="activeTab === 'description'">
          <CardContent class="pt-6">
            <!-- Markdown source is shown as plain text; plug a sanitising renderer in here. -->
            <div v-if="item.description" class="whitespace-pre-wrap break-words text-sm leading-relaxed">{{ item.description }}</div>
            <p v-else class="text-sm text-muted-foreground">{{ t('items.noDescription') }}</p>
          </CardContent>
        </Card>

        <Card v-else>
          <form novalidate @submit.prevent="save">
            <CardContent class="space-y-4 pt-6">
              <div class="space-y-1.5">
                <Label for="edit-title">{{ t('items.fields.title') }} *</Label>
                <Input id="edit-title" v-model="editTitle" required />
                <p v-if="store.fieldErrors.title" class="text-sm text-destructive">{{ store.fieldErrors.title[0] }}</p>
              </div>
              <div class="space-y-1.5">
                <Label for="edit-description">{{ t('items.fields.description') }}</Label>
                <Textarea id="edit-description" v-model="editDescription" :rows="12" class="font-mono" />
                <p v-if="store.fieldErrors.description" class="text-sm text-destructive">
                  {{ store.fieldErrors.description[0] }}
                </p>
              </div>
              <div class="space-y-1.5 sm:max-w-xs">
                <Label>{{ t('items.fields.priority') }}</Label>
                <Select
                  :model-value="editPriority" :options="priorityOptions"
                  @update:model-value="editPriority = $event as WorkItemPriority"
                />
                <p v-if="store.fieldErrors.priority" class="text-sm text-destructive">
                  {{ store.fieldErrors.priority[0] }}
                </p>
              </div>
              <div class="flex justify-end gap-2">
                <Button variant="outline" :disabled="saving" @click="switchTab('description')">
                  {{ t('common.cancel') }}
                </Button>
                <Button type="submit" :disabled="!editValid || saving">
                  {{ saving ? t('common.saving') : t('common.save') }}
                </Button>
              </div>
            </CardContent>
          </form>
        </Card>
      </div>
    </div>

    <ConfirmDialog
      v-model:open="deleteOpen"
      :title="t('items.detail.deleteTitle', { key })"
      :description="t('items.detail.deleteDescription')"
      :confirm-label="t('common.delete')"
      :busy-label="t('items.detail.deleting')"
      :busy="deleting"
      destructive
      @confirm="confirmDelete"
    />
  </div>
</template>
