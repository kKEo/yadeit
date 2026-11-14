<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import * as api from '@/api/operator'
import type { AdminUser } from '@/api/types'
import { useApiForm } from '@/composables/useApiForm'
import { usePagedList } from '@/composables/usePagedList'
import { formatDate } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import ConfirmDialog from '@/components/app/ConfirmDialog.vue'
import Alert from '@/components/ui/Alert.vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import Table from '@/components/ui/Table.vue'
import TableBody from '@/components/ui/TableBody.vue'
import TableCell from '@/components/ui/TableCell.vue'
import TableHead from '@/components/ui/TableHead.vue'
import TableHeader from '@/components/ui/TableHeader.vue'
import TableRow from '@/components/ui/TableRow.vue'

/** Every person with an account, with suspension. Agents are managed in their workspace. */
const { t, locale } = useI18n()
const auth = useAuthStore()
const q = ref('')
const status = ref('all')
const action = useApiForm()
const suspending = ref<AdminUser | null>(null)

const list = usePagedList<AdminUser>((cursor) => api.listUsers({
  q: q.value.trim(), suspended: status.value === 'all' ? null : status.value === 'suspended', cursor,
}))

const suspendOpen = computed({
  get: () => suspending.value !== null,
  set: (open) => { if (!open) suspending.value = null },
})

async function setSuspended(user: AdminUser, suspended: boolean) {
  await action.submit(async () => {
    const next = await api.setUserSuspended(user.id, suspended)
    list.items.value = list.items.value.map((u) => (u.id === next.id ? next : u))
  })
  suspending.value = null
}

onMounted(() => list.reload())
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-2xl font-semibold tracking-tight">{{ t('admin.users.title') }}</h1>
    <form class="flex flex-wrap items-center gap-2" role="search" @submit.prevent="list.reload()">
      <Input v-model="q" type="search" class="w-64" :placeholder="t('admin.users.search')" :aria-label="t('admin.users.search')" />
      <Select
        :model-value="status" class="w-44" :aria-label="t('admin.status.label')"
        :options="['all', 'active', 'suspended'].map((value) => ({ value, label: t(`admin.status.${value}`) }))"
        @update:model-value="status = $event; list.reload()"
      />
      <Button type="submit" variant="outline">{{ t('admin.search') }}</Button>
    </form>

    <Alert v-if="list.error.value || action.error.value" variant="destructive" :title="list.error.value ?? action.error.value ?? ''" />
    <Skeleton v-if="!list.loaded.value && list.loading.value" class="h-40 w-full" />
    <p v-else-if="list.loaded.value && list.items.value.length === 0" class="text-sm text-muted-foreground">{{ t('admin.users.empty') }}</p>
    <Table v-else-if="list.items.value.length">
      <TableHeader>
        <TableRow>
          <TableHead>{{ t('admin.users.name') }}</TableHead>
          <TableHead class="text-right">{{ t('admin.users.workspaces') }}</TableHead>
          <TableHead>{{ t('admin.created') }}</TableHead>
          <TableHead>{{ t('admin.status.label') }}</TableHead>
          <TableHead />
        </TableRow>
      </TableHeader>
      <TableBody>
        <TableRow v-for="user in list.items.value" :key="user.id" data-testid="user-row">
          <TableCell>
            <span class="inline-flex flex-wrap items-center gap-2 font-medium">
              {{ user.name }}
              <Badge v-if="user.is_operator" variant="info">{{ t('admin.users.operator') }}</Badge>
              <Badge v-if="!user.email_verified" variant="warning">{{ t('admin.users.unverified') }}</Badge>
            </span>
            <span class="block text-xs text-muted-foreground">{{ user.email }}</span>
          </TableCell>
          <TableCell class="text-right tabular-nums">{{ user.workspaces }}</TableCell>
          <TableCell class="whitespace-nowrap text-muted-foreground">{{ formatDate(user.created_at.slice(0, 10), locale) }}</TableCell>
          <TableCell>
            <Badge :variant="user.suspended_at ? 'destructive' : 'success'">
              {{ t(user.suspended_at ? 'admin.status.suspended' : 'admin.status.active') }}
            </Badge>
          </TableCell>
          <TableCell class="text-right">
            <Button v-if="user.suspended_at" variant="outline" size="sm" :disabled="action.pending.value" @click="setSuspended(user, false)">
              {{ t('admin.restore') }}
            </Button>
            <Button v-else-if="user.id !== auth.user?.id" variant="outline" size="sm" @click="suspending = user">{{ t('admin.suspend') }}</Button>
          </TableCell>
        </TableRow>
      </TableBody>
    </Table>
    <Button v-if="list.hasMore()" variant="outline" size="sm" :disabled="list.loading.value" @click="list.loadMore()">{{ t('admin.more') }}</Button>

    <ConfirmDialog
      v-model:open="suspendOpen" destructive
      :title="t('admin.users.suspendTitle', { name: suspending?.name ?? '' })"
      :description="t('admin.users.suspendDescription')"
      :confirm-label="t('admin.suspend')" :busy="action.pending.value"
      @confirm="suspending && setSuspended(suspending, true)"
    />
  </div>
</template>
