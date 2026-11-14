<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import type { UpdateWorkspaceRequest } from '@/api/types'
import { useApiForm } from '@/composables/useApiForm'
import { roleAtLeast } from '@/lib/roles'
import { isValidSlug } from '@/lib/slug'
import { useWorkspaceStore } from '@/stores/workspace'
import ConfirmDialog from '@/components/app/ConfirmDialog.vue'
import FormField from '@/components/app/FormField.vue'
import AuditSection from '@/components/app/AuditSection.vue'
import AiSettingsSection from '@/components/app/AiSettingsSection.vue'
import WebhooksSection from '@/components/app/WebhooksSection.vue'
import Alert from '@/components/ui/Alert.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import CardHeader from '@/components/ui/CardHeader.vue'
import CardTitle from '@/components/ui/CardTitle.vue'
import CardDescription from '@/components/ui/CardDescription.vue'
import CardContent from '@/components/ui/CardContent.vue'
import CardFooter from '@/components/ui/CardFooter.vue'
import Input from '@/components/ui/Input.vue'
import { Trash2 } from '@lucide/vue'

const store = useWorkspaceStore()
const router = useRouter()
const { t } = useI18n()
const form = useApiForm(['name', 'slug'])
const removal = useApiForm()

// The layout remounts this view per workspace, so seeding once is enough.
const name = ref(store.current?.name ?? '')
const slug = ref(store.current?.slug ?? '')
const saved = ref(false)
const deleteOpen = ref(false)

const canEdit = computed(() => roleAtLeast(store.role, 'admin'))
const canDelete = computed(() => store.role === 'owner')
const slugInvalid = computed(() => !isValidSlug(slug.value))

const changes = computed<UpdateWorkspaceRequest>(() => {
  const req: UpdateWorkspaceRequest = {}
  if (name.value.trim() !== store.current?.name) req.name = name.value.trim()
  if (slug.value !== store.current?.slug) req.slug = slug.value
  return req
})
const canSubmit = computed(() =>
  canEdit.value && name.value.trim() !== '' && !slugInvalid.value
  && Object.keys(changes.value).length > 0 && !form.pending.value,
)

async function save() {
  if (!canSubmit.value) return
  saved.value = false
  const before = store.current?.slug
  let after = before
  const ok = await form.submit(async () => {
    after = (await store.update(changes.value)).slug
  })
  if (!ok) return
  // A new slug means a new URL; the view is recreated there.
  if (after !== before) await router.replace({ name: 'workspace-settings', params: { slug: after } })
  else saved.value = true
}

async function confirmDelete() {
  const ok = await removal.submit(() => store.remove())
  deleteOpen.value = false
  if (ok) await router.push({ name: 'home' })
}
</script>

<template>
  <div class="mx-auto max-w-3xl space-y-6">
    <h1 class="text-2xl font-semibold tracking-tight">{{ t('settings.title') }}</h1>

    <Card>
      <form novalidate @submit.prevent="save">
        <CardHeader>
          <CardTitle class="text-lg">{{ t('settings.general') }}</CardTitle>
          <CardDescription>{{ canEdit ? t('settings.generalDescription') : t('settings.readOnly') }}</CardDescription>
        </CardHeader>
        <CardContent class="space-y-4">
          <Alert v-if="form.error.value" variant="destructive" :title="form.error.value" />
          <Alert v-else-if="saved" variant="success" :title="t('settings.saved')" />
          <FormField id="settings-name" :label="t('workspace.fields.name')" :errors="form.fieldErrors.value.name">
            <Input id="settings-name" v-model="name" name="name" maxlength="80" :disabled="!canEdit" />
          </FormField>
          <FormField
            id="settings-slug" :label="t('workspace.fields.slug')"
            :hint="t('settings.slugHint', { path: `/w/${slug}` })" :errors="form.fieldErrors.value.slug"
          >
            <Input
              id="settings-slug" v-model="slug" name="slug" maxlength="40" autocapitalize="none"
              spellcheck="false" :disabled="!canEdit" :aria-invalid="slugInvalid"
            />
            <p v-if="slugInvalid" class="text-sm text-destructive">{{ t('workspace.fields.slugRule') }}</p>
          </FormField>
        </CardContent>
        <CardFooter v-if="canEdit" class="justify-end">
          <Button type="submit" :disabled="!canSubmit">
            {{ form.pending.value ? t('common.saving') : t('common.save') }}
          </Button>
        </CardFooter>
      </form>
    </Card>

    <AiSettingsSection v-if="canEdit" />

    <WebhooksSection v-if="canEdit" />

    <AuditSection v-if="canEdit" />

    <Card v-if="canDelete" class="border-destructive/50">
      <CardHeader>
        <CardTitle class="text-lg">{{ t('settings.dangerZone') }}</CardTitle>
        <CardDescription>{{ t('settings.deleteDescription') }}</CardDescription>
      </CardHeader>
      <CardContent class="space-y-4">
        <Alert v-if="removal.error.value" variant="destructive" :title="removal.error.value" />
        <Button variant="destructive" @click="deleteOpen = true">
          <Trash2 class="mr-2 h-4 w-4" />{{ t('settings.delete') }}
        </Button>
      </CardContent>
    </Card>

    <ConfirmDialog
      v-model:open="deleteOpen"
      :title="t('settings.deleteTitle', { workspace: store.current?.name ?? '' })"
      :description="t('settings.deleteConfirm')"
      :confirm-label="t('settings.delete')"
      :busy-label="t('settings.deleting')"
      :busy="removal.pending.value"
      destructive
      @confirm="confirmDelete"
    />
  </div>
</template>
