<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useApiForm } from '@/composables/useApiForm'
import { isValidSlug, slugify } from '@/lib/slug'
import { useAuthStore } from '@/stores/auth'
import { useWorkspaceStore } from '@/stores/workspace'
import AuthLayout from '@/layouts/AuthLayout.vue'
import FormField from '@/components/app/FormField.vue'
import Card from '@/components/ui/Card.vue'
import CardHeader from '@/components/ui/CardHeader.vue'
import CardTitle from '@/components/ui/CardTitle.vue'
import CardDescription from '@/components/ui/CardDescription.vue'
import CardContent from '@/components/ui/CardContent.vue'
import CardFooter from '@/components/ui/CardFooter.vue'
import Input from '@/components/ui/Input.vue'
import Button from '@/components/ui/Button.vue'
import Alert from '@/components/ui/Alert.vue'

/** Onboarding (`/welcome`, no workspace yet) and "create another workspace". */
const auth = useAuthStore()
const store = useWorkspaceStore()
const router = useRouter()
const { t } = useI18n()
const { pending, error, fieldErrors, submit } = useApiForm(['name', 'slug'])

// Decided once: the list grows the moment the workspace is created.
const isFirst = store.list.length === 0

const name = ref('')
const slug = ref('')
/** Until the user edits the address, it follows the name. */
const slugEdited = ref(false)

watch(name, (value) => {
  if (!slugEdited.value) slug.value = slugify(value)
})

function onSlugInput(value: string) {
  slug.value = value
  slugEdited.value = value !== ''
  if (!slugEdited.value) slug.value = slugify(name.value)
}

const slugInvalid = computed(() => slugEdited.value && !isValidSlug(slug.value))
const canSubmit = computed(() => name.value.trim() !== '' && !slugInvalid.value && !pending.value)

async function create() {
  if (!canSubmit.value) return
  let created = ''
  const ok = await submit(async () => {
    // Without an edited address the API derives a free one from the name.
    const workspace = await store.create({
      name: name.value.trim(),
      ...(slugEdited.value ? { slug: slug.value } : {}),
    })
    created = workspace.slug
  })
  if (ok) await router.push({ name: 'workspace', params: { slug: created } })
}

async function signOut() {
  await auth.logout()
  await router.push({ name: 'login' })
}
</script>

<template>
  <AuthLayout>
    <Card class="shadow-lg">
      <form novalidate @submit.prevent="create">
        <CardHeader>
          <CardTitle>{{ isFirst ? t('welcome.title', { name: auth.user?.name ?? '' }) : t('workspace.create.title') }}</CardTitle>
          <CardDescription>{{ isFirst ? t('welcome.description') : t('workspace.create.description') }}</CardDescription>
        </CardHeader>
        <CardContent class="space-y-4">
          <Alert v-if="error" variant="destructive" :title="error" />
          <FormField id="workspace-name" :label="t('workspace.fields.name')" :errors="fieldErrors.name">
            <Input
              id="workspace-name" v-model="name" name="name" maxlength="80"
              :placeholder="t('workspace.fields.namePlaceholder')"
            />
          </FormField>
          <FormField
            id="workspace-slug" :label="t('workspace.fields.slug')"
            :hint="t('workspace.fields.slugHint', { path: `/w/${slug || '…'}` })" :errors="fieldErrors.slug"
          >
            <Input
              id="workspace-slug" :model-value="slug" name="slug" maxlength="40" autocapitalize="none"
              spellcheck="false" :aria-invalid="slugInvalid" @update:model-value="onSlugInput"
            />
            <p v-if="slugInvalid" class="text-sm text-destructive">{{ t('workspace.fields.slugRule') }}</p>
          </FormField>
        </CardContent>
        <CardFooter class="justify-between gap-2">
          <Button v-if="isFirst" variant="ghost" @click="signOut">{{ t('common.signOut') }}</Button>
          <Button v-else variant="outline" :disabled="pending" @click="router.push({ name: 'home' })">
            {{ t('common.cancel') }}
          </Button>
          <Button type="submit" :disabled="!canSubmit">
            {{ pending ? t('workspace.create.submitting') : t('workspace.create.submit') }}
          </Button>
        </CardFooter>
      </form>
    </Card>
  </AuthLayout>
</template>
