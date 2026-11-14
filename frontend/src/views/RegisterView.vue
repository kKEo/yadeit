<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { topLevelError } from '@/composables/useApiForm'
import { authQuery, queryString, redirectTarget } from '@/lib/redirect'
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

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const { t, locale } = useI18n()

const name = ref('')
const email = ref(queryString(route.query, 'email'))
const password = ref('')
const passwordConfirmation = ref('')

const canSubmit = computed(() =>
  name.value.trim() !== '' && email.value.trim() !== '' && password.value !== ''
  && passwordConfirmation.value !== '' && !auth.loading,
)
const formError = computed(() => topLevelError(auth.error, auth.fieldErrors, ['name', 'email', 'password']))

onBeforeUnmount(() => auth.clearError())

async function handleRegister() {
  if (!canSubmit.value) return
  const ok = await auth.register({
    name: name.value.trim(),
    // The API only accepts lowercase addresses.
    email: email.value.trim().toLowerCase(),
    password: password.value,
    password_confirmation: passwordConfirmation.value,
    locale: locale.value,
  })
  if (ok) await router.push(redirectTarget(route.query))
}
</script>

<template>
  <AuthLayout>
    <Card class="shadow-lg">
      <form novalidate @submit.prevent="handleRegister">
        <CardHeader>
          <CardTitle>{{ t('register.title') }}</CardTitle>
          <CardDescription>{{ t('register.description') }}</CardDescription>
        </CardHeader>
        <CardContent class="space-y-4">
          <Alert v-if="formError" variant="destructive" :title="formError" />
          <FormField id="name" :label="t('register.name')" :errors="auth.fieldErrors.name">
            <Input id="name" v-model="name" name="name" autocomplete="name" />
          </FormField>
          <FormField id="email" :label="t('auth.email')" :errors="auth.fieldErrors.email">
            <Input
              id="email" v-model="email" type="email" name="email" autocomplete="username"
              :placeholder="t('auth.emailPlaceholder')"
            />
          </FormField>
          <FormField
            id="password" :label="t('auth.password')" :hint="t('register.passwordHint')"
            :errors="auth.fieldErrors.password"
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
          <p class="text-center text-xs text-muted-foreground">
            {{ t('register.agree') }}
            <TextLink :to="{ name: 'terms' }" class="!text-xs">{{ t('legal.terms.title') }}</TextLink> ·
            <TextLink :to="{ name: 'privacy' }" class="!text-xs">{{ t('legal.privacy.title') }}</TextLink>
          </p>
          <Button type="submit" class="w-full" :disabled="!canSubmit">
            {{ auth.loading ? t('register.submitting') : t('register.submit') }}
          </Button>
          <p class="text-sm text-muted-foreground">
            {{ t('register.haveAccount') }}
            <TextLink :to="{ name: 'login', query: authQuery(route.query) }">{{ t('auth.submit') }}</TextLink>
          </p>
        </CardFooter>
      </form>
    </Card>
  </AuthLayout>
</template>
