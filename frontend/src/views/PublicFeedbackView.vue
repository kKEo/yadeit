<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ApiError } from '@/api/client'
import { getPublicForm, sendPublicFeedback } from '@/api/feedback'
import { useApiForm } from '@/composables/useApiForm'
import FormField from '@/components/app/FormField.vue'
import Alert from '@/components/ui/Alert.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import Textarea from '@/components/ui/Textarea.vue'

/** A project's feedback form for people without an account. Shows the project's name and nothing else. */
const route = useRoute()
const { t } = useI18n()
const token = String(route.params.token)
const form = useApiForm(['title', 'body', 'name', 'email'])

const loading = ref(true)
const project = ref<string | null>(null)
const sent = ref(false)
const tooFast = ref(false)
const fields = reactive({ title: '', body: '', name: '', email: '', website: '' })

onMounted(async () => {
  try {
    project.value = (await getPublicForm(token)).project
  } catch {
    project.value = null
  }
  loading.value = false
})

async function submit() {
  if (fields.title.trim().length < 3) return
  tooFast.value = false
  const ok = await form.submit(async () => {
    try {
      await sendPublicFeedback(token, {
        title: fields.title.trim(), body: fields.body.trim() || null, name: fields.name.trim() || null, email: fields.email.trim() || null,
        website: fields.website,
      })
    } catch (err: unknown) {
      if (err instanceof ApiError && err.status === 429) {
        tooFast.value = true
        return
      }
      throw err
    }
  })
  if (ok && !tooFast.value) {
    sent.value = true
    Object.assign(fields, { title: '', body: '' })
  }
}
</script>

<template>
  <main class="mx-auto max-w-xl px-4 py-10">
    <div v-if="loading" class="space-y-3" aria-busy="true"><Skeleton class="h-9 w-2/3" /><Skeleton class="h-48 w-full" /></div>
    <p v-else-if="!project" class="text-muted-foreground">{{ t('publicFeedback.closed') }}</p>

    <div v-else-if="sent" class="space-y-4">
      <Alert variant="success" :title="t('publicFeedback.thanks')" />
      <Button variant="outline" @click="sent = false">{{ t('publicFeedback.another') }}</Button>
    </div>

    <form v-else novalidate class="space-y-4" @submit.prevent="submit">
      <header class="space-y-1">
        <h1 class="text-2xl font-semibold tracking-tight">{{ t('publicFeedback.title', { project }) }}</h1>
        <p class="text-sm text-muted-foreground">{{ t('publicFeedback.intro') }}</p>
      </header>
      <Alert v-if="tooFast" variant="destructive" :title="t('publicFeedback.tooFast')" />
      <Alert v-else-if="form.error.value" variant="destructive" :title="form.error.value" />

      <FormField id="pf-title" :label="t('publicFeedback.what')" :errors="form.fieldErrors.value.title">
        <Input id="pf-title" v-model="fields.title" maxlength="200" required />
      </FormField>
      <FormField id="pf-body" :label="t('publicFeedback.details')" :errors="form.fieldErrors.value.body">
        <Textarea id="pf-body" v-model="fields.body" :rows="6" />
      </FormField>
      <div class="grid gap-4 sm:grid-cols-2">
        <FormField id="pf-name" :label="t('publicFeedback.name')" :errors="form.fieldErrors.value.name">
          <Input id="pf-name" v-model="fields.name" maxlength="100" autocomplete="name" />
        </FormField>
        <FormField id="pf-email" :label="t('publicFeedback.email')" :hint="t('publicFeedback.emailHint')" :errors="form.fieldErrors.value.email">
          <Input id="pf-email" v-model="fields.email" type="email" maxlength="200" autocomplete="email" />
        </FormField>
      </div>
      <!-- People never see or reach this field; programs that fill in everything do. -->
      <div class="absolute -left-[9999px] h-0 w-0 overflow-hidden" aria-hidden="true">
        <label for="pf-website">{{ t('publicFeedback.website') }}</label>
        <input id="pf-website" v-model="fields.website" type="text" name="website" tabindex="-1" autocomplete="off" />
      </div>
      <Button type="submit" :disabled="form.pending.value || fields.title.trim().length < 3">
        {{ form.pending.value ? t('publicFeedback.sending') : t('publicFeedback.send') }}
      </Button>
    </form>
  </main>
</template>
