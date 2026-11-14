<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ArrowLeft, Check, Copy, Pencil, Trash2 } from '@lucide/vue'
import { ApiError } from '@/api/client'
import * as api from '@/api/pages'
import { listProjects } from '@/api/projects'
import type { DocPage, Project, SavePageRequest } from '@/api/types'
import { useApiForm } from '@/composables/useApiForm'
import { useCanEdit } from '@/composables/useCanEdit'
import { formatDateTime } from '@/lib/format'
import ConfirmDialog from '@/components/app/ConfirmDialog.vue'
import FormField from '@/components/app/FormField.vue'
import MarkdownView from '@/components/items/MarkdownView.vue'
import Alert from '@/components/ui/Alert.vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import Tabs from '@/components/ui/Tabs.vue'
import Textarea from '@/components/ui/Textarea.vue'

/** One page: read it, edit it with a preview, publish it, share it by link. */
const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const canEdit = useCanEdit()
const slug = computed(() => String(route.params.slug))
const id = computed(() => Number(route.params.id))
const form = useApiForm(['title', 'body', 'project'])

const loading = ref(true)
const page = ref<DocPage | null>(null)
const projects = ref<Project[]>([])
const editing = ref(false)
const tab = ref('write')
const title = ref('')
const body = ref('')
const project = ref('none')
const conflict = ref(false)
const copied = ref(false)
const deleteOpen = ref(false)

const projectOptions = computed(() => [
  { value: 'none', label: t('docs.workspaceWide') },
  ...projects.value.map((p) => ({ value: p.key, label: p.name })),
])
const publicUrl = computed(() => (page.value?.public_token ? `${window.location.origin}/p/${page.value.public_token}` : null))

function startEditing() {
  if (!page.value) return
  title.value = page.value.title
  body.value = page.value.body ?? ''
  project.value = page.value.project?.key ?? 'none'
  tab.value = 'write'
  conflict.value = false
  editing.value = true
}

onMounted(async () => {
  await form.submit(async () => {
    [page.value, projects.value] = await Promise.all([api.getPage(slug.value, id.value), listProjects(slug.value)])
  })
  loading.value = false
  if (route.query.edit === '1' && canEdit.value) startEditing()
})

/** Sends a change with the version on screen; a 412 means someone else saved in between. */
async function change(req: SavePageRequest): Promise<boolean> {
  const current = page.value
  if (!current) return false
  conflict.value = false
  return form.submit(async () => {
    try {
      page.value = await api.updatePage(slug.value, current.id, { ...req, expected_version: current.version })
    } catch (err: unknown) {
      if (err instanceof ApiError && err.status === 412) {
        conflict.value = true
        return
      }
      throw err
    }
  })
}

async function save() {
  if (title.value.trim() === '') return
  const ok = await change({
    title: title.value.trim(), body: body.value.trim() === '' ? null : body.value, project: project.value === 'none' ? null : project.value,
  })
  if (ok && !conflict.value) editing.value = false
}

async function copyLink() {
  if (!publicUrl.value) return
  try {
    await navigator.clipboard.writeText(publicUrl.value)
    copied.value = true
  } catch {
    copied.value = false
  }
}

async function confirmDelete() {
  const current = page.value
  if (!current) return
  const ok = await form.submit(() => api.deletePage(slug.value, current.id))
  deleteOpen.value = false
  if (ok) await router.push({ name: 'docs', params: { slug: slug.value } })
}
</script>

<template>
  <div class="mx-auto max-w-3xl space-y-4">
    <RouterLink :to="{ name: 'docs', params: { slug } }" class="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
      <ArrowLeft class="h-4 w-4" />{{ t('docs.back') }}
    </RouterLink>

    <div v-if="loading" class="space-y-3" aria-busy="true"><Skeleton class="h-9 w-2/3" /><Skeleton class="h-64 w-full" /></div>
    <Alert v-else-if="!page" variant="destructive" :title="form.error.value ?? t('docs.notFound')" />

    <template v-else>
      <Alert v-if="conflict" variant="destructive" :title="t('docs.conflict')" />
      <Alert v-else-if="form.error.value" variant="destructive" :title="form.error.value" />

      <form v-if="editing" novalidate class="space-y-4" @submit.prevent="save">
        <FormField id="doc-title" :label="t('docs.titleLabel')" :errors="form.fieldErrors.value.title">
          <Input id="doc-title" v-model="title" maxlength="200" required />
        </FormField>
        <FormField id="doc-project" :label="t('docs.project')" :errors="form.fieldErrors.value.project">
          <Select id="doc-project" :model-value="project" :options="projectOptions" @update:model-value="project = $event" />
        </FormField>
        <Tabs v-model="tab" :tabs="[{ value: 'write', label: t('docs.write') }, { value: 'preview', label: t('docs.preview') }]" class="h-8">
          <template #write>
            <Textarea
              v-model="body" :rows="22" class="font-mono text-sm" :aria-label="t('docs.write')" :placeholder="t('docs.bodyPlaceholder')"
              @keydown.meta.enter.prevent="save" @keydown.ctrl.enter.prevent="save"
            />
          </template>
          <template #preview>
            <div class="min-h-[12rem] rounded-md border p-4">
              <MarkdownView v-if="body.trim()" :source="body" />
              <p v-else class="text-sm text-muted-foreground">{{ t('docs.empty') }}</p>
            </div>
          </template>
        </Tabs>
        <p v-if="form.fieldErrors.value.body?.length" class="text-sm text-destructive">{{ form.fieldErrors.value.body[0] }}</p>
        <div class="flex justify-end gap-2">
          <Button variant="outline" :disabled="form.pending.value" @click="editing = false">{{ t('docs.cancel') }}</Button>
          <Button type="submit" :disabled="form.pending.value || title.trim() === ''">{{ form.pending.value ? t('docs.saving') : t('docs.save') }}</Button>
        </div>
      </form>

      <template v-else>
        <header class="space-y-2">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <h1 class="text-3xl font-semibold tracking-tight">{{ page.title }}</h1>
            <div v-if="canEdit" class="flex flex-wrap items-center gap-2">
              <Button variant="outline" size="sm" class="gap-1.5" @click="startEditing"><Pencil class="h-3.5 w-3.5" />{{ t('docs.edit') }}</Button>
              <Button variant="outline" size="sm" :disabled="form.pending.value" @click="change({ status: page.status === 'published' ? 'draft' : 'published' })">
                {{ t(page.status === 'published' ? 'docs.unpublish' : 'docs.publish') }}
              </Button>
              <Button variant="ghost" size="sm" :aria-label="t('docs.delete')" @click="deleteOpen = true"><Trash2 class="h-4 w-4" /></Button>
            </div>
          </div>
          <p class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
            <Badge :variant="page.status === 'published' ? 'secondary' : 'outline'">{{ t(`docs.${page.status}`) }}</Badge>
            <span>{{ page.project ? page.project.name : t('docs.workspaceWide') }}</span>
            <span>· {{ formatDateTime(page.updated_at, locale) }}<template v-if="page.updated_by">, {{ page.updated_by.name }}</template></span>
          </p>
        </header>

        <section v-if="canEdit && page.status === 'published'" class="space-y-2 rounded-lg border p-3 text-sm">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-muted-foreground">{{ t('docs.shareHint') }}</p>
            <Button variant="outline" size="sm" :disabled="form.pending.value" @click="change({ public: !page.public_token })">
              {{ t(page.public_token ? 'docs.unshare' : 'docs.share') }}
            </Button>
          </div>
          <div v-if="publicUrl" class="flex gap-2">
            <Input :model-value="publicUrl" readonly class="font-mono text-xs" :aria-label="t('docs.shared')" @focus="($event.target as HTMLInputElement).select()" />
            <Button variant="outline" size="sm" class="shrink-0 gap-1.5" @click="copyLink">
              <component :is="copied ? Check : Copy" class="h-3.5 w-3.5" />{{ copied ? t('docs.copied') : t('docs.copyLink') }}
            </Button>
          </div>
        </section>

        <MarkdownView v-if="page.body" :source="page.body" />
        <p v-else class="text-sm text-muted-foreground">{{ t('docs.empty') }}</p>
      </template>

      <ConfirmDialog
        v-model:open="deleteOpen" :title="t('docs.deleteTitle', { title: page.title })" :description="t('docs.deleteDescription')"
        :confirm-label="t('docs.delete')" :busy="form.pending.value" destructive @confirm="confirmDelete"
      />
    </template>
  </div>
</template>
