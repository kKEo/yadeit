<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ProjectDetail } from '@/api/types'
import { useApiForm } from '@/composables/useApiForm'
import { isValidProjectKey, suggestProjectKey } from '@/lib/projectKey'
import { useCreateProject } from '@/queries/workspace'
import FormField from '@/components/app/FormField.vue'
import Alert from '@/components/ui/Alert.vue'
import Button from '@/components/ui/Button.vue'
import Dialog from '@/components/ui/Dialog.vue'
import Input from '@/components/ui/Input.vue'

/** A new project: a name and the short key its items are numbered under. */
const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; created: [project: ProjectDetail] }>()
const { t } = useI18n()
const createProject = useCreateProject()
const { pending, error, fieldErrors, submit, clear } = useApiForm(['name', 'key'])

const name = ref('')
const key = ref('')
/** Once the key is typed by hand, the name no longer rewrites it. */
const keyTouched = ref(false)

watch(() => props.open, (open) => {
  if (!open) return
  clear()
  name.value = ''
  key.value = ''
  keyTouched.value = false
})

watch(name, (value) => {
  if (!keyTouched.value) key.value = suggestProjectKey(value)
})

function onKey(value: string) {
  keyTouched.value = true
  key.value = value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 10)
}

async function save() {
  let project: ProjectDetail | null = null
  const ok = await submit(async () => {
    project = await createProject.mutateAsync({ name: name.value.trim(), key: key.value })
  })
  if (ok && project) {
    emit('created', project)
    emit('update:open', false)
  }
}
</script>

<template>
  <Dialog :open="open" :title="t('projects.create.title')" :description="t('projects.create.description')" :close-label="t('common.close')" @update:open="emit('update:open', $event)">
    <form id="project-form" class="space-y-4" @submit.prevent="save">
      <Alert v-if="error" variant="destructive" :title="error" />
      <FormField id="project-name" :label="t('projects.create.name')" :errors="fieldErrors.name">
        <Input id="project-name" v-model="name" maxlength="80" required autofocus :placeholder="t('projects.create.namePlaceholder')" />
      </FormField>
      <FormField id="project-key" :label="t('projects.create.key')" :errors="fieldErrors.key" :hint="t('projects.create.keyHint', { example: `${key || 'WEB'}-1` })">
        <Input id="project-key" :model-value="key" maxlength="10" required class="font-mono uppercase" placeholder="WEB" @update:model-value="onKey" />
      </FormField>
    </form>
    <template #footer>
      <Button variant="outline" class="mt-2 sm:mt-0" :disabled="pending" @click="emit('update:open', false)">{{ t('common.cancel') }}</Button>
      <Button type="submit" form="project-form" :disabled="pending || !name.trim() || !isValidProjectKey(key)">
        {{ pending ? t('projects.create.submitting') : t('projects.create.submit') }}
      </Button>
    </template>
  </Dialog>
</template>
