<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import * as api from '@/api/client'
import { useApiForm } from '@/composables/useApiForm'
import { queryString } from '@/lib/redirect'
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

const route = useRoute()
const { t } = useI18n()
const { pending, error, fieldErrors, submit } = useApiForm(['email', 'password'])

const token = queryString(route.query, 'token')
const email = ref(queryString(route.query, 'email'))
const password = ref('')
const passwordConfirmation = ref('')
const done = ref(false)

const canSubmit = computed(() =>
  token !== '' && email.value.trim() !== '' && password.value !== ''
  && passwordConfirmation.value !== '' && !pending.value,
)

async function reset() {
  if (!canSubmit.value) return
  done.value = await submit(() => api.resetPassword({
    token,
    email: email.value.trim(),
    password: password.value,
    password_confirmation: passwordConfirmation.value,
  }))
}
</script>

<template>
  <AuthLayout>
    <Card class="shadow-lg">
      <template v-if="done">
        <CardHeader>
          <CardTitle>{{ t('reset.doneTitle') }}</CardTitle>
          <CardDescription>{{ t('reset.doneDescription') }}</CardDescription>
        </CardHeader>
        <CardFooter>
          <TextLink :to="{ name: 'login', query: { email: email.trim() } }">{{ t('auth.submit') }}</TextLink>
        </CardFooter>
      </template>
      <form v-else novalidate @submit.prevent="reset">
        <CardHeader>
          <CardTitle>{{ t('reset.title') }}</CardTitle>
          <CardDescription>{{ t('reset.description') }}</CardDescription>
        </CardHeader>
        <CardContent class="space-y-4">
          <Alert v-if="token === ''" variant="destructive" :title="t('reset.missingToken')" />
          <Alert v-else-if="error" variant="destructive" :title="error" />
          <FormField id="email" :label="t('auth.email')" :errors="fieldErrors.email">
            <Input id="email" v-model="email" type="email" name="email" autocomplete="username" />
          </FormField>
          <FormField
            id="password" :label="t('reset.newPassword')" :hint="t('register.passwordHint')"
            :errors="fieldErrors.password"
          >
            <Input id="password" v-model="password" type="password" name="password" autocomplete="new-password" />
          </FormField>
          <FormField id="password-confirmation" :label="t('register.passwordConfirmation')">
            <Input
              id="password-confirmation" v-model="passwordConfirmation" type="password"
              name="password_confirmation" autocomplete="new-password"
            />
          </FormField>
        </CardContent>
        <CardFooter class="flex-col gap-3">
          <Button type="submit" class="w-full" :disabled="!canSubmit">
            {{ pending ? t('common.saving') : t('reset.submit') }}
          </Button>
          <TextLink :to="{ name: 'forgot-password' }">{{ t('reset.requestNew') }}</TextLink>
        </CardFooter>
      </form>
    </Card>
  </AuthLayout>
</template>
