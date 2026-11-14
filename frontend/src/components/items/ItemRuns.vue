<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { Bot, CircleAlert, CircleCheck, CircleHelp, Lightbulb, Wrench } from '@lucide/vue'
import type { AgentRun, RunEntryType } from '@/api/types'
import { formatDateTime, formatRelative } from '@/lib/format'
import { useItemRuns } from '@/queries/items'
import Badge from '@/components/ui/Badge.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import MarkdownView from './MarkdownView.vue'

/** What agents did on this item: one block per run, newest first, with its log in order. */
const props = defineProps<{ itemKey: string }>()
const { t, locale } = useI18n()
const runs = useItemRuns(() => props.itemKey)

const icons: Record<RunEntryType, typeof Bot> = {
  thought: Lightbulb, action: Wrench, question: CircleHelp, response: CircleCheck, error: CircleAlert,
}

function statusKey(run: AgentRun): string {
  return run.stale ? 'stale' : run.status
}

function variant(run: AgentRun): 'default' | 'secondary' | 'destructive' | 'outline' {
  if (run.status === 'failed') return 'destructive'
  if (run.stale || run.status === 'stopped') return 'outline'
  return run.status === 'completed' ? 'secondary' : 'default'
}
</script>

<template>
  <div v-if="runs.status.value === 'pending'" class="space-y-2" aria-busy="true">
    <Skeleton class="h-5 w-2/3" /><Skeleton class="h-5 w-1/2" />
  </div>
  <p v-else-if="(runs.data.value ?? []).length === 0" class="text-sm text-muted-foreground">{{ t('items.runs.empty') }}</p>
  <ol v-else class="space-y-4">
    <li v-for="run in runs.data.value" :key="run.id" class="rounded-lg border" :data-run-status="statusKey(run)">
      <div class="flex flex-wrap items-center justify-between gap-2 border-b px-3 py-2">
        <p class="flex min-w-0 items-center gap-2 text-sm font-medium">
          <Bot class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
          <span class="truncate">{{ run.agent?.name ?? t('activity.someone') }}</span>
          <Badge :variant="variant(run)">{{ t(`items.runs.status.${statusKey(run)}`) }}</Badge>
        </p>
        <time :datetime="run.started_at" :title="formatDateTime(run.started_at, locale)" class="shrink-0 text-xs text-muted-foreground">
          {{ t('items.runs.started', { when: formatRelative(run.started_at, locale) }) }}
        </time>
      </div>
      <p v-if="run.entries.length === 0" class="px-3 py-2 text-sm text-muted-foreground">{{ t('items.runs.noEntries') }}</p>
      <ol v-else class="divide-y">
        <li v-for="entry in run.entries" :key="entry.id" class="flex gap-2 px-3 py-2 text-sm" :data-entry-type="entry.type">
          <component
            :is="icons[entry.type]" class="mt-0.5 h-4 w-4 shrink-0"
            :class="entry.type === 'error' ? 'text-destructive' : 'text-muted-foreground'" aria-hidden="true"
          />
          <div class="min-w-0 flex-1">
            <p class="flex items-baseline justify-between gap-3 text-xs text-muted-foreground">
              <span>{{ t(`items.runs.type.${entry.type}`) }}</span>
              <time :datetime="entry.created_at" :title="formatDateTime(entry.created_at, locale)" class="shrink-0">
                {{ formatRelative(entry.created_at, locale) }}
              </time>
            </p>
            <MarkdownView :source="entry.body" class="break-words" />
          </div>
        </li>
      </ol>
    </li>
  </ol>
</template>
