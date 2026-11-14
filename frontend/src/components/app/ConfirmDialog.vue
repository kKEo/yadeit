<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Dialog from '@/components/ui/Dialog.vue'
import Button from '@/components/ui/Button.vue'

interface Props {
  open: boolean
  title: string
  description?: string
  confirmLabel: string
  busyLabel?: string
  busy?: boolean
  destructive?: boolean
}
const props = withDefaults(defineProps<Props>(), { busy: false, destructive: false })
const emit = defineEmits<{ 'update:open': [value: boolean]; confirm: [] }>()
const { t } = useI18n()

function setOpen(value: boolean) {
  // keep the dialog up while the confirmed action is still running
  if (!value && props.busy) return
  emit('update:open', value)
}
</script>

<template>
  <Dialog
    :open="open"
    :title="title"
    :description="description"
    :close-label="t('common.close')"
    @update:open="setOpen"
  >
    <slot />
    <template #footer>
      <Button variant="outline" class="mt-2 sm:mt-0" :disabled="busy" @click="setOpen(false)">
        {{ t('common.cancel') }}
      </Button>
      <Button :variant="destructive ? 'destructive' : 'default'" :disabled="busy" @click="emit('confirm')">
        {{ busy && busyLabel ? busyLabel : confirmLabel }}
      </Button>
    </template>
  </Dialog>
</template>
