<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import * as api from '@/api/client'
import { useApiForm } from '@/composables/useApiForm'
import { useAuthStore } from '@/stores/auth'
import Button from '@/components/ui/Button.vue'
import { MailWarning, X } from '@lucide/vue'

const auth = useAuthStore()
const { t } = useI18n()
const { pending, error, submit } = useApiForm()

const dismissed = ref(false)
const sent = ref(false)

async function resend() {
  sent.value = await submit(() => api.resendVerificationEmail())
}
</script>

<template>
  <div
    v-if="auth.user && !auth.user.email_verified && !dismissed"
    role="status" class="border-b border-yellow-500/50 bg-yellow-50 text-yellow-800"
  >
    <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-3 gap-y-2 px-4 py-2 text-sm">
      <MailWarning class="h-4 w-4 shrink-0" />
      <p class="min-w-0 flex-1">
        {{ sent ? t('verifyBanner.sent', { email: auth.user.email }) : t('verifyBanner.text', { email: auth.user.email }) }}
        <span v-if="error" class="block text-destructive">{{ error }}</span>
      </p>
      <Button v-if="!sent" variant="outline" size="sm" :disabled="pending" @click="resend">
        {{ pending ? t('verifyBanner.resending') : t('verifyBanner.resend') }}
      </Button>
      <Button variant="ghost" size="icon" class="h-8 w-8" :aria-label="t('verifyBanner.dismiss')" @click="dismissed = true">
        <X class="h-4 w-4" />
      </Button>
    </div>
  </div>
</template>
