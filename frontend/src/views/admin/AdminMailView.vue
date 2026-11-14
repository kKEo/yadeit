<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import * as api from '@/api/operator'
import type { MailStatus, OutgoingEmail, OutgoingEmailDetail } from '@/api/types'
import { useApiForm } from '@/composables/useApiForm'
import { usePagedList } from '@/composables/usePagedList'
import { formatDateTime } from '@/lib/format'
import ConfirmDialog from '@/components/app/ConfirmDialog.vue'
import MarkdownView from '@/components/items/MarkdownView.vue'
import Alert from '@/components/ui/Alert.vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Dialog from '@/components/ui/Dialog.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import Table from '@/components/ui/Table.vue'
import TableBody from '@/components/ui/TableBody.vue'
import TableCell from '@/components/ui/TableCell.vue'
import TableHead from '@/components/ui/TableHead.vue'
import TableHeader from '@/components/ui/TableHeader.vue'
import TableRow from '@/components/ui/TableRow.vue'

/** Outgoing email: the log of what was sent and the announcements that still wait. */
const { t, locale } = useI18n()
const route = useRoute()
const q = ref('')
const status = ref('all')
// Arriving from an announcement shows only its messages.
const broadcast = ref(route.query.broadcast ? Number(route.query.broadcast) : null)
const counts = ref<Record<MailStatus, number> | null>(null)
const action = useApiForm()
const detail = ref<OutgoingEmailDetail | null>(null)
const removing = ref<OutgoingEmail | null>(null)

const list = usePagedList<OutgoingEmail>(async (cursor) => {
  const page = await api.listMail({
    q: q.value.trim(), status: status.value === 'all' ? null : (status.value as MailStatus), broadcast: broadcast.value, cursor,
  })
  counts.value = page.counts
  return page
})

const variants = { waiting: 'warning', sending: 'info', sent: 'success', failed: 'destructive' } as const
const statusOptions = computed(() => (['all', 'waiting', 'sent', 'failed'] as const).map((value) => ({
  value,
  label: value === 'all' || !counts.value ? t(`admin.mail.status.${value}`) : `${t(`admin.mail.status.${value}`)} (${counts.value[value]})`,
})))
const canSend = (email: OutgoingEmail) => email.broadcast_id !== null && (email.status === 'waiting' || email.status === 'failed')
const detailOpen = computed({ get: () => detail.value !== null, set: (open) => { if (!open) detail.value = null } })
const removeOpen = computed({ get: () => removing.value !== null, set: (open) => { if (!open) removing.value = null } })

async function open(email: OutgoingEmail) {
  await action.submit(async () => { detail.value = await api.getMail(email.id) })
}

async function sendNow(email: OutgoingEmail) {
  await action.submit(() => api.sendMailNow(email.id).then(() => undefined))
  // Reload either way: a refused message now carries the reason.
  await list.reload()
}

async function remove() {
  const email = removing.value
  if (!email) return
  const ok = await action.submit(() => api.deleteMail(email.id))
  removing.value = null
  if (ok) await list.reload()
}

function showAll() {
  broadcast.value = null
  void list.reload()
}

onMounted(() => list.reload())
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-2xl font-semibold tracking-tight">{{ t('admin.mail.title') }}</h1>
    <p class="max-w-3xl text-sm text-muted-foreground">{{ t('admin.mail.description') }}</p>

    <form class="flex flex-wrap items-center gap-2" role="search" @submit.prevent="list.reload()">
      <Input v-model="q" type="search" class="w-64" :placeholder="t('admin.mail.search')" :aria-label="t('admin.mail.search')" />
      <Select
        :model-value="status" class="w-48" :aria-label="t('admin.status.label')" :options="statusOptions"
        @update:model-value="status = $event; list.reload()"
      />
      <Button type="submit" variant="outline">{{ t('admin.search') }}</Button>
      <Button v-if="broadcast !== null" type="button" variant="ghost" @click="showAll">{{ t('admin.mail.showAll') }}</Button>
    </form>

    <Alert v-if="list.error.value || action.error.value" variant="destructive" :title="list.error.value ?? action.error.value ?? ''" />
    <Skeleton v-if="!list.loaded.value && list.loading.value" class="h-40 w-full" />
    <p v-else-if="list.loaded.value && list.items.value.length === 0" class="text-sm text-muted-foreground">{{ t('admin.mail.empty') }}</p>
    <Table v-else-if="list.items.value.length">
      <TableHeader>
        <TableRow>
          <TableHead>{{ t('admin.mail.to') }}</TableHead>
          <TableHead>{{ t('admin.mail.subject') }}</TableHead>
          <TableHead>{{ t('admin.status.label') }}</TableHead>
          <TableHead>{{ t('admin.mail.when') }}</TableHead>
          <TableHead />
        </TableRow>
      </TableHeader>
      <TableBody>
        <TableRow v-for="email in list.items.value" :key="email.id" data-testid="mail-row">
          <TableCell>
            <span v-if="email.to.name" class="block font-medium">{{ email.to.name }}</span>
            <span class="text-xs text-muted-foreground">{{ email.to.email }}</span>
          </TableCell>
          <TableCell>
            {{ email.subject }}
            <span class="block font-mono text-xs text-muted-foreground">{{ email.kind }}</span>
          </TableCell>
          <TableCell>
            <Badge :variant="variants[email.status]">{{ t(`admin.mail.status.${email.status}`) }}</Badge>
            <span v-if="email.error" class="mt-1 block max-w-xs text-xs text-destructive">{{ email.error }}</span>
          </TableCell>
          <TableCell class="whitespace-nowrap text-muted-foreground">{{ formatDateTime(email.sent_at ?? email.created_at, locale) }}</TableCell>
          <TableCell class="whitespace-nowrap text-right">
            <Button variant="ghost" size="sm" @click="open(email)">{{ t('admin.mail.details') }}</Button>
            <Button v-if="canSend(email)" variant="outline" size="sm" :disabled="action.pending.value" @click="sendNow(email)">{{ t('admin.mail.sendNow') }}</Button>
            <Button v-if="email.status !== 'sending'" variant="ghost" size="sm" @click="removing = email">{{ t('common.delete') }}</Button>
          </TableCell>
        </TableRow>
      </TableBody>
    </Table>
    <Button v-if="list.hasMore()" variant="outline" size="sm" :disabled="list.loading.value" @click="list.loadMore()">{{ t('admin.more') }}</Button>

    <Dialog v-model:open="detailOpen" :title="detail?.subject ?? ''" :close-label="t('common.close')" class="max-w-2xl">
      <dl v-if="detail" class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
        <dt class="text-muted-foreground">{{ t('admin.mail.to') }}</dt>
        <dd>{{ detail.to.name ? `${detail.to.name} <${detail.to.email}>` : detail.to.email }}</dd>
        <dt class="text-muted-foreground">{{ t('admin.status.label') }}</dt>
        <dd>{{ t(`admin.mail.status.${detail.status}`) }} · {{ t('admin.mail.attempts', { n: detail.attempts }) }}</dd>
        <template v-if="detail.error">
          <dt class="text-muted-foreground">{{ t('admin.mail.error') }}</dt>
          <dd class="text-destructive">{{ detail.error }}</dd>
        </template>
      </dl>
      <MarkdownView v-if="detail?.body" :source="detail.body" class="max-h-80 overflow-y-auto rounded-md border p-3" />
      <p v-else class="text-sm text-muted-foreground">{{ t('admin.mail.noBody') }}</p>
    </Dialog>

    <ConfirmDialog
      v-model:open="removeOpen" destructive :title="t('admin.mail.deleteTitle')"
      :description="t(removing?.status === 'sent' ? 'admin.mail.deleteSent' : 'admin.mail.deleteWaiting')"
      :confirm-label="t('common.delete')" :busy="action.pending.value" @confirm="remove"
    />
  </div>
</template>
