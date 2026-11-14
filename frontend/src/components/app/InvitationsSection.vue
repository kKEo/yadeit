<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Invitation, WorkspaceRole } from '@/api/types'
import { useApiForm } from '@/composables/useApiForm'
import { formatDateTime } from '@/lib/format'
import { assignableRoles } from '@/lib/roles'
import { useWorkspaceStore } from '@/stores/workspace'
import FormField from '@/components/app/FormField.vue'
import RoleBadge from '@/components/app/RoleBadge.vue'
import Alert from '@/components/ui/Alert.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import CardHeader from '@/components/ui/CardHeader.vue'
import CardTitle from '@/components/ui/CardTitle.vue'
import CardDescription from '@/components/ui/CardDescription.vue'
import CardContent from '@/components/ui/CardContent.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'

/** Invite form and open invitations; rendered for admins and owners only. */
const store = useWorkspaceStore()
const { t, locale } = useI18n()
const form = useApiForm(['email', 'role'])
const list = useApiForm()

const email = ref('')
const role = ref<WorkspaceRole>('member')
const invited = ref<string | null>(null)

const roleOptions = computed(() =>
  assignableRoles(store.role).map((value) => ({ value, label: t(`role.${value}`) })),
)
const canSubmit = computed(() => email.value.trim() !== '' && !form.pending.value)

onMounted(() => list.submit(() => store.fetchInvitations()))

async function invite() {
  if (!canSubmit.value) return
  invited.value = null
  // The API only accepts lowercase addresses.
  const address = email.value.trim().toLowerCase()
  const ok = await form.submit(() => store.invite({ email: address, role: role.value }))
  if (ok) {
    invited.value = address
    email.value = ''
  }
}

function withdraw(invitation: Invitation) {
  invited.value = null
  return list.submit(() => store.withdrawInvitation(invitation.id))
}
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle class="text-lg">{{ t('invitations.title') }}</CardTitle>
      <CardDescription>{{ t('invitations.description') }}</CardDescription>
    </CardHeader>
    <CardContent class="space-y-6">
      <form novalidate class="space-y-4" @submit.prevent="invite">
        <Alert v-if="form.error.value" variant="destructive" :title="form.error.value" />
        <Alert v-else-if="invited" variant="success" :title="t('invitations.sent', { email: invited })" />
        <div class="grid gap-4 sm:grid-cols-[1fr_12rem_auto] sm:items-start">
          <FormField id="invite-email" :label="t('auth.email')" :errors="form.fieldErrors.value.email">
            <Input
              id="invite-email" v-model="email" type="email" name="email" autocomplete="off"
              :placeholder="t('invitations.emailPlaceholder')"
            />
          </FormField>
          <FormField id="invite-role" :label="t('members.fields.role')" :errors="form.fieldErrors.value.role">
            <Select
              id="invite-role" :model-value="role" :options="roleOptions"
              @update:model-value="role = $event as WorkspaceRole"
            />
          </FormField>
          <Button type="submit" class="sm:mt-[1.625rem]" :disabled="!canSubmit">
            {{ form.pending.value ? t('invitations.sending') : t('invitations.send') }}
          </Button>
        </div>
      </form>

      <div class="space-y-2">
        <h3 class="text-sm font-medium">{{ t('invitations.pending') }}</h3>
        <Alert v-if="list.error.value" variant="destructive" :title="list.error.value" />
        <p v-if="store.invitations.length === 0" class="text-sm text-muted-foreground">
          {{ t('invitations.empty') }}
        </p>
        <ul v-else class="divide-y rounded-md border">
          <li
            v-for="invitation in store.invitations" :key="invitation.id"
            class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3"
          >
            <div class="min-w-0">
              <p class="truncate text-sm font-medium">{{ invitation.email }}</p>
              <p class="text-xs text-muted-foreground">
                {{ t('invitations.expires', { date: formatDateTime(invitation.expires_at, locale) }) }}
              </p>
            </div>
            <div class="flex items-center gap-3">
              <RoleBadge :role="invitation.role" />
              <Button variant="outline" size="sm" :disabled="list.pending.value" @click="withdraw(invitation)">
                {{ t('invitations.withdraw') }}
              </Button>
            </div>
          </li>
        </ul>
      </div>
    </CardContent>
  </Card>
</template>
