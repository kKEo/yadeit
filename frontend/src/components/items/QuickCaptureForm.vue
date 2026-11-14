<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Sparkles } from '@lucide/vue'
import * as ai from '@/api/ai'
import { addChecklistEntry } from '@/api/items'
import { useAi } from '@/composables/useAi'
import AiNote from '@/components/app/AiNote.vue'
import { v4 as uuidv4 } from 'uuid'
import type { AiItemDraft, CreateItemRequest, ItemDetail, ItemFilters, Priority } from '@/api/types'
import { useApiForm } from '@/composables/useApiForm'
import { captureDefaults, defaultProject, rememberProject, rememberedProject } from '@/lib/captureDefaults'
import { memberOptions, priorityOptions, stateOptions } from '@/lib/pickerOptions'
import { useCreateItem } from '@/queries/itemMutations'
import { useSlug } from '@/queries/keys'
import { useMembers, useProject, useProjects } from '@/queries/workspace'
import { useAuthStore } from '@/stores/auth'
import PropertyPicker from '@/components/app/PropertyPicker.vue'
import Alert from '@/components/ui/Alert.vue'
import Button from '@/components/ui/Button.vue'
import Kbd from '@/components/ui/Kbd.vue'
import Switch from '@/components/ui/Switch.vue'

/**
 * The quick capture form. Only the title is required; project, state,
 * priority, assignee and labels start from the filters of the list in view.
 */
const props = defineProps<{ filters: ItemFilters; parent?: string | null }>()
const emit = defineEmits<{ created: [item: ItemDetail]; done: [] }>()
const { t } = useI18n()
const auth = useAuthStore()
const slug = useSlug()
const projects = useProjects()
const members = useMembers()
const createItem = useCreateItem()
const form = useApiForm(['title', 'project'])

const title = ref('')
const projectKey = ref<string | null>(null)
const state = ref<string | null>(null)
const priority = ref<Priority>('none')
const assignee = ref<string | null>(null)
const labels = ref<string[]>([])
const createMore = ref(false)

// A draft the model wrote from the sentence in the title field. It fills the form; the person still decides.
const { available: aiAvailable } = useAi()
const draftForm = useApiForm()
const draft = ref<AiItemDraft | null>(null)

async function draftWithAi() {
  const sentence = title.value.trim()
  const key = projectKey.value
  if (sentence.length < 3 || !key) return
  await draftForm.submit(async () => {
    const result = await ai.draftItem(slug.value, key, sentence)
    draft.value = result
    title.value = result.title
    priority.value = result.priority
    const known = (project.data.value?.labels ?? []).map((l) => l.name)
    labels.value = result.labels.filter((name) => known.includes(name))
  })
}
const titleInput = ref<HTMLInputElement | null>(null)
// One key per logical create: a retry after a network error must not make a second item.
let idempotencyKey = uuidv4()

const projectList = computed(() => projects.data.value ?? [])
const project = useProject(projectKey)

watch(projectList, (list) => {
  if (projectKey.value === null && list.length) projectKey.value = defaultProject(props.filters, list, rememberedProject(slug.value))
}, { immediate: true })

// Defaults follow the project: its states and labels decide what the filters translate to.
watch(() => project.data.value, (detail) => {
  if (!detail || detail.key !== projectKey.value) return
  const defaults = captureDefaults(props.filters, detail, auth.user?.id ?? null)
  state.value = defaults.state
  priority.value = defaults.priority
  assignee.value = defaults.assignee
  labels.value = defaults.labels
}, { immediate: true })

const projectOptions = computed(() => projectList.value.map((p) => ({ value: p.key, label: p.name, hint: p.key })))
const stateChoices = computed(() => stateOptions(project.data.value?.states ?? [], t))
const defaultStateName = computed(() => project.data.value?.states.find((s) => s.is_default)?.name)
const labelChoices = computed(() => (project.data.value?.labels ?? []).map((l) => ({ value: l.name, label: l.name, color: l.color })))

onMounted(() => void nextTick(() => titleInput.value?.focus()))

async function submit() {
  const trimmed = title.value.trim()
  if (!trimmed || !projectKey.value) return
  const req: CreateItemRequest = { project: projectKey.value, title: trimmed }
  if (state.value) req.state = Number(state.value)
  if (priority.value !== 'none') req.priority = priority.value
  if (assignee.value) req.assignee = Number(assignee.value)
  if (labels.value.length) req.labels = labels.value
  if (props.parent) req.parent = props.parent
  if (draft.value) {
    req.description = draft.value.description
    req.kind = draft.value.kind
  }
  const criteria = draft.value?.acceptance ?? []

  let created: ItemDetail | null = null
  const ok = await form.submit(async () => {
    created = await createItem.mutateAsync({ req, idempotencyKey })
    // The drafted acceptance criteria become the new item's checklist.
    for (const text of criteria) await addChecklistEntry(slug.value, created.key, text).catch(() => undefined)
  })
  if (!ok || !created) return

  idempotencyKey = uuidv4()
  rememberProject(slug.value, projectKey.value)
  emit('created', created)
  draft.value = null
  if (createMore.value) {
    title.value = ''
    void nextTick(() => titleInput.value?.focus())
  } else {
    emit('done')
  }
}

const pickerTrigger = 'inline-flex h-8 max-w-[12rem] items-center gap-1.5 rounded-md border px-2 text-xs hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring'
</script>

<template>
  <p v-if="projects.status.value !== 'pending' && projectList.length === 0" class="text-sm text-muted-foreground">
    {{ t('capture.noProjects') }}
  </p>
  <form v-else class="space-y-4" @submit.prevent="submit" @keydown.meta.enter.prevent="submit" @keydown.ctrl.enter.prevent="submit">
    <Alert v-if="form.error.value" variant="destructive" :title="form.error.value" />
    <div>
      <input
        ref="titleInput" v-model="title" type="text" maxlength="255" required :aria-label="t('items.fields.title')"
        :placeholder="t('items.create.titlePlaceholder')" data-testid="capture-title"
        class="h-10 w-full rounded-md border border-input bg-background px-3 text-base placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      />
      <p v-if="form.fieldErrors.value.title?.length" class="mt-1 text-sm text-destructive">{{ form.fieldErrors.value.title[0] }}</p>
      <p v-if="form.fieldErrors.value.project?.length" class="mt-1 text-sm text-destructive">{{ form.fieldErrors.value.project[0] }}</p>
      <p v-if="parent" class="mt-1 text-xs text-muted-foreground">{{ t('capture.subItemOf', { key: parent }) }}</p>
    </div>

    <div v-if="aiAvailable" class="space-y-2" data-ai-draft>
      <div v-if="!draft" class="flex flex-wrap items-center gap-2">
        <Button variant="outline" size="sm" class="h-8 gap-1.5" :disabled="title.trim().length < 3 || !projectKey || draftForm.pending.value" @click="draftWithAi">
          <Sparkles class="h-3.5 w-3.5" />{{ draftForm.pending.value ? t('ai.working') : t('ai.draft.button') }}
        </Button>
        <span class="text-xs text-muted-foreground">{{ t('ai.draft.hint') }}</span>
      </div>
      <Alert v-if="draftForm.error.value" variant="destructive" :title="draftForm.error.value" />
      <div v-if="draft" class="space-y-2 rounded-lg border bg-muted/30 p-3 text-sm">
        <div class="flex items-start justify-between gap-2">
          <AiNote />
          <button type="button" class="rounded text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline" @click="draft = null">{{ t('ai.draft.discard') }}</button>
        </div>
        <label class="block text-xs font-medium text-muted-foreground" for="capture-draft-description">{{ t('ai.draft.description') }}</label>
        <textarea
          id="capture-draft-description" v-model="draft.description" rows="5"
          class="w-full rounded-md border border-input bg-background px-2 py-1.5 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        />
        <template v-if="draft.acceptance.length">
          <p class="text-xs font-medium text-muted-foreground">{{ t('ai.draft.acceptance') }}</p>
          <ul class="list-disc space-y-0.5 pl-5"><li v-for="(line, i) in draft.acceptance" :key="i">{{ line }}</li></ul>
        </template>
        <p class="text-xs text-muted-foreground">{{ t(`kind.${draft.kind}`) }}</p>
      </div>
    </div>

    <div class="flex flex-wrap gap-2">
      <PropertyPicker :label="t('items.fields.project')" :options="projectOptions" :model-value="projectKey" @update:model-value="projectKey = $event as string">
        <template #trigger="{ summary }"><button type="button" :class="pickerTrigger" :aria-label="`${t('items.fields.project')}: ${summary}`"><span class="truncate">{{ summary }}</span></button></template>
      </PropertyPicker>
      <PropertyPicker
        :label="t('items.fields.state')" :options="stateChoices" :model-value="state" :placeholder="defaultStateName ?? t('items.fields.state')"
        @update:model-value="state = $event as string"
      >
        <template #trigger="{ summary }"><button type="button" :class="pickerTrigger" :aria-label="`${t('items.fields.state')}: ${summary}`"><span class="truncate">{{ summary }}</span></button></template>
      </PropertyPicker>
      <PropertyPicker :label="t('items.fields.priority')" :options="priorityOptions(t)" :model-value="priority" @update:model-value="priority = $event as Priority">
        <template #trigger="{ summary }"><button type="button" :class="pickerTrigger" :aria-label="`${t('items.fields.priority')}: ${summary}`"><span class="truncate">{{ summary }}</span></button></template>
      </PropertyPicker>
      <PropertyPicker
        :label="t('items.fields.assignee')" :options="memberOptions(members.data.value ?? [], t)" :model-value="assignee ?? ''"
        @update:model-value="assignee = ($event as string) || null"
      >
        <template #trigger="{ summary }"><button type="button" :class="pickerTrigger" :aria-label="`${t('items.fields.assignee')}: ${summary}`"><span class="truncate">{{ summary }}</span></button></template>
      </PropertyPicker>
      <PropertyPicker
        v-if="labelChoices.length" multiple :label="t('items.fields.labels')" :options="labelChoices" :model-value="labels"
        :placeholder="t('items.fields.labels')" @update:model-value="labels = $event as string[]"
      >
        <template #trigger="{ summary }"><button type="button" :class="pickerTrigger" :aria-label="`${t('items.fields.labels')}: ${summary}`"><span class="truncate">{{ summary }}</span></button></template>
      </PropertyPicker>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3 border-t pt-3">
      <label class="flex items-center gap-2 text-sm">
        <Switch v-model="createMore" :aria-label="t('capture.createMore')" data-testid="capture-more" />
        <span aria-hidden="true">{{ t('capture.createMore') }}</span>
      </label>
      <div class="flex items-center gap-2">
        <Kbd keys="mod+enter" />
        <Button type="submit" :disabled="!title.trim() || !projectKey || form.pending.value">
          {{ form.pending.value ? t('items.create.submitting') : t('items.create.submit') }}
        </Button>
      </div>
    </div>
  </form>
</template>
