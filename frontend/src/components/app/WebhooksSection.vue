<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Check, Copy, Trash2 } from '@lucide/vue'
import * as api from '@/api/webhooks'
import type { NewWebhook, Webhook, WebhookDelivery } from '@/api/types'
import { useApiForm } from '@/composables/useApiForm'
import { formatDateTime } from '@/lib/format'
import ConfirmDialog from '@/components/app/ConfirmDialog.vue'
import FormField from '@/components/app/FormField.vue'
import Alert from '@/components/ui/Alert.vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import CardContent from '@/components/ui/CardContent.vue'
import CardDescription from '@/components/ui/CardDescription.vue'
import CardHeader from '@/components/ui/CardHeader.vue'
import CardTitle from '@/components/ui/CardTitle.vue'
import Checkbox from '@/components/ui/Checkbox.vue'
import Dialog from '@/components/ui/Dialog.vue'
import Input from '@/components/ui/Input.vue'

/** Webhooks of the workspace with their delivery log; rendered for admins and owners only. */
const route = useRoute()
const { t, locale } = useI18n()
const slug = computed(() => String(route.params.slug))
const list = useApiForm()
const form = useApiForm(['name', 'url', 'events'])

const hooks = ref<Webhook[]>([])
const eventNames = ref<string[]>([])
const draft = reactive({ name: '', url: '', events: [] as string[] })
const secret = ref<NewWebhook | null>(null)
const copied = ref(false)
const removing = ref<Webhook | null>(null)
const notice = ref<string | null>(null)
const logFor = ref<number | null>(null)
const log = ref<WebhookDelivery[]>([])

const canSubmit = computed(() => draft.name.trim() !== '' && draft.url.trim() !== '' && !form.pending.value)
const removeOpen = computed({
  get: () => removing.value !== null,
  set: (open) => { if (!open) removing.value = null },
})
const secretOpen = computed({
  get: () => secret.value !== null,
  set: (open) => { if (!open) closeSecret() },
})

onMounted(() => list.submit(async () => {
  const res = await api.listWebhooks(slug.value)
  hooks.value = res.data
  eventNames.value = res.events
}))

function toggleEvent(name: string, checked: boolean) {
  draft.events = checked ? [...draft.events, name] : draft.events.filter((e) => e !== name)
}

async function create() {
  if (!canSubmit.value) return
  notice.value = null
  await form.submit(async () => {
    const created = await api.createWebhook(slug.value, {
      name: draft.name.trim(), url: draft.url.trim(), events: draft.events.length ? draft.events : null,
    })
    const { secret: _secret, ...row } = created
    hooks.value = [...hooks.value, row]
    secret.value = created
    Object.assign(draft, { name: '', url: '', events: [] })
  })
}

function setActive(hook: Webhook, active: boolean) {
  notice.value = null
  return list.submit(async () => {
    const updated = await api.updateWebhook(slug.value, hook.id, { is_active: active })
    hooks.value = hooks.value.map((h) => (h.id === hook.id ? updated : h))
  })
}

async function ping(hook: Webhook) {
  notice.value = null
  const ok = await list.submit(() => api.pingWebhook(slug.value, hook.id))
  if (ok) notice.value = t('webhooks.pinged')
}

async function loadLog(hook: Webhook) {
  logFor.value = hook.id
  await list.submit(async () => { log.value = await api.listWebhookDeliveries(slug.value, hook.id) })
}

function toggleLog(hook: Webhook) {
  if (logFor.value === hook.id) {
    logFor.value = null
    return
  }
  log.value = []
  void loadLog(hook)
}

async function confirmRemove() {
  const hook = removing.value
  if (!hook) return
  await list.submit(async () => {
    await api.deleteWebhook(slug.value, hook.id)
    hooks.value = hooks.value.filter((h) => h.id !== hook.id)
    if (logFor.value === hook.id) logFor.value = null
  })
  removing.value = null
}

async function copySecret() {
  if (!secret.value) return
  try {
    await navigator.clipboard.writeText(secret.value.secret)
    copied.value = true
  } catch {
    // No clipboard access: the secret stays selectable in the field.
    copied.value = false
  }
}

function closeSecret() {
  secret.value = null
  copied.value = false
}
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle class="text-lg">{{ t('webhooks.title') }}</CardTitle>
      <CardDescription>{{ t('webhooks.description') }}</CardDescription>
    </CardHeader>
    <CardContent class="space-y-6">
      <Alert v-if="list.error.value" variant="destructive" :title="list.error.value" />
      <Alert v-else-if="notice" variant="success" :title="notice" />

      <p v-if="hooks.length === 0" class="text-sm text-muted-foreground">{{ t('webhooks.empty') }}</p>
      <ul v-else class="divide-y rounded-md border">
        <li v-for="hook in hooks" :key="hook.id" class="space-y-3 px-4 py-3" :data-webhook-id="hook.id">
          <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <div class="min-w-0">
              <p class="flex flex-wrap items-center gap-2 text-sm font-medium">
                <span class="truncate">{{ hook.name }}</span>
                <Badge :variant="hook.is_active ? 'secondary' : 'outline'">
                  {{ hook.disabled_at ? t('webhooks.switchedOff') : t(hook.is_active ? 'webhooks.active' : 'webhooks.inactive') }}
                </Badge>
              </p>
              <p class="truncate font-mono text-xs text-muted-foreground">{{ hook.url }}</p>
              <p class="text-xs text-muted-foreground">{{ hook.events ? hook.events.join(', ') : t('webhooks.allEvents') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <Button variant="outline" size="sm" :disabled="list.pending.value" @click="setActive(hook, !hook.is_active)">
                {{ t(hook.is_active ? 'webhooks.turnOff' : 'webhooks.turnOn') }}
              </Button>
              <Button variant="outline" size="sm" :disabled="list.pending.value || !hook.is_active" @click="ping(hook)">{{ t('webhooks.ping') }}</Button>
              <Button variant="outline" size="sm" :aria-expanded="logFor === hook.id" @click="toggleLog(hook)">
                {{ t(logFor === hook.id ? 'webhooks.hideLog' : 'webhooks.log') }}
              </Button>
              <Button
                variant="ghost" size="sm" :disabled="list.pending.value"
                :aria-label="t('webhooks.removeNamed', { name: hook.name })" @click="removing = hook"
              ><Trash2 class="h-4 w-4" /></Button>
            </div>
          </div>

          <div v-if="logFor === hook.id" class="space-y-2 rounded-md bg-muted/40 p-3">
            <div class="flex items-center justify-between">
              <h3 class="text-sm font-medium">{{ t('webhooks.log') }}</h3>
              <Button variant="ghost" size="sm" :disabled="list.pending.value" @click="loadLog(hook)">{{ t('webhooks.refresh') }}</Button>
            </div>
            <p v-if="log.length === 0" class="text-sm text-muted-foreground">{{ t('webhooks.logEmpty') }}</p>
            <ol v-else class="space-y-1 text-xs">
              <li v-for="row in log" :key="row.id" class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5" :data-delivery-ok="row.succeeded">
                <span :class="row.succeeded ? 'text-emerald-700 dark:text-emerald-400' : 'text-destructive'" class="font-medium">
                  {{ t(row.succeeded ? 'webhooks.ok' : 'webhooks.failed') }}
                </span>
                <span class="font-mono">{{ row.event }}</span>
                <span v-if="row.attempt > 1" class="text-muted-foreground">{{ t('webhooks.attempt', { n: row.attempt }) }}</span>
                <span v-if="row.error" class="text-muted-foreground">{{ row.error }}</span>
                <time :datetime="row.created_at" class="ml-auto text-muted-foreground">{{ formatDateTime(row.created_at, locale) }}</time>
              </li>
            </ol>
          </div>
        </li>
      </ul>

      <form novalidate class="space-y-4" @submit.prevent="create">
        <Alert v-if="form.error.value" variant="destructive" :title="form.error.value" />
        <div class="grid gap-4 sm:grid-cols-[14rem_1fr] sm:items-start">
          <FormField id="webhook-name" :label="t('webhooks.name')" :errors="form.fieldErrors.value.name">
            <Input id="webhook-name" v-model="draft.name" autocomplete="off" maxlength="80" :placeholder="t('webhooks.namePlaceholder')" />
          </FormField>
          <FormField id="webhook-url" :label="t('webhooks.url')" :errors="form.fieldErrors.value.url">
            <Input id="webhook-url" v-model="draft.url" type="url" autocomplete="off" :placeholder="t('webhooks.urlPlaceholder')" />
          </FormField>
        </div>
        <fieldset v-if="eventNames.length > 0" class="space-y-2">
          <legend class="text-sm font-medium">{{ t('webhooks.events') }}</legend>
          <p class="text-xs text-muted-foreground">{{ t('webhooks.eventsHint') }}</p>
          <div class="flex flex-wrap gap-x-5 gap-y-2">
            <label v-for="name in eventNames" :key="name" class="flex items-center gap-2 font-mono text-xs">
              <Checkbox :id="`webhook-event-${name}`" :model-value="draft.events.includes(name)" @update:model-value="toggleEvent(name, $event === true)" />
              {{ name }}
            </label>
          </div>
        </fieldset>
        <Button type="submit" :disabled="!canSubmit">{{ form.pending.value ? t('webhooks.creating') : t('webhooks.create') }}</Button>
      </form>
    </CardContent>

    <Dialog v-model:open="secretOpen" :title="t('webhooks.secretTitle')" :description="t('webhooks.secretDescription')">
      <div class="space-y-4">
        <Input
          :model-value="secret?.secret ?? ''" readonly class="font-mono text-xs" :aria-label="t('webhooks.secretTitle')"
          @focus="($event.target as HTMLInputElement).select()"
        />
        <div class="flex justify-end gap-2">
          <Button variant="outline" @click="copySecret">
            <component :is="copied ? Check : Copy" class="mr-2 h-4 w-4" />{{ copied ? t('webhooks.copied') : t('webhooks.copy') }}
          </Button>
          <Button @click="closeSecret">{{ t('webhooks.done') }}</Button>
        </div>
      </div>
    </Dialog>

    <ConfirmDialog
      v-model:open="removeOpen"
      :title="t('webhooks.removeTitle', { name: removing?.name ?? '' })"
      :description="t('webhooks.removeDescription')"
      :confirm-label="t('webhooks.remove')"
      :busy="list.pending.value"
      destructive
      @confirm="confirmRemove"
    />
  </Card>
</template>
