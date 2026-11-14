<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useQueryCache } from '@pinia/colada'
import { configureAi } from '@/api/ai'
import { useAi } from '@/composables/useAi'
import { useApiForm } from '@/composables/useApiForm'
import { keys, useSlug } from '@/queries/keys'
import FormField from '@/components/app/FormField.vue'
import Alert from '@/components/ui/Alert.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import CardContent from '@/components/ui/CardContent.vue'
import CardDescription from '@/components/ui/CardDescription.vue'
import CardHeader from '@/components/ui/CardHeader.vue'
import CardTitle from '@/components/ui/CardTitle.vue'
import Input from '@/components/ui/Input.vue'
import Progress from '@/components/ui/Progress.vue'
import Switch from '@/components/ui/Switch.vue'

/** The workspace's AI switch, usage and optional own provider key; rendered for admins and owners only. */
const { t, locale } = useI18n()
const slug = useSlug()
const cache = useQueryCache()
const { status } = useAi()
const form = useApiForm(['api_key', 'monthly_token_limit'])

const apiKey = ref('')
const limit = ref('')
const saved = ref(false)
// The field holds the workspace's own choice; left empty it follows the default (and the plan), shown as the placeholder.
watch(() => status.data.value?.own_token_limit, (value) => { if (value !== undefined) limit.value = value === null ? '' : String(value) }, { immediate: true })

const number = (value: number) => new Intl.NumberFormat(locale.value).format(value)
const percent = computed(() => {
  const s = status.data.value
  return !s || s.monthly_token_limit === 0 ? 0 : Math.min(100, Math.round((s.used_tokens / s.monthly_token_limit) * 100))
})

async function apply(req: Parameters<typeof configureAi>[1]) {
  saved.value = false
  const ok = await form.submit(async () => {
    cache.setQueryData(keys.ai(slug.value), await configureAi(slug.value, req))
  })
  saved.value = ok
  return ok
}

async function save() {
  const req: Parameters<typeof configureAi>[1] = { monthly_token_limit: limit.value.trim() === '' ? null : Number(limit.value) }
  if (apiKey.value.trim() !== '') req.api_key = apiKey.value.trim()
  // The key leaves the field as soon as it is stored: it is never shown again.
  if (await apply(req)) apiKey.value = ''
}
</script>

<template>
  <Card v-if="status.data.value" data-ai-settings>
    <CardHeader>
      <CardTitle class="text-lg">{{ t('ai.settings.title') }}</CardTitle>
      <CardDescription>{{ t('ai.settings.description') }}</CardDescription>
    </CardHeader>
    <CardContent class="space-y-4">
      <Alert v-if="form.error.value" variant="destructive" :title="form.error.value" />
      <Alert v-else-if="saved" variant="success" :title="t('ai.settings.saved')" />

      <label class="flex items-center gap-3 text-sm font-medium">
        <Switch
          :model-value="status.data.value.enabled" :disabled="form.pending.value" :aria-label="t('ai.settings.enable')"
          @update:model-value="apply({ enabled: $event })"
        />
        <span aria-hidden="true">{{ t('ai.settings.enable') }}</span>
      </label>
      <p class="text-xs text-muted-foreground">{{ t('ai.settings.privacy') }}</p>
      <Alert v-if="!status.data.value.configured" variant="destructive" :title="t('ai.settings.notConfigured')" />

      <div class="space-y-1">
        <Progress :model-value="percent" class="h-1.5" />
        <p class="text-xs text-muted-foreground">
          {{ t('ai.settings.usage', { used: number(status.data.value.used_tokens), limit: number(status.data.value.monthly_token_limit) }) }}
          · {{ t('ai.settings.model', { model: status.data.value.model }) }}
        </p>
      </div>

      <form novalidate class="grid gap-4 sm:grid-cols-[1fr_12rem] sm:items-start" @submit.prevent="save">
        <FormField
          id="ai-key" :label="t('ai.settings.key')" :errors="form.fieldErrors.value.api_key"
          :hint="status.data.value.has_own_key ? t('ai.settings.keySet') : t('ai.settings.keyHint')"
        >
          <Input id="ai-key" v-model="apiKey" type="password" autocomplete="off" placeholder="sk-ant-…" />
        </FormField>
        <FormField id="ai-limit" :label="t('ai.settings.limit')" :errors="form.fieldErrors.value.monthly_token_limit">
          <Input id="ai-limit" v-model="limit" type="number" min="0" inputmode="numeric" :placeholder="status.data.value ? String(status.data.value.monthly_token_limit) : undefined" />
        </FormField>
        <div class="flex flex-wrap gap-2 sm:col-span-2">
          <Button type="submit" :disabled="form.pending.value">{{ form.pending.value ? t('ai.settings.saving') : t('ai.settings.save') }}</Button>
          <Button v-if="status.data.value.has_own_key" variant="outline" :disabled="form.pending.value" @click="apply({ api_key: null })">
            {{ t('ai.settings.removeKey') }}
          </Button>
        </div>
      </form>
    </CardContent>
  </Card>
</template>
