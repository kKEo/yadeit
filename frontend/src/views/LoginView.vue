<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { topLevelError } from '@/composables/useApiForm'
import { authQuery, followRedirect, queryString } from '@/lib/redirect'
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
const { t } = useI18n()

const email = ref(queryString(route.query, 'email'))
const password = ref('')

const canSubmit = computed(() => email.value.trim() !== '' && password.value !== '' && !auth.loading)
const formError = computed(() => topLevelError(auth.error, auth.fieldErrors, ['email', 'password']))

onBeforeUnmount(() => auth.clearError())

async function handleLogin() {
  if (!canSubmit.value) return
  const ok = await auth.login(email.value.trim(), password.value)
  if (ok) await followRedirect(router, route.query)
}
</script>

<template>
  <AuthLayout>
    <Card class="shadow-lg">
      <form novalidate @submit.prevent="handleLogin">
        <CardHeader>
          <CardTitle>{{ t('auth.title') }}</CardTitle>
          <CardDescription>{{ t('auth.description') }}</CardDescription>
        </CardHeader>
        <CardContent class="space-y-4">
          <Alert v-if="formError" variant="destructive" :title="formError" />
          <FormField id="email" :label="t('auth.email')" :errors="auth.fieldErrors.email">
            <Input
              id="email" v-model="email" type="email" name="email" autocomplete="username"
              :placeholder="t('auth.emailPlaceholder')"
            />
          </FormField>
          <FormField id="password" :label="t('auth.password')" :errors="auth.fieldErrors.password">
            <Input id="password" v-model="password" type="password" name="password" autocomplete="current-password" />
          </FormField>
          <TextLink :to="{ name: 'forgot-password' }">{{ t('auth.forgotLink') }}</TextLink>
        </CardContent>
        <CardFooter class="flex-col gap-3">
          <Button type="submit" class="w-full" :disabled="!canSubmit">
            {{ auth.loading ? t('auth.submitting') : t('auth.submit') }}
          </Button>
          <p class="text-sm text-muted-foreground">
            {{ t('auth.noAccount') }}
            <TextLink :to="{ name: 'register', query: authQuery(route.query) }">{{ t('auth.registerLink') }}</TextLink>
          </p>
        </CardFooter>
      </form>
    </Card>
  </AuthLayout>
</template>
