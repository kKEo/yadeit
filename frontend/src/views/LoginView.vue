<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import Card from '@/components/ui/Card.vue'
import CardHeader from '@/components/ui/CardHeader.vue'
import CardTitle from '@/components/ui/CardTitle.vue'
import CardDescription from '@/components/ui/CardDescription.vue'
import CardContent from '@/components/ui/CardContent.vue'
import CardFooter from '@/components/ui/CardFooter.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import Button from '@/components/ui/Button.vue'
import Alert from '@/components/ui/Alert.vue'
import LocaleSwitcher from '@/components/app/LocaleSwitcher.vue'
import { SquareKanban } from '@lucide/vue'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const { t } = useI18n()

const email = ref('')
const password = ref('')

const canSubmit = computed(() => email.value.trim() !== '' && password.value !== '' && !auth.loading)

// Only follow same-origin, path-only redirects.
function redirectTarget(): string {
  const target = route.query.redirect
  return typeof target === 'string' && target.startsWith('/') && !target.startsWith('//')
    ? target
    : '/items'
}

async function handleLogin() {
  if (!canSubmit.value) return
  const ok = await auth.login(email.value.trim(), password.value)
  if (ok) await router.push(redirectTarget())
}
</script>

<template>
  <div class="flex min-h-screen flex-col items-center justify-center bg-gradient-to-br from-slate-50 to-slate-100 px-4">
    <div class="absolute right-4 top-4"><LocaleSwitcher /></div>
    <div class="mb-8 flex items-center gap-2 text-slate-800">
      <SquareKanban class="h-7 w-7" />
      <span class="text-xl font-bold tracking-tight">{{ t('app.name') }}</span>
    </div>
    <Card class="w-full max-w-md shadow-lg">
      <form novalidate @submit.prevent="handleLogin">
        <CardHeader>
          <CardTitle>{{ t('auth.title') }}</CardTitle>
          <CardDescription>{{ t('auth.description') }}</CardDescription>
        </CardHeader>
        <CardContent>
          <div class="space-y-4">
            <Alert v-if="auth.error" variant="destructive" :title="auth.error" />
            <div class="space-y-1.5">
              <Label for="email">{{ t('auth.email') }}</Label>
              <Input
                id="email" v-model="email" type="email" name="email" autocomplete="username"
                :placeholder="t('auth.emailPlaceholder')"
              />
              <p v-if="auth.fieldErrors.email" class="text-sm text-destructive">{{ auth.fieldErrors.email[0] }}</p>
            </div>
            <div class="space-y-1.5">
              <Label for="password">{{ t('auth.password') }}</Label>
              <Input id="password" v-model="password" type="password" name="password" autocomplete="current-password" />
              <p v-if="auth.fieldErrors.password" class="text-sm text-destructive">{{ auth.fieldErrors.password[0] }}</p>
            </div>
          </div>
        </CardContent>
        <CardFooter class="flex-col gap-2">
          <Button type="submit" class="w-full" :disabled="!canSubmit">
            {{ auth.loading ? t('auth.submitting') : t('auth.submit') }}
          </Button>
        </CardFooter>
      </form>
    </Card>
  </div>
</template>
