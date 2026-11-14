<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { MY_DATA_EXPORT_PATH, deleteAccount } from '@/api/client'
import { SUPPORTED_LOCALES, isSupportedLocale, type AppLocale } from '@/i18n'
import { useApiForm } from '@/composables/useApiForm'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import { useUiStore } from '@/stores/ui'
import FormField from '@/components/app/FormField.vue'
import Alert from '@/components/ui/Alert.vue'
import Button from '@/components/ui/Button.vue'
import Dialog from '@/components/ui/Dialog.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Switch from '@/components/ui/Switch.vue'

/** Profile and preferences: name, language and whether inbox entries are also emailed. */
const { t } = useI18n()
const auth = useAuthStore()
const ui = useUiStore()
const toast = useToastStore()
const { pending, error, fieldErrors, submit, clear } = useApiForm(['name'])
const router = useRouter()
const removal = useApiForm(['password'])
const deleting = ref(false)
const password = ref('')

const name = ref('')
const language = ref<AppLocale>('en')
const emailNotifications = ref(true)

watch(() => ui.profileOpen, (open) => {
  if (!open || !auth.user) return
  clear()
  removal.clear()
  deleting.value = false
  password.value = ''
  name.value = auth.user.name
  language.value = isSupportedLocale(auth.user.locale) ? auth.user.locale : 'en'
  emailNotifications.value = auth.user.email_notifications
}, { immediate: true })

async function save() {
  const ok = await submit(() => auth.updateProfile({
    name: name.value.trim(), locale: language.value, email_notifications: emailNotifications.value,
  }))
  if (ok) {
    ui.profileOpen = false
    toast.success(t('profile.saved'))
  }
}

async function removeAccount() {
  const ok = await removal.submit(() => deleteAccount(password.value))
  if (!ok) return
  ui.profileOpen = false
  auth.reset()
  await router.push({ name: 'home' })
}
</script>

<template>
  <Dialog v-model:open="ui.profileOpen" :title="t('profile.title')" :description="auth.user?.email" :close-label="t('common.close')">
    <form id="profile-form" class="space-y-4" @submit.prevent="save">
      <Alert v-if="error" variant="destructive" :title="error" />
      <FormField id="profile-name" :label="t('register.name')" :errors="fieldErrors.name">
        <Input id="profile-name" v-model="name" maxlength="100" required autocomplete="name" />
      </FormField>
      <FormField id="profile-language" :label="t('common.language')">
        <Select
          id="profile-language" :model-value="language" :options="SUPPORTED_LOCALES.map((code) => ({ value: code, label: t(`locale.${code}`) }))"
          @update:model-value="language = $event as AppLocale"
        />
      </FormField>
      <div class="flex items-start justify-between gap-4">
        <div>
          <label for="profile-email-notifications" class="text-sm font-medium">{{ t('profile.emailNotifications') }}</label>
          <p class="text-xs text-muted-foreground">{{ t('profile.emailNotificationsHint') }}</p>
        </div>
        <Switch id="profile-email-notifications" v-model="emailNotifications" />
      </div>
    </form>

    <section class="space-y-3 border-t pt-4" :aria-label="t('profile.data.title')">
      <h3 class="text-sm font-medium">{{ t('profile.data.title') }}</h3>
      <p class="text-xs text-muted-foreground">{{ t('profile.data.exportHint') }}</p>
      <div class="flex flex-wrap gap-2">
        <a :href="MY_DATA_EXPORT_PATH" download class="inline-flex h-9 items-center rounded-md border border-input px-3 text-sm font-medium hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
          {{ t('profile.data.export') }}
        </a>
        <Button v-if="!deleting" type="button" variant="outline" size="sm" class="h-9 text-destructive" @click="deleting = true">{{ t('profile.data.delete') }}</Button>
      </div>
      <form v-if="deleting" class="space-y-3 rounded-md border border-destructive/50 p-3" @submit.prevent="removeAccount">
        <p class="text-sm">{{ t('profile.data.deleteWarning') }}</p>
        <Alert v-if="removal.error.value" variant="destructive" :title="removal.error.value" />
        <FormField id="profile-delete-password" :label="t('profile.data.password')" :errors="removal.fieldErrors.value.password">
          <Input id="profile-delete-password" v-model="password" type="password" autocomplete="current-password" required />
        </FormField>
        <div class="flex gap-2">
          <Button type="submit" variant="destructive" size="sm" :disabled="removal.pending.value || password === ''">
            {{ removal.pending.value ? t('profile.data.deleting') : t('profile.data.confirmDelete') }}
          </Button>
          <Button type="button" variant="ghost" size="sm" :disabled="removal.pending.value" @click="deleting = false">{{ t('common.cancel') }}</Button>
        </div>
      </form>
    </section>
    <template #footer>
      <Button variant="outline" class="mt-2 sm:mt-0" :disabled="pending" @click="ui.profileOpen = false">{{ t('common.cancel') }}</Button>
      <Button type="submit" form="profile-form" :disabled="pending || !name.trim()">{{ pending ? t('common.saving') : t('common.save') }}</Button>
    </template>
  </Dialog>
</template>
