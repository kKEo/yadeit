<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
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

const name = ref('')
const language = ref<AppLocale>('en')
const emailNotifications = ref(true)

watch(() => ui.profileOpen, (open) => {
  if (!open || !auth.user) return
  clear()
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
    <template #footer>
      <Button variant="outline" class="mt-2 sm:mt-0" :disabled="pending" @click="ui.profileOpen = false">{{ t('common.cancel') }}</Button>
      <Button type="submit" form="profile-form" :disabled="pending || !name.trim()">{{ pending ? t('common.saving') : t('common.save') }}</Button>
    </template>
  </Dialog>
</template>
