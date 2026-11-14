<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import * as api from '@/api/client'
import { useApiForm } from '@/composables/useApiForm'
import { queryString } from '@/lib/redirect'
import { useAuthStore } from '@/stores/auth'
import AuthLayout from '@/layouts/AuthLayout.vue'
import TextLink from '@/components/app/TextLink.vue'
import Alert from '@/components/ui/Alert.vue'
import Card from '@/components/ui/Card.vue'
import CardHeader from '@/components/ui/CardHeader.vue'
import CardTitle from '@/components/ui/CardTitle.vue'
import CardContent from '@/components/ui/CardContent.vue'
import CardFooter from '@/components/ui/CardFooter.vue'
import Skeleton from '@/components/ui/Skeleton.vue'

const route = useRoute()
const auth = useAuthStore()
const { t } = useI18n()
const { error, submit } = useApiForm()

const state = ref<'checking' | 'confirmed' | 'failed'>('checking')

onMounted(async () => {
  const params = {
    id: queryString(route.query, 'id'),
    hash: queryString(route.query, 'hash'),
    expires: queryString(route.query, 'expires'),
    signature: queryString(route.query, 'signature'),
  }
  if (Object.values(params).some((value) => value === '')) {
    state.value = 'failed'
    return
  }
  const ok = await submit(() => api.verifyEmail(params))
  state.value = ok ? 'confirmed' : 'failed'
  // Clears the "confirm your email" banner when the link was opened in a signed-in browser.
  if (ok && auth.isAuthenticated) await auth.refresh().catch(() => undefined)
})
</script>

<template>
  <AuthLayout>
    <Card class="shadow-lg">
      <CardHeader><CardTitle>{{ t('verify.title') }}</CardTitle></CardHeader>
      <CardContent>
        <div v-if="state === 'checking'" class="space-y-2" aria-busy="true">
          <p class="text-sm text-muted-foreground">{{ t('verify.checking') }}</p>
          <Skeleton class="w-2/3" />
        </div>
        <Alert v-else-if="state === 'confirmed'" variant="success" :title="t('verify.confirmed')" />
        <Alert v-else variant="destructive" :title="error ?? t('verify.failed')">
          {{ t('verify.failedHint') }}
        </Alert>
      </CardContent>
      <CardFooter v-if="state !== 'checking'">
        <TextLink :to="{ name: 'home' }">
          {{ auth.isAuthenticated ? t('verify.continue') : t('verify.continueSignedOut') }}
        </TextLink>
      </CardFooter>
    </Card>
  </AuthLayout>
</template>
