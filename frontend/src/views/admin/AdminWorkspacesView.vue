<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import * as api from '@/api/operator'
import type { AdminOverview, AdminWorkspace, HealthReport } from '@/api/types'
import { useApiForm } from '@/composables/useApiForm'
import { usePagedList } from '@/composables/usePagedList'
import { formatDate } from '@/lib/format'
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

/** Every workspace of the installation in outline, with suspension and the plan. */
const { t, locale } = useI18n()
const q = ref('')
const status = ref('all')
const overview = ref<AdminOverview | null>(null)
const health = ref<HealthReport | null>(null)
const healthVariants = { ok: 'success', warning: 'warning', failing: 'destructive' } as const
const action = useApiForm()
const suspending = ref<AdminWorkspace | null>(null)

const list = usePagedList<AdminWorkspace>((cursor) => api.listWorkspaces({
  q: q.value.trim(), suspended: status.value === 'all' ? null : status.value === 'suspended', cursor,
}))

const tiles = computed(() => (overview.value
  ? (['workspaces', 'suspended_workspaces', 'people', 'agents', 'items', 'signups_last_7_days'] as const)
      .map((key) => ({ key, value: overview.value![key] }))
  : []))
const plans = ['free', 'team']
const suspendOpen = computed({
  get: () => suspending.value !== null,
  set: (open) => { if (!open) suspending.value = null },
})

function replace(next: AdminWorkspace) {
  list.items.value = list.items.value.map((w) => (w.id === next.id ? next : w))
}

async function change(workspace: AdminWorkspace, changes: { suspended?: boolean; plan?: string }) {
  await action.submit(async () => {
    replace(await api.updateWorkspace(workspace.id, changes))
    overview.value = await api.getOverview()
  })
  suspending.value = null
}

onMounted(async () => {
  void list.reload()
  await action.submit(async () => { [overview.value, health.value] = await Promise.all([api.getOverview(), api.getHealth()]) })
})
</script>

<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-semibold tracking-tight">{{ t('admin.workspaces.title') }}</h1>

    <dl v-if="tiles.length" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
      <div v-for="tile in tiles" :key="tile.key" class="rounded-lg border p-3">
        <dt class="text-xs text-muted-foreground">{{ t(`admin.overview.${tile.key}`) }}</dt>
        <dd class="mt-1 text-2xl font-semibold tabular-nums">{{ tile.value }}</dd>
      </div>
    </dl>

    <section v-if="health" class="rounded-lg border p-4" :aria-label="t('admin.health.title')" data-testid="health">
      <h2 class="flex items-center gap-2 text-sm font-medium">
        {{ t('admin.health.title') }}
        <Badge :variant="healthVariants[health.status]">{{ t(`admin.health.status.${health.status}`) }}</Badge>
      </h2>
      <ul class="mt-2 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
        <li v-for="(check, name) in health.checks" :key="name" class="flex items-baseline gap-2">
          <span class="mt-1 inline-block h-2 w-2 shrink-0 rounded-full" :class="check.status === 'ok' ? 'bg-green-500' : check.status === 'warning' ? 'bg-yellow-500' : 'bg-red-500'" aria-hidden="true" />
          <span><span class="font-medium">{{ t(`admin.health.check.${name}`) }}</span> <span class="text-muted-foreground">{{ check.detail }}</span></span>
        </li>
      </ul>
    </section>

    <div class="space-y-4">
      <form class="flex flex-wrap items-center gap-2" role="search" @submit.prevent="list.reload()">
        <Input v-model="q" type="search" class="w-64" :placeholder="t('admin.workspaces.search')" :aria-label="t('admin.workspaces.search')" />
        <Select
          :model-value="status" class="w-44" :aria-label="t('admin.status.label')"
          :options="['all', 'active', 'suspended'].map((value) => ({ value, label: t(`admin.status.${value}`) }))"
          @update:model-value="status = $event; list.reload()"
        />
        <Button type="submit" variant="outline">{{ t('admin.search') }}</Button>
      </form>

      <Alert v-if="list.error.value || action.error.value" variant="destructive" :title="list.error.value ?? action.error.value ?? ''" />
      <Skeleton v-if="!list.loaded.value && list.loading.value" class="h-40 w-full" />
      <p v-else-if="list.loaded.value && list.items.value.length === 0" class="text-sm text-muted-foreground">{{ t('admin.workspaces.empty') }}</p>
      <Table v-else-if="list.items.value.length">
        <TableHeader>
          <TableRow>
            <TableHead>{{ t('admin.workspaces.name') }}</TableHead>
            <TableHead>{{ t('admin.workspaces.plan') }}</TableHead>
            <TableHead class="text-right">{{ t('admin.workspaces.members') }}</TableHead>
            <TableHead class="text-right">{{ t('admin.workspaces.items') }}</TableHead>
            <TableHead>{{ t('admin.created') }}</TableHead>
            <TableHead>{{ t('admin.status.label') }}</TableHead>
            <TableHead />
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-for="workspace in list.items.value" :key="workspace.id" data-testid="workspace-row">
            <TableCell>
              <span class="font-medium">{{ workspace.name }}</span>
              <span class="block font-mono text-xs text-muted-foreground">{{ workspace.slug }}</span>
            </TableCell>
            <TableCell>
              <Select
                :model-value="workspace.plan" class="h-8 w-28" :aria-label="t('admin.workspaces.planOf', { workspace: workspace.name })"
                :options="plans.map((value) => ({ value, label: t(`admin.plan.${value}`) }))"
                @update:model-value="change(workspace, { plan: $event })"
              />
            </TableCell>
            <TableCell class="text-right tabular-nums">{{ workspace.members }}</TableCell>
            <TableCell class="text-right tabular-nums">{{ workspace.items }}</TableCell>
            <TableCell class="whitespace-nowrap text-muted-foreground">{{ formatDate(workspace.created_at.slice(0, 10), locale) }}</TableCell>
            <TableCell>
              <Badge :variant="workspace.suspended_at ? 'destructive' : 'success'">
                {{ t(workspace.suspended_at ? 'admin.status.suspended' : 'admin.status.active') }}
              </Badge>
            </TableCell>
            <TableCell class="text-right">
              <Button v-if="workspace.suspended_at" variant="outline" size="sm" :disabled="action.pending.value" @click="change(workspace, { suspended: false })">
                {{ t('admin.restore') }}
              </Button>
              <Button v-else variant="outline" size="sm" @click="suspending = workspace">{{ t('admin.suspend') }}</Button>
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
      <Button v-if="list.hasMore()" variant="outline" size="sm" :disabled="list.loading.value" @click="list.loadMore()">{{ t('admin.more') }}</Button>
    </div>

    <ConfirmDialog
      v-model:open="suspendOpen" destructive
      :title="t('admin.workspaces.suspendTitle', { workspace: suspending?.name ?? '' })"
      :description="t('admin.workspaces.suspendDescription')"
      :confirm-label="t('admin.suspend')" :busy="action.pending.value"
      @confirm="suspending && change(suspending, { suspended: true })"
    />
  </div>
</template>
