<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { describeActivity } from '@/lib/activity'
import { formatDateTime, formatRelative } from '@/lib/format'
import { useItemActivity } from '@/queries/items'
import Skeleton from '@/components/ui/Skeleton.vue'

/** The item's history as plain sentences, newest first. */
const props = defineProps<{ itemKey: string }>()
const { t, locale } = useI18n()
const activity = useItemActivity(() => props.itemKey)

const lines = computed(() => (activity.data.value ?? []).flatMap((entry) => (
  describeActivity(entry, t).map((text, index) => ({ id: `${entry.id}-${index}`, text, at: entry.created_at }))
)))
</script>

<template>
  <div v-if="activity.status.value === 'pending'" class="space-y-2" aria-busy="true">
    <Skeleton class="h-5 w-2/3" /><Skeleton class="h-5 w-1/2" />
  </div>
  <p v-else-if="lines.length === 0" class="text-sm text-muted-foreground">{{ t('items.activity.empty') }}</p>
  <ol v-else class="space-y-2">
    <li v-for="line in lines" :key="line.id" class="flex items-baseline justify-between gap-3 text-sm">
      <span class="min-w-0 break-words">{{ line.text }}</span>
      <time :datetime="line.at" :title="formatDateTime(line.at, locale)" class="shrink-0 text-xs text-muted-foreground">
        {{ formatRelative(line.at, locale) }}
      </time>
    </li>
  </ol>
</template>
