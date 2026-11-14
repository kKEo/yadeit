<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Member, WorkspaceRole } from '@/api/types'
import { formatDateTime } from '@/lib/format'
import { assignableRoles, canManageMember } from '@/lib/roles'
import RoleBadge from '@/components/app/RoleBadge.vue'
import Button from '@/components/ui/Button.vue'
import Select from '@/components/ui/Select.vue'
import Table from '@/components/ui/Table.vue'
import TableHeader from '@/components/ui/TableHeader.vue'
import TableBody from '@/components/ui/TableBody.vue'
import TableRow from '@/components/ui/TableRow.vue'
import TableHead from '@/components/ui/TableHead.vue'
import TableCell from '@/components/ui/TableCell.vue'
import { UserMinus } from '@lucide/vue'

interface Props {
  members: Member[]
  /** Role of the signed-in user; decides which controls are offered. */
  actorRole: WorkspaceRole | null
  currentUserId: number | null
  busy?: boolean
}
const props = defineProps<Props>()
const emit = defineEmits<{ changeRole: [member: Member, role: WorkspaceRole]; remove: [member: Member] }>()
const { t, locale } = useI18n()

const roleOptions = computed(() =>
  assignableRoles(props.actorRole).map((value) => ({ value, label: t(`role.${value}`) })),
)
const canManageAnyone = computed(() => roleOptions.value.length > 0)

function onRoleSelected(member: Member, value: string) {
  if (value !== member.role) emit('changeRole', member, value as WorkspaceRole)
}
</script>

<template>
  <div class="rounded-md border">
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>{{ t('members.fields.name') }}</TableHead>
          <TableHead>{{ t('members.fields.role') }}</TableHead>
          <TableHead class="hidden md:table-cell">{{ t('members.fields.joined') }}</TableHead>
          <TableHead v-if="canManageAnyone" class="text-right">{{ t('members.fields.actions') }}</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        <TableRow v-for="member in members" :key="member.id">
          <TableCell>
            <p class="font-medium">
              {{ member.name }}
              <span v-if="member.id === currentUserId" class="font-normal text-muted-foreground">{{ t('members.you') }}</span>
            </p>
            <p class="text-xs text-muted-foreground">{{ member.type === 'agent' ? t('agents.memberLabel') : member.email }}</p>
          </TableCell>
          <TableCell><RoleBadge :role="member.role" /></TableCell>
          <TableCell class="hidden whitespace-nowrap text-muted-foreground md:table-cell">
            {{ formatDateTime(member.joined_at, locale) }}
          </TableCell>
          <TableCell v-if="canManageAnyone">
            <div v-if="canManageMember(actorRole, member.role)" class="flex items-center justify-end gap-2">
              <Select
                :model-value="member.role" :options="roleOptions" :disabled="busy" class="h-9 w-36"
                :aria-label="t('members.changeRoleOf', { name: member.name })"
                @update:model-value="onRoleSelected(member, $event)"
              />
              <Button
                v-if="member.id !== currentUserId" variant="ghost" size="icon" class="h-9 w-9 text-destructive"
                :disabled="busy" :aria-label="t('members.removeNamed', { name: member.name })"
                @click="emit('remove', member)"
              >
                <UserMinus class="h-4 w-4" />
              </Button>
            </div>
          </TableCell>
        </TableRow>
      </TableBody>
    </Table>
  </div>
</template>
