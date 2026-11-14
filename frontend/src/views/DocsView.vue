<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { FileText, Link2, Plus } from '@lucide/vue'
import * as api from '@/api/pages'
import { listProjects } from '@/api/projects'
import type { DocPageSummary, Project } from '@/api/types'
import { useApiForm } from '@/composables/useApiForm'
import { useCanEdit } from '@/composables/useCanEdit'
import { formatRelative } from '@/lib/format'
import EmptyState from '@/components/app/EmptyState.vue'
import Alert from '@/components/ui/Alert.vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Skeleton from '@/components/ui/Skeleton.vue'

/** The pages of the workspace, filterable by project; a new page opens straight in the editor. */
const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const canEdit = useCanEdit()
const slug = computed(() => String(route.params.slug))
const list = useApiForm()
const create = useApiForm()

const loading = ref(true)
const pages = ref<DocPageSummary[]>([])
const projects = ref<Project[]>([])
const project = ref('all')
const search = ref('')

const projectOptions = computed(() => [
  { value: 'all', label: t('docs.allProjects') },
  { value: 'none', label: t('docs.workspaceWide') },
  ...projects.value.map((p) => ({ value: p.key, label: p.name })),
])

async function load() {
  await list.submit(async () => {
    pages.value = await api.listPages(slug.value, {
      project: project.value === 'all' ? undefined : project.value,
      q: search.value.trim() || undefined,
    })
  })
  loading.value = false
}

onMounted(async () => {
  projects.value = await listProjects(slug.value).catch(() => [])
  await load()
})

let timer: ReturnType<typeof setTimeout> | null = null
watch(project, load)
watch(search, () => {
  if (timer) clearTimeout(timer)
  timer = setTimeout(load, 250)
})

async function newPage() {
  await create.submit(async () => {
    const belongsTo = project.value === 'all' || project.value === 'none' ? null : project.value
    const page = await api.createPage(slug.value, { title: t('docs.newTitle'), project: belongsTo })
    await router.push({ name: 'doc', params: { slug: slug.value, id: page.id }, query: { edit: '1' } })
  })
}
</script>

<template>
  <div class="mx-auto max-w-4xl space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold tracking-tight">{{ t('docs.title') }}</h1>
        <p class="text-sm text-muted-foreground">{{ t('docs.subtitle') }}</p>
      </div>
      <Button v-if="canEdit" size="sm" class="gap-2" :disabled="create.pending.value" @click="newPage">
        <Plus class="h-4 w-4" />{{ create.pending.value ? t('docs.creating') : t('docs.new') }}
      </Button>
    </div>

    <Alert v-if="list.error.value || create.error.value" variant="destructive" :title="(list.error.value || create.error.value)!" />

    <div class="flex flex-wrap gap-3">
      <Input v-model="search" type="search" class="max-w-xs" :aria-label="t('docs.search')" :placeholder="t('docs.search')" />
      <Select :model-value="project" :options="projectOptions" class="w-56" :aria-label="t('docs.project')" @update:model-value="project = $event" />
    </div>

    <div v-if="loading" class="space-y-2" aria-busy="true"><Skeleton v-for="i in 3" :key="i" class="h-14 w-full" /></div>

    <EmptyState
      v-else-if="pages.length === 0" :icon="FileText" :title="t('docs.emptyTitle')" :description="t('docs.emptyDescription')"
      :action-label="canEdit ? t('docs.new') : undefined" @action="newPage"
    />

    <ul v-else class="divide-y rounded-lg border">
      <li v-for="page in pages" :key="page.id">
        <RouterLink
          :to="{ name: 'doc', params: { slug, id: page.id } }"
          class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-3 hover:bg-accent/50 focus-visible:bg-accent focus-visible:outline-none"
        >
          <span class="min-w-0">
            <span class="flex flex-wrap items-center gap-2 font-medium">
              <span class="truncate">{{ page.title }}</span>
              <Badge v-if="page.status === 'draft'" variant="outline">{{ t('docs.draft') }}</Badge>
              <Link2 v-if="page.public_token" class="h-3.5 w-3.5 text-muted-foreground" :aria-label="t('docs.shared')" />
            </span>
            <span class="block text-xs text-muted-foreground">
              {{ page.project ? page.project.name : t('docs.workspaceWide') }} ·
              {{ page.updated_by
                ? t('docs.updated', { when: formatRelative(page.updated_at, locale), name: page.updated_by.name })
                : t('docs.updatedNoName', { when: formatRelative(page.updated_at, locale) }) }}
            </span>
          </span>
        </RouterLink>
      </li>
    </ul>
  </div>
</template>
