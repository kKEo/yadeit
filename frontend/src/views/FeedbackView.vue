<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Check, Copy, Megaphone, Plus, Sparkles } from '@lucide/vue'
import { triageFeedback } from '@/api/ai'
import { useAi } from '@/composables/useAi'
import AiNote from '@/components/app/AiNote.vue'
import * as api from '@/api/feedback'
import { listProjects } from '@/api/projects'
import type { AiTriageSuggestion, Feedback, FeedbackStatusFilter, Project } from '@/api/types'
import { useApiForm } from '@/composables/useApiForm'
import { useCanEdit } from '@/composables/useCanEdit'
import { useItemPeek } from '@/composables/useItemPeek'
import { useShortcuts } from '@/composables/useShortcuts'
import { formatDateTime, formatRelative } from '@/lib/format'
import { roleAtLeast } from '@/lib/roles'
import { useWorkspaceStore } from '@/stores/workspace'
import EmptyState from '@/components/app/EmptyState.vue'
import FormField from '@/components/app/FormField.vue'
import Alert from '@/components/ui/Alert.vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Dialog from '@/components/ui/Dialog.vue'
import Input from '@/components/ui/Input.vue'
import Kbd from '@/components/ui/Kbd.vue'
import Select from '@/components/ui/Select.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import Tabs from '@/components/ui/Tabs.vue'
import Textarea from '@/components/ui/Textarea.vue'

/**
 * The feedback inbox. Everyone can send feedback; members decide what happens
 * to it, by mouse or with 1 (accept), 2 (decline), 3 (duplicate) and H (snooze).
 */
const route = useRoute()
const { t, locale } = useI18n()
const workspace = useWorkspaceStore()
const canTriage = useCanEdit()
const isAdmin = computed(() => roleAtLeast(workspace.role, 'admin'))
const { open: openItem } = useItemPeek()
const slug = computed(() => String(route.params.slug))
const list = useApiForm()
const sendForm = useApiForm(['project', 'title', 'body'])
const dialogForm = useApiForm(['item', 'of', 'reason'])

const STATUSES: FeedbackStatusFilter[] = ['new', 'snoozed', 'accepted', 'declined', 'duplicate']
const status = ref<FeedbackStatusFilter>('new')
const loading = ref(true)
const rows = ref<Feedback[]>([])
const waiting = ref<number | null>(null)
const projects = ref<Project[]>([])
const focusedId = ref<number | null>(null)

const tabs = computed(() => STATUSES.map((value) => ({
  value, label: value === 'new' && waiting.value ? `${t('feedback.tabs.new')} (${waiting.value})` : t(`feedback.tabs.${value}`),
})))
const focused = computed(() => rows.value.find((row) => row.id === focusedId.value) ?? null)
const projectOptions = computed(() => projects.value.map((p) => ({ value: p.key, label: p.name })))

async function load() {
  await list.submit(async () => {
    const res = await api.listFeedback(slug.value, { status: status.value })
    rows.value = res.data
    waiting.value = res.waiting
    if (!rows.value.some((row) => row.id === focusedId.value)) focusedId.value = rows.value[0]?.id ?? null
  })
  loading.value = false
}

onMounted(async () => {
  projects.value = await listProjects(slug.value).catch(() => [])
  await load()
})
watch(status, load)

/** Runs one decision and takes the row out of the list it no longer belongs to. */
async function decide(row: Feedback, decision: Parameters<typeof api.decide>[2], form = list): Promise<boolean> {
  return form.submit(async () => {
    await api.decide(slug.value, row.id, decision)
    const index = rows.value.findIndex((r) => r.id === row.id)
    const neighbour = rows.value[index + 1] ?? rows.value[index - 1] ?? null
    rows.value = rows.value.filter((r) => r.id !== row.id)
    if (focusedId.value === row.id) focusedId.value = neighbour?.id ?? null
    if (waiting.value !== null) waiting.value = Math.max(0, waiting.value + (decision.action === 'reopen' ? 1 : status.value === 'new' ? -1 : 0))
  })
}

const accept = (row: Feedback) => decide(row, { action: 'accept', body: {} })
const reopen = (row: Feedback) => decide(row, { action: 'reopen' })
const snooze = (row: Feedback, days: number) => decide(row, { action: 'snooze', body: { until: new Date(Date.now() + days * 86_400_000).toISOString() } })
const unsnooze = (row: Feedback) => decide(row, { action: 'snooze', body: { until: null } })

// ── AI suggestions: shown under the row, applied only by clicking one of the usual decisions ─
const { available: aiAvailable } = useAi()
const aiForm = useApiForm()
const suggestions = ref<Record<number, AiTriageSuggestion>>({})
const suggestingId = ref<number | null>(null)

async function suggest(row: Feedback) {
  suggestingId.value = row.id
  await aiForm.submit(async () => {
    suggestions.value = { ...suggestions.value, [row.id]: await triageFeedback(slug.value, row.id) }
  })
  suggestingId.value = null
}

// ── Dialogs: add to an item, duplicate of, decline ───────────────────────────
const dialog = reactive({ kind: null as 'addTo' | 'duplicate' | 'decline' | null, row: null as Feedback | null, itemKey: '', of: '', reason: '' })
const dialogOpen = computed({
  get: () => dialog.kind !== null,
  set: (open) => { if (!open) dialog.kind = null },
})
const others = computed(() => rows.value.filter((r) => r.id !== dialog.row?.id).map((r) => ({ value: String(r.id), label: `#${r.id} ${r.title}` })))

function openDialog(kind: 'addTo' | 'duplicate' | 'decline', row: Feedback) {
  dialogForm.clear()
  Object.assign(dialog, { kind, row, itemKey: '', of: '', reason: '' })
}

async function confirmDialog() {
  const row = dialog.row
  if (!row || !dialog.kind) return
  const decision: Parameters<typeof api.decide>[2] | null =
    dialog.kind === 'addTo' ? (dialog.itemKey.trim() ? { action: 'accept', body: { item: dialog.itemKey.trim().toUpperCase() } } : null)
      : dialog.kind === 'duplicate' ? (dialog.of ? { action: 'duplicate', body: { of: Number(dialog.of) } } : null)
        : { action: 'decline', body: { reason: dialog.reason.trim() || null } }
  if (decision && await decide(row, decision, dialogForm)) dialog.kind = null
}

// ── Sending feedback from inside the app ─────────────────────────────────────
const send = reactive({ open: false, project: '', title: '', body: '', done: false })

function openSend() {
  sendForm.clear()
  Object.assign(send, { open: true, project: projects.value[0]?.key ?? '', title: '', body: '', done: false })
}

async function submitSend() {
  if (send.title.trim() === '' || send.project === '') return
  const ok = await sendForm.submit(async () => {
    await api.sendFeedback(slug.value, { project: send.project, title: send.title.trim(), body: send.body.trim() || null })
  })
  if (ok) {
    send.done = true
    send.open = false
    status.value === 'new' ? await load() : (status.value = 'new')
  }
}

// ── The public form, per project ─────────────────────────────────────────────
const copiedFor = ref<string | null>(null)
const formUrl = (project: Project) => (project.feedback_token ? `${window.location.origin}/f/${project.feedback_token}` : null)

function togglePublicForm(project: Project) {
  return list.submit(async () => {
    const token = await api.setPublicForm(slug.value, project.key, !project.feedback_token)
    projects.value = projects.value.map((p) => (p.key === project.key ? { ...p, feedback_token: token } : p))
  })
}

async function copyFormUrl(project: Project) {
  const url = formUrl(project)
  if (!url) return
  try {
    await navigator.clipboard.writeText(url)
    copiedFor.value = project.key
  } catch {
    copiedFor.value = null
  }
}

// ── Keyboard ─────────────────────────────────────────────────────────────────
function moveFocus(delta: number) {
  if (rows.value.length === 0) return
  const index = rows.value.findIndex((row) => row.id === focusedId.value)
  focusedId.value = rows.value[index === -1 ? 0 : Math.min(Math.max(index + delta, 0), rows.value.length - 1)].id
}

const canAct = () => canTriage.value && status.value === 'new' && focused.value !== null && !dialogOpen.value && !send.open
const group = 'feedback.shortcuts.group'
useShortcuts([
  { keys: 'j', group, description: 'shortcuts.next', handler: () => moveFocus(1) },
  { keys: 'k', group, description: 'shortcuts.previous', handler: () => moveFocus(-1) },
  { keys: '1', group, description: 'feedback.shortcuts.accept', when: canAct, handler: () => { if (focused.value) void accept(focused.value) } },
  { keys: '2', group, description: 'feedback.shortcuts.decline', when: canAct, handler: () => { if (focused.value) openDialog('decline', focused.value) } },
  { keys: '3', group, description: 'feedback.shortcuts.duplicate', when: canAct, handler: () => { if (focused.value) openDialog('duplicate', focused.value) } },
  { keys: 'h', group, description: 'feedback.shortcuts.snooze', when: canAct, handler: () => { if (focused.value) void snooze(focused.value, 1) } },
])
</script>

<template>
  <div class="mx-auto max-w-4xl space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold tracking-tight">{{ t('feedback.title') }}</h1>
        <p class="text-sm text-muted-foreground">{{ t('feedback.subtitle') }}</p>
      </div>
      <Button size="sm" class="gap-2" :disabled="projects.length === 0" @click="openSend"><Plus class="h-4 w-4" />{{ t('feedback.send') }}</Button>
    </div>

    <Alert v-if="list.error.value" variant="destructive" :title="list.error.value" />
    <Alert v-else-if="send.done" variant="success" :title="t('feedback.sent')" />

    <Tabs v-if="canTriage" v-model="status" :tabs="tabs" />

    <div v-if="loading" class="space-y-2" aria-busy="true"><Skeleton v-for="i in 3" :key="i" class="h-20 w-full" /></div>

    <EmptyState
      v-else-if="rows.length === 0" :icon="Megaphone"
      :title="status === 'new' ? t('feedback.emptyInbox') : t('feedback.empty')" :description="status === 'new' ? t('feedback.emptyInboxHint') : undefined"
    />

    <ul v-else class="divide-y rounded-lg border">
      <li
        v-for="row in rows" :key="row.id" :data-feedback-id="row.id" :data-focused="row.id === focusedId"
        class="space-y-2 px-4 py-3 data-[focused=true]:bg-accent/60 data-[focused=true]:shadow-[inset_2px_0_0_hsl(var(--primary))]"
        @click="focusedId = row.id"
      >
        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
          <p class="min-w-0 font-medium"><span class="mr-1.5 font-mono text-xs text-muted-foreground">#{{ row.id }}</span>{{ row.title }}</p>
          <time :datetime="row.created_at" :title="formatDateTime(row.created_at, locale)" class="shrink-0 text-xs text-muted-foreground">
            {{ formatRelative(row.created_at, locale) }}
          </time>
        </div>
        <p v-if="row.body" class="whitespace-pre-line break-words text-sm text-muted-foreground">{{ row.body }}</p>
        <p class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
          <Badge variant="outline">{{ row.project.name }}</Badge>
          <Badge variant="outline">{{ t(`feedback.source.${row.source}`) }}</Badge>
          <span>{{ row.from.name ? t('feedback.from', { name: row.from.name }) : t('feedback.fromUnknown') }}</span>
          <a v-if="row.from.email" :href="`mailto:${row.from.email}`" class="underline-offset-2 hover:underline">{{ row.from.email }}</a>
          <button
            v-if="row.item" type="button" class="rounded text-primary underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            @click.stop="openItem(row.item.key)"
          >{{ t('feedback.countsFor', { key: row.item.key }) }} ({{ t('feedback.demand', row.item.demand) }})</button>
          <span v-if="row.status === 'declined'">{{ row.decline_reason ? t('feedback.declined', { reason: row.decline_reason }) : t('feedback.declinedNoReason') }}</span>
          <span v-if="row.duplicate_of">{{ t('feedback.duplicateOf', { id: row.duplicate_of }) }}</span>
          <span v-if="row.snoozed_until">{{ t('feedback.snoozedUntil', { date: formatDateTime(row.snoozed_until, locale) }) }}</span>
        </p>

        <div v-if="canTriage" class="flex flex-wrap items-center gap-2">
          <template v-if="status === 'new'">
            <Button size="sm" class="gap-1.5" :disabled="list.pending.value" @click.stop="accept(row)">{{ t('feedback.accept') }}<Kbd keys="1" /></Button>
            <Button variant="outline" size="sm" :disabled="list.pending.value" @click.stop="openDialog('addTo', row)">{{ t('feedback.addTo') }}</Button>
            <Button variant="outline" size="sm" class="gap-1.5" :disabled="list.pending.value" @click.stop="openDialog('decline', row)">{{ t('feedback.decline') }}<Kbd keys="2" /></Button>
            <Button variant="outline" size="sm" class="gap-1.5" :disabled="list.pending.value" @click.stop="openDialog('duplicate', row)">{{ t('feedback.duplicate') }}<Kbd keys="3" /></Button>
            <Button variant="ghost" size="sm" class="gap-1.5" :disabled="list.pending.value" @click.stop="snooze(row, 1)">{{ t('feedback.snoozeTomorrow') }}<Kbd keys="h" /></Button>
            <Button variant="ghost" size="sm" :disabled="list.pending.value" @click.stop="snooze(row, 7)">{{ t('feedback.snoozeWeek') }}</Button>
            <Button v-if="aiAvailable" variant="ghost" size="sm" class="gap-1.5" :disabled="aiForm.pending.value" @click.stop="suggest(row)">
              <Sparkles class="h-3.5 w-3.5" />{{ suggestingId === row.id ? t('ai.working') : t('ai.triage.button') }}
            </Button>
          </template>
          <Button v-else variant="outline" size="sm" :disabled="list.pending.value" @click.stop="status === 'snoozed' ? unsnooze(row) : reopen(row)">
            {{ t('feedback.reopen') }}
          </Button>
        </div>

        <div v-if="suggestions[row.id] && status === 'new'" class="space-y-2 rounded-lg border bg-muted/30 p-3 text-sm" data-ai-triage>
          <AiNote />
          <p>{{ suggestions[row.id].reason }}</p>
          <div class="flex flex-wrap gap-2">
            <Button
              v-if="suggestions[row.id].duplicate_of" variant="outline" size="sm" :disabled="list.pending.value"
              @click.stop="decide(row, { action: 'duplicate', body: { of: suggestions[row.id].duplicate_of! } })"
            >{{ t('ai.triage.markDuplicate', { id: suggestions[row.id].duplicate_of }) }}</Button>
            <Button
              v-if="suggestions[row.id].existing_item" variant="outline" size="sm" :disabled="list.pending.value"
              @click.stop="decide(row, { action: 'accept', body: { item: suggestions[row.id].existing_item! } })"
            >{{ t('ai.triage.addTo', { key: suggestions[row.id].existing_item }) }}</Button>
            <Button
              variant="outline" size="sm" :disabled="list.pending.value"
              @click.stop="decide(row, { action: 'accept', body: { kind: suggestions[row.id].kind, priority: suggestions[row.id].priority } })"
            >{{ t('ai.triage.accept', { kind: t(`kind.${suggestions[row.id].kind}`) }) }}</Button>
          </div>
        </div>
      </li>
    </ul>

    <section v-if="isAdmin && projects.length > 0" class="space-y-2 rounded-lg border p-4">
      <h2 class="text-sm font-medium">{{ t('feedback.publicTitle') }}</h2>
      <p class="text-xs text-muted-foreground">{{ t('feedback.publicHint') }}</p>
      <ul class="space-y-2">
        <li v-for="project in projects" :key="project.key" class="flex flex-wrap items-center gap-2 text-sm" :data-public-form="project.key">
          <span class="w-40 shrink-0 truncate">{{ project.name }}</span>
          <Input v-if="formUrl(project)" :model-value="formUrl(project)!" readonly class="h-8 min-w-0 flex-1 font-mono text-xs" :aria-label="t('feedback.publicTitle')" @focus="($event.target as HTMLInputElement).select()" />
          <Button v-if="formUrl(project)" variant="outline" size="sm" class="gap-1.5" @click="copyFormUrl(project)">
            <component :is="copiedFor === project.key ? Check : Copy" class="h-3.5 w-3.5" />{{ copiedFor === project.key ? t('feedback.copied') : t('feedback.copy') }}
          </Button>
          <Button variant="outline" size="sm" :disabled="list.pending.value" @click="togglePublicForm(project)">
            {{ t(project.feedback_token ? 'feedback.publicOff' : 'feedback.publicOn') }}
          </Button>
        </li>
      </ul>
    </section>

    <Dialog
      v-model:open="dialogOpen" :close-label="t('common.close')"
      :title="dialog.kind === 'addTo' ? t('feedback.addToTitle') : dialog.kind === 'duplicate' ? t('feedback.duplicateTitle') : t('feedback.declineTitle')"
      :description="dialog.kind === 'addTo' ? t('feedback.addToHint') : dialog.kind === 'duplicate' ? t('feedback.duplicateHint') : dialog.row?.title"
    >
      <form novalidate class="space-y-4" @submit.prevent="confirmDialog">
        <Alert v-if="dialogForm.error.value" variant="destructive" :title="dialogForm.error.value" />
        <FormField v-if="dialog.kind === 'addTo'" id="feedback-item" :label="t('feedback.itemKey')" :errors="dialogForm.fieldErrors.value.item">
          <Input id="feedback-item" v-model="dialog.itemKey" autofocus autocomplete="off" class="font-mono uppercase placeholder:normal-case placeholder:font-sans" :placeholder="t('feedback.itemKeyPlaceholder')" />
        </FormField>
        <template v-else-if="dialog.kind === 'duplicate'">
          <p v-if="others.length === 0" class="text-sm text-muted-foreground">{{ t('feedback.noOthers') }}</p>
          <Select v-else :model-value="dialog.of" :options="others" :aria-label="t('feedback.duplicateTitle')" @update:model-value="dialog.of = $event" />
        </template>
        <FormField v-else id="feedback-reason" :label="t('feedback.reason')" :errors="dialogForm.fieldErrors.value.reason">
          <Input id="feedback-reason" v-model="dialog.reason" autofocus maxlength="500" />
        </FormField>
        <div class="flex justify-end gap-2">
          <Button variant="outline" @click="dialog.kind = null">{{ t('common.cancel') }}</Button>
          <Button type="submit" :disabled="dialogForm.pending.value || (dialog.kind === 'addTo' && !dialog.itemKey.trim()) || (dialog.kind === 'duplicate' && !dialog.of)">
            {{ dialog.kind === 'addTo' ? t('feedback.add') : dialog.kind === 'duplicate' ? t('feedback.duplicate') : t('feedback.decline') }}
          </Button>
        </div>
      </form>
    </Dialog>

    <Dialog v-model:open="send.open" :title="t('feedback.sendTitle')" :description="t('feedback.sendHint')" :close-label="t('common.close')">
      <form novalidate class="space-y-4" @submit.prevent="submitSend">
        <Alert v-if="sendForm.error.value" variant="destructive" :title="sendForm.error.value" />
        <FormField id="send-project" :label="t('feedback.project')" :errors="sendForm.fieldErrors.value.project">
          <Select id="send-project" :model-value="send.project" :options="projectOptions" @update:model-value="send.project = $event" />
        </FormField>
        <FormField id="send-title" :label="t('feedback.what')" :errors="sendForm.fieldErrors.value.title">
          <Input id="send-title" v-model="send.title" maxlength="200" autofocus />
        </FormField>
        <FormField id="send-body" :label="t('feedback.details')" :errors="sendForm.fieldErrors.value.body">
          <Textarea id="send-body" v-model="send.body" :rows="5" />
        </FormField>
        <div class="flex justify-end gap-2">
          <Button variant="outline" @click="send.open = false">{{ t('common.cancel') }}</Button>
          <Button type="submit" :disabled="sendForm.pending.value || send.title.trim() === ''">{{ sendForm.pending.value ? t('feedback.sending') : t('feedback.send') }}</Button>
        </div>
      </form>
    </Dialog>
  </div>
</template>
