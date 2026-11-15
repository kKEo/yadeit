<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Pencil } from '@lucide/vue'
import Button from '@/components/ui/Button.vue'
import Tabs from '@/components/ui/Tabs.vue'
import Textarea from '@/components/ui/Textarea.vue'
import MarkdownView from './MarkdownView.vue'

/** The Markdown description: rendered, with a write/preview editor behind "Edit". */
const props = defineProps<{ description: string | null; canEdit: boolean }>()
const emit = defineEmits<{ save: [description: string | null] }>()
const { t } = useI18n()

const editing = ref(false)
const draft = ref('')
const tab = ref('write')

function start() {
  draft.value = props.description ?? ''
  tab.value = 'write'
  editing.value = true
}

function save() {
  const next = draft.value.trim() === '' ? null : draft.value
  if (next !== props.description) emit('save', next)
  editing.value = false
}
</script>

<template>
  <section class="space-y-2" :aria-label="t('items.fields.description')">
    <!-- The heading row is where a way of describing the item other than text will be chosen. -->
    <div class="flex min-h-7 items-center justify-between gap-2">
      <h3 class="text-sm font-medium">{{ t('items.fields.description') }}</h3>
      <Button v-if="canEdit && !editing" variant="ghost" size="sm" class="h-7 gap-1.5 px-2 text-xs text-muted-foreground" @click="start">
        <Pencil class="h-3.5 w-3.5" />{{ t('items.detail.editDescription') }}
      </Button>
    </div>
    <div v-if="!editing">
      <MarkdownView v-if="description" :source="description" />
      <p v-else class="text-sm text-muted-foreground">{{ t('items.noDescription') }}</p>
    </div>
    <form v-else class="space-y-2" @submit.prevent="save" @keydown.esc.stop="editing = false">
      <Tabs v-model="tab" :tabs="[{ value: 'write', label: t('items.detail.write') }, { value: 'preview', label: t('items.detail.preview') }]" class="h-8">
        <template #write>
          <Textarea
            v-model="draft" :rows="10" :aria-label="t('items.fields.description')" :placeholder="t('items.create.descriptionPlaceholder')"
            class="font-mono text-sm" @keydown.meta.enter.prevent="save" @keydown.ctrl.enter.prevent="save"
          />
        </template>
        <template #preview>
          <div class="min-h-[80px] rounded-md border p-3">
            <MarkdownView v-if="draft.trim()" :source="draft" />
            <p v-else class="text-sm text-muted-foreground">{{ t('items.noDescription') }}</p>
          </div>
        </template>
      </Tabs>
      <div class="flex justify-end gap-2">
        <Button variant="outline" size="sm" @click="editing = false">{{ t('common.cancel') }}</Button>
        <Button type="submit" size="sm">{{ t('common.save') }}</Button>
      </div>
    </form>
  </section>
</template>
