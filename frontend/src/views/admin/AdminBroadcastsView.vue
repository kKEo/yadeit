<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import * as api from '@/api/operator'
import type { Broadcast, BroadcastAudience, BroadcastPreview } from '@/api/types'
import { useApiForm } from '@/composables/useApiForm'
import { formatDateTime } from '@/lib/format'
import ConfirmDialog from '@/components/app/ConfirmDialog.vue'
import FormField from '@/components/app/FormField.vue'
import MarkdownView from '@/components/items/MarkdownView.vue'
import Alert from '@/components/ui/Alert.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import CardContent from '@/components/ui/CardContent.vue'
import CardDescription from '@/components/ui/CardDescription.vue'
import CardHeader from '@/components/ui/CardHeader.vue'
import CardTitle from '@/components/ui/CardTitle.vue'
import Checkbox from '@/components/ui/Checkbox.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Textarea from '@/components/ui/Textarea.vue'

/**
 * Announcements to the people using the installation. Sending takes three
 * steps on purpose: write, check who receives it, confirm that number.
 */
const { t, locale } = useI18n()
const form = useApiForm(['subject', 'body'])
const history = useApiForm()

const draft = reactive({ subject: '', body: '', audience: 'everyone' as BroadcastAudience, plan: 'any', important: false })
const preview = ref<BroadcastPreview | null>(null)
const confirmOpen = ref(false)
const sent = ref<Broadcast | null>(null)
const broadcasts = ref<Broadcast[]>([])

const filter = computed(() => ({ audience: draft.audience, plan: draft.plan === 'any' ? null : draft.plan, important: draft.important }))
const ready = computed(() => draft.subject.trim() !== '' && draft.body.trim() !== '')

// The number that was checked belongs to the audience it was checked for.
watch(filter, () => { preview.value = null })

async function check() {
  sent.value = null
  await form.submit(async () => { preview.value = await api.previewBroadcast(filter.value) })
}

async function send() {
  const checked = preview.value
  if (!checked) return
  try {
    await form.submit(async () => {
      sent.value = await api.sendBroadcast({ ...filter.value, subject: draft.subject.trim(), body: draft.body, confirm_recipients: checked.recipients })
    })
  } finally {
    confirmOpen.value = false
  }
  if (sent.value) {
    draft.subject = ''
    draft.body = ''
    preview.value = null
    await load()
  } else {
    // The audience may have changed in between: show the new number before anything is sent.
    preview.value = null
  }
}

async function load() {
  await history.submit(async () => { broadcasts.value = await api.listBroadcasts() })
}

function audienceLabel(b: { audience: BroadcastAudience; plan: string | null }): string {
  return b.plan ? `${t(`admin.broadcasts.audience.${b.audience}`)} · ${t(`admin.plan.${b.plan}`)}` : t(`admin.broadcasts.audience.${b.audience}`)
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-semibold tracking-tight">{{ t('admin.broadcasts.title') }}</h1>

    <Card>
      <CardHeader>
        <CardTitle class="text-lg">{{ t('admin.broadcasts.new') }}</CardTitle>
        <CardDescription>{{ t('admin.broadcasts.description') }}</CardDescription>
      </CardHeader>
      <CardContent class="space-y-4">
        <Alert v-if="form.error.value" variant="destructive" :title="form.error.value" />
        <Alert v-else-if="sent" variant="success" :title="t('admin.broadcasts.queued', { n: sent.recipients })" />
        <div class="grid gap-4 lg:grid-cols-2">
          <div class="space-y-4">
            <FormField id="broadcast-subject" :label="t('admin.broadcasts.subject')" :errors="form.fieldErrors.value.subject">
              <Input id="broadcast-subject" v-model="draft.subject" maxlength="150" />
            </FormField>
            <FormField id="broadcast-body" :label="t('admin.broadcasts.body')" :hint="t('admin.broadcasts.bodyHint')" :errors="form.fieldErrors.value.body">
              <Textarea id="broadcast-body" v-model="draft.body" :rows="10" />
            </FormField>
          </div>
          <div class="space-y-2">
            <p class="text-sm font-medium">{{ t('admin.broadcasts.preview') }}</p>
            <div class="min-h-[12rem] rounded-md border p-3" data-testid="broadcast-preview">
              <p class="mb-2 font-semibold">{{ draft.subject || t('admin.broadcasts.noSubject') }}</p>
              <MarkdownView :source="draft.body" />
            </div>
          </div>
        </div>
        <div class="flex flex-wrap items-end gap-4">
          <FormField id="broadcast-audience" :label="t('admin.broadcasts.to')">
            <Select
              id="broadcast-audience" v-model="draft.audience" class="w-56"
              :options="(['everyone', 'admins', 'owners'] as const).map((value) => ({ value, label: t(`admin.broadcasts.audience.${value}`) }))"
            />
          </FormField>
          <FormField id="broadcast-plan" :label="t('admin.workspaces.plan')">
            <Select
              id="broadcast-plan" v-model="draft.plan" class="w-40"
              :options="[{ value: 'any', label: t('admin.broadcasts.anyPlan') }, ...['free', 'team'].map((value) => ({ value, label: t(`admin.plan.${value}`) }))]"
            />
          </FormField>
          <label class="flex max-w-sm items-start gap-2 pb-2 text-sm">
            <Checkbox :model-value="draft.important" class="mt-0.5" @update:model-value="draft.important = $event === true" />
            <span>{{ t('admin.broadcasts.important') }}</span>
          </label>
        </div>
        <div class="flex flex-wrap items-center gap-3">
          <Button variant="outline" :disabled="form.pending.value" @click="check">{{ t('admin.broadcasts.check') }}</Button>
          <template v-if="preview">
            <span class="text-sm" data-testid="broadcast-count">
              {{ t('admin.broadcasts.reaches', { n: preview.recipients }, preview.recipients) }}
              <span v-if="preview.sample.length" class="text-muted-foreground">{{ t('admin.broadcasts.sample', { list: preview.sample.join(', ') }) }}</span>
            </span>
            <Button :disabled="!ready || preview.recipients === 0 || form.pending.value" @click="confirmOpen = true">{{ t('admin.broadcasts.send') }}</Button>
          </template>
        </div>
      </CardContent>
    </Card>

    <section class="space-y-3">
      <h2 class="text-lg font-semibold">{{ t('admin.broadcasts.history') }}</h2>
      <Alert v-if="history.error.value" variant="destructive" :title="history.error.value" />
      <p v-else-if="broadcasts.length === 0 && !history.pending.value" class="text-sm text-muted-foreground">{{ t('admin.broadcasts.none') }}</p>
      <ul class="space-y-2">
        <li v-for="b in broadcasts" :key="b.id" class="rounded-lg border p-3" data-testid="broadcast-row">
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <span class="font-medium">{{ b.subject }}</span>
            <span class="text-xs text-muted-foreground">{{ formatDateTime(b.created_at, locale) }}<template v-if="b.created_by"> · {{ b.created_by }}</template></span>
          </div>
          <p class="mt-1 text-sm text-muted-foreground">
            {{ audienceLabel(b) }} ·
            {{ t('admin.broadcasts.progress', { sent: b.sent, total: b.recipients }) }}<template v-if="b.waiting"> · {{ t('admin.broadcasts.waiting', { n: b.waiting }) }}</template><template v-if="b.failed"> · <span class="text-destructive">{{ t('admin.broadcasts.failed', { n: b.failed }) }}</span></template>
            · <RouterLink :to="{ name: 'admin-mail', query: { broadcast: b.id } }" class="font-medium text-primary underline-offset-4 hover:underline">{{ t('admin.broadcasts.messages') }}</RouterLink>
          </p>
        </li>
      </ul>
    </section>

    <ConfirmDialog
      v-model:open="confirmOpen"
      :title="t('admin.broadcasts.confirmTitle', { n: preview?.recipients ?? 0 }, preview?.recipients ?? 0)"
      :description="t('admin.broadcasts.confirmDescription', { subject: draft.subject })"
      :confirm-label="t('admin.broadcasts.send')" :busy="form.pending.value" @confirm="send"
    />
  </div>
</template>
