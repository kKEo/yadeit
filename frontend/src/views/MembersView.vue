<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import type { Member, WorkspaceRole } from '@/api/types'
import { useApiForm } from '@/composables/useApiForm'
import { roleAtLeast } from '@/lib/roles'
import { useAuthStore } from '@/stores/auth'
import { useWorkspaceStore } from '@/stores/workspace'
import ConfirmDialog from '@/components/app/ConfirmDialog.vue'
import InvitationsSection from '@/components/app/InvitationsSection.vue'
import MembersTable from '@/components/app/MembersTable.vue'
import Alert from '@/components/ui/Alert.vue'
import Button from '@/components/ui/Button.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import { LogOut } from '@lucide/vue'

const auth = useAuthStore()
const store = useWorkspaceStore()
const router = useRouter()
const { t } = useI18n()
// One error slot for the table actions: role violations (403) and "last owner" (409) land here.
const { pending, error, submit } = useApiForm()

const loading = ref(true)
const removing = ref<Member | null>(null)
const leaveOpen = ref(false)

const canInvite = computed(() => roleAtLeast(store.role, 'admin'))
const removeOpen = computed({
  get: () => removing.value !== null,
  set: (open) => { if (!open) removing.value = null },
})

onMounted(async () => {
  await submit(() => store.fetchMembers())
  loading.value = false
})

function changeRole(member: Member, role: WorkspaceRole) {
  return submit(() => store.changeMemberRole(member.id, role))
}

async function confirmRemove() {
  const member = removing.value
  if (!member) return
  await submit(() => store.removeMember(member.id))
  removing.value = null
}

async function confirmLeave() {
  const ok = await submit(() => store.leave())
  leaveOpen.value = false
  if (ok) await router.push({ name: 'home' })
}
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold tracking-tight">{{ t('members.title') }}</h1>
        <p class="text-sm text-muted-foreground">{{ t('members.count', store.members.length) }}</p>
      </div>
      <Button variant="outline" size="sm" :disabled="pending" @click="leaveOpen = true">
        <LogOut class="mr-2 h-4 w-4" />{{ t('members.leave') }}
      </Button>
    </div>

    <Alert v-if="error" variant="destructive" :title="error" />

    <div v-if="loading" class="space-y-2" aria-busy="true">
      <Skeleton v-for="i in 3" :key="i" class="h-12 w-full" />
    </div>
    <MembersTable
      v-else
      :members="store.members" :actor-role="store.role" :current-user-id="auth.user?.id ?? null" :busy="pending"
      @change-role="changeRole" @remove="removing = $event"
    />

    <InvitationsSection v-if="canInvite" />

    <ConfirmDialog
      v-model:open="removeOpen"
      :title="t('members.removeTitle', { name: removing?.name ?? '' })"
      :description="t('members.removeDescription')"
      :confirm-label="t('members.remove')"
      :busy="pending"
      destructive
      @confirm="confirmRemove"
    />
    <ConfirmDialog
      v-model:open="leaveOpen"
      :title="t('members.leaveTitle', { workspace: store.current?.name ?? '' })"
      :description="t('members.leaveDescription')"
      :confirm-label="t('members.leave')"
      :busy="pending"
      destructive
      @confirm="confirmLeave"
    />
  </div>
</template>
