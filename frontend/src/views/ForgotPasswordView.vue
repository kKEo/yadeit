<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import * as api from '@/api/client'
import { useApiForm } from '@/composables/useApiForm'
import AuthLayout from '@/layouts/AuthLayout.vue'
import FormField from '@/components/app/FormField.vue'
import TextLink from '@/components/app/TextLink.vue'
import Card from '@/components/ui/Card.vue'
import CardHeader from '@/components/ui/CardHeader.vue'
import CardTitle from '@/components/ui/CardTitle.vue'
import CardDescription from '@/components/ui/CardDescription.vue'
import CardContent from '@/components/ui/CardContent.vue'
import CardFooter from '@/components/ui/CardFooter.vue'
import Input from '@/components/ui/Input.vue'
import Button from '@/components/ui/Button.vue'
import Alert from '@/components/ui/Alert.vue'

const { t } = useI18n()
const { pending, error, fieldErrors, submit } = useApiForm(['email'])

const email = ref('')
const sent = ref(false)
const canSubmit = computed(() => email.value.trim() !== '' && !pending.value)

async function send() {
  if (!canSubmit.value) return
  sent.value = await submit(() => api.forgotPassword(email.value.trim()))
}
</script>

<template>
  <AuthLayout>
    <Card class="shadow-lg">
      <form novalidate @submit.prevent="send">
        <CardHeader>
          <CardTitle>{{ t('forgot.title') }}</CardTitle>
          <CardDescription>{{ t('forgot.description') }}</CardDescription>
        </CardHeader>
        <CardContent class="space-y-4">
          <Alert v-if="sent" variant="success" :title="t('forgot.sent')" />
          <Alert v-else-if="error" variant="destructive" :title="error" />
          <FormField id="email" :label="t('auth.email')" :errors="fieldErrors.email">
            <Input
              id="email" v-model="email" type="email" name="email" autocomplete="username"
              :placeholder="t('auth.emailPlaceholder')"
            />
          </FormField>
        </CardContent>
        <CardFooter class="flex-col gap-3">
          <Button type="submit" class="w-full" :disabled="!canSubmit">
            {{ pending ? t('forgot.submitting') : t('forgot.submit') }}
          </Button>
          <TextLink :to="{ name: 'login' }">{{ t('auth.backToLogin') }}</TextLink>
        </CardFooter>
      </form>
    </Card>
  </AuthLayout>
</template>
