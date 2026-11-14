<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import FormField from '@/components/app/FormField.vue'
import Alert from '@/components/ui/Alert.vue'
import Button from '@/components/ui/Button.vue'
import Checkbox from '@/components/ui/Checkbox.vue'
import Dialog from '@/components/ui/Dialog.vue'
import Input from '@/components/ui/Input.vue'

/** Name and sharing for a new view, or for renaming an existing one. */
interface Props {
  open: boolean
  mode: 'create' | 'edit'
  initialName?: string
  initialShared?: boolean
  canShare: boolean
  busy?: boolean
  error?: string | null
  errors?: string[]
}
const props = defineProps<Props>()
const emit = defineEmits<{ 'update:open': [value: boolean]; submit: [value: { name: string; is_shared: boolean }] }>()
const { t } = useI18n()

const name = ref('')
const shared = ref(false)

watch(() => props.open, (open) => {
  if (!open) return
  name.value = props.initialName ?? ''
  shared.value = props.initialShared ?? false
}, { immediate: true })

function submit() {
  const trimmed = name.value.trim()
  if (trimmed) emit('submit', { name: trimmed, is_shared: shared.value })
}
</script>

<template>
  <Dialog
    :open="open" :title="t(mode === 'create' ? 'views.saveTitle' : 'views.editTitle')"
    :description="mode === 'create' ? t('views.saveDescription') : undefined" :close-label="t('common.close')"
    @update:open="emit('update:open', $event)"
  >
    <form id="save-view-form" class="space-y-4" @submit.prevent="submit">
      <Alert v-if="error" variant="destructive" :title="error" />
      <FormField id="view-name" :label="t('views.name')" :errors="errors">
        <Input id="view-name" v-model="name" maxlength="80" required autofocus :placeholder="t('views.namePlaceholder')" />
      </FormField>
      <div v-if="canShare" class="flex items-center gap-2">
        <Checkbox id="view-shared" :model-value="shared" @update:model-value="shared = $event === true" />
        <label for="view-shared" class="text-sm">{{ t('views.shared') }}</label>
      </div>
    </form>
    <template #footer>
      <Button variant="outline" class="mt-2 sm:mt-0" :disabled="busy" @click="emit('update:open', false)">{{ t('common.cancel') }}</Button>
      <Button type="submit" form="save-view-form" :disabled="busy || !name.trim()">{{ busy ? t('common.saving') : t('common.save') }}</Button>
    </template>
  </Dialog>
</template>
