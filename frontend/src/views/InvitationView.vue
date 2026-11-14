<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import * as api from '@/api/client'
import { ApiError } from '@/api/client'
import type { InvitationPreview } from '@/api/types'
import { useApiForm } from '@/composables/useApiForm'
import { formatDateTime } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import AuthLayout from '@/layouts/AuthLayout.vue'
import RoleBadge from '@/components/app/RoleBadge.vue'
import TextLink from '@/components/app/TextLink.vue'
import Alert from '@/components/ui/Alert.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import CardHeader from '@/components/ui/CardHeader.vue'
import CardTitle from '@/components/ui/CardTitle.vue'
import CardDescription from '@/components/ui/CardDescription.vue'
import CardContent from '@/components/ui/CardContent.vue'
import CardFooter from '@/components/ui/CardFooter.vue'
import Skeleton from '@/components/ui/Skeleton.vue'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const { t, locale } = useI18n()
const { pending: accepting, error: acceptError, submit } = useApiForm()

const token = computed(() => String(route.params.token))
const preview = ref<InvitationPreview | null>(null)
const loading = ref(true)
/** Used, withdrawn, expired or mistyped link: the API answers 404 for all of them. */
const invalid = ref(false)
const loadError = ref<string | null>(null)

const emailMatches = computed(() =>
  auth.user !== null && preview.value !== null
  && auth.user.email.toLowerCase() === preview.value.email.toLowerCase(),
)
// Brings the person back here after signing in, with the invited address filled in.
const authQuery = computed(() => ({ redirect: route.fullPath, email: preview.value?.email ?? '' }))

onMounted(async () => {
  try {
    preview.value = await api.getInvitationPreview(token.value)
  } catch (err: unknown) {
    if (err instanceof ApiError && err.status === 404) invalid.value = true
    else loadError.value = err instanceof Error ? err.message : t('errors.generic')
  } finally {
    loading.value = false
  }
})

async function accept() {
  let slug = ''
  const ok = await submit(async () => {
    slug = (await api.acceptInvitation(token.value)).workspace.slug
    await auth.refresh()
  })
  if (ok) await router.push({ name: 'workspace', params: { slug } })
}
</script>

<template>
  <AuthLayout>
    <Card class="shadow-lg">
      <CardContent v-if="loading" class="space-y-3 pt-6" aria-busy="true">
        <Skeleton class="h-6 w-2/3" />
        <Skeleton class="w-full" />
      </CardContent>

      <template v-else-if="preview">
        <CardHeader>
          <CardTitle>{{ t('invitation.title', { workspace: preview.workspace.name }) }}</CardTitle>
          <CardDescription>{{ t('invitation.description', { email: preview.email }) }}</CardDescription>
        </CardHeader>
        <CardContent class="space-y-4">
          <dl class="grid grid-cols-[auto_1fr] items-center gap-x-4 gap-y-2 text-sm">
            <dt class="text-muted-foreground">{{ t('members.fields.role') }}</dt>
            <dd><RoleBadge :role="preview.role" /></dd>
            <dt class="text-muted-foreground">{{ t('invitation.expires') }}</dt>
            <dd>{{ formatDateTime(preview.expires_at, locale) }}</dd>
          </dl>
          <Alert v-if="acceptError" variant="destructive" :title="acceptError" />
          <Alert v-if="auth.user && !emailMatches" variant="warning" :title="t('invitation.wrongAccountTitle')">
            {{ t('invitation.wrongAccount', { current: auth.user.email, invited: preview.email }) }}
          </Alert>
          <p v-else-if="!auth.user" class="text-sm text-muted-foreground">
            {{ preview.has_account ? t('invitation.signInHint') : t('invitation.registerHint') }}
          </p>
        </CardContent>
        <CardFooter class="flex-wrap gap-2">
          <template v-if="!auth.user">
            <Button
              :variant="preview.has_account ? 'default' : 'outline'"
              @click="router.push({ name: 'login', query: authQuery })"
            >{{ t('auth.submit') }}</Button>
            <Button
              :variant="preview.has_account ? 'outline' : 'default'"
              @click="router.push({ name: 'register', query: authQuery })"
            >{{ t('register.submit') }}</Button>
          </template>
          <Button v-else-if="emailMatches" :disabled="accepting" @click="accept">
            {{ accepting ? t('invitation.accepting') : t('invitation.accept') }}
          </Button>
          <Button v-else variant="outline" @click="auth.logout()">{{ t('common.signOut') }}</Button>
        </CardFooter>
      </template>

      <template v-else>
        <CardHeader>
          <CardTitle>{{ invalid ? t('invitation.invalidTitle') : t('invitation.loadFailedTitle') }}</CardTitle>
          <CardDescription>{{ invalid ? t('invitation.invalid') : loadError }}</CardDescription>
        </CardHeader>
        <CardFooter>
          <TextLink :to="{ name: 'home' }">{{ t('common.goHome') }}</TextLink>
        </CardFooter>
      </template>
    </Card>
  </AuthLayout>
</template>
