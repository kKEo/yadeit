<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { Bot } from '@lucide/vue'
import type { AuditEntry } from '@/api/types'
import { formatDateTime } from '@/lib/format'
import Table from '@/components/ui/Table.vue'
import TableBody from '@/components/ui/TableBody.vue'
import TableCell from '@/components/ui/TableCell.vue'
import TableHead from '@/components/ui/TableHead.vue'
import TableHeader from '@/components/ui/TableHeader.vue'
import TableRow from '@/components/ui/TableRow.vue'

/** Audit entries as a table; the operator also sees the workspace and the address. */
defineProps<{ entries: AuditEntry[]; operator?: boolean }>()
const { t, te, locale } = useI18n()

/** The action in words when we have them, and the code itself for anything newer than the translations. */
function actionLabel(action: string): string {
  const key = `audit.action.${action}`
  return te(key) ? t(key) : action
}

function detailText(details: AuditEntry['details']): string {
  if (!details) return ''
  return Object.entries(details)
    .map(([key, value]) => `${key}: ${Array.isArray(value) ? value.join(', ') : value === null ? '—' : String(value)}`)
    .join(' · ')
}
</script>

<template>
  <Table>
    <TableHeader>
      <TableRow>
        <TableHead>{{ t('audit.when') }}</TableHead>
        <TableHead>{{ t('audit.who') }}</TableHead>
        <TableHead>{{ t('audit.what') }}</TableHead>
        <TableHead>{{ t('audit.details') }}</TableHead>
        <TableHead v-if="operator">{{ t('audit.workspace') }}</TableHead>
        <TableHead v-if="operator">{{ t('audit.ip') }}</TableHead>
      </TableRow>
    </TableHeader>
    <TableBody>
      <TableRow v-for="entry in entries" :key="entry.id" data-testid="audit-row">
        <TableCell class="whitespace-nowrap text-muted-foreground">{{ formatDateTime(entry.created_at, locale) }}</TableCell>
        <TableCell class="whitespace-nowrap">
          <span v-if="entry.actor" class="inline-flex items-center gap-1.5">
            <Bot v-if="entry.actor.type === 'agent'" class="h-4 w-4 text-muted-foreground" :aria-label="t('audit.agent')" />
            {{ entry.actor.name }}
          </span>
          <span v-else class="text-muted-foreground">{{ t('audit.nobody') }}</span>
          <span v-if="entry.token" class="block text-xs text-muted-foreground">{{ t('audit.viaToken', { token: entry.token }) }}</span>
        </TableCell>
        <TableCell>
          <span :title="entry.action">{{ actionLabel(entry.action) }}</span>
          <span v-if="entry.subject" class="block font-mono text-xs text-muted-foreground">{{ entry.subject }}</span>
        </TableCell>
        <TableCell class="max-w-[28rem] break-words text-xs text-muted-foreground">{{ detailText(entry.details) }}</TableCell>
        <TableCell v-if="operator" class="font-mono text-xs">{{ entry.workspace ?? '—' }}</TableCell>
        <TableCell v-if="operator" class="font-mono text-xs text-muted-foreground">{{ entry.ip ?? '—' }}</TableCell>
      </TableRow>
    </TableBody>
  </Table>
</template>
