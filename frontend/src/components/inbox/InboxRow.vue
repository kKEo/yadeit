<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { AlarmClock, Mail, MailOpen, Trash2 } from '@lucide/vue'
import type { InboxEntry } from '@/api/types'
import { formatDateTime, formatRelative } from '@/lib/format'
import { snoozeOptions } from '@/lib/snooze'
import DropdownMenu from '@/components/ui/DropdownMenu.vue'
import DropdownMenuItem from '@/components/ui/DropdownMenuItem.vue'

/** One notification: what happened, on which item, who did it and when; with its actions. */
const props = defineProps<{ entry: InboxEntry; focused: boolean }>()
const emit = defineEmits<{ open: []; focus: []; toggleRead: []; snooze: [until: string]; remove: [] }>()
const { t, locale } = useI18n()

const text = computed(() => {
  const actor = props.entry.actor?.name ?? t('activity.someone')
  if (props.entry.event === 'state_changed' && props.entry.details?.to) {
    return t('inbox.event.state_changed_to', { actor, from: props.entry.details.from ?? '—', to: props.entry.details.to })
  }
  return t(`inbox.event.${props.entry.event}`, { actor })
})

const iconButton = 'rounded p-1.5 text-muted-foreground hover:bg-accent hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring'
</script>

<template>
  <div
    :data-inbox-id="entry.id" :data-focused="focused" :data-unread="!entry.read"
    class="group flex items-center gap-3 border-b px-3 py-2 last:border-b-0 data-[focused=true]:bg-accent data-[focused=true]:shadow-[inset_2px_0_0_hsl(var(--primary))]"
  >
    <span class="h-2 w-2 shrink-0 rounded-full" :class="entry.read ? 'bg-transparent' : 'bg-blue-600'" aria-hidden="true" />
    <button
      type="button" class="min-w-0 flex-1 rounded text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      @click="emit('open')" @focus="emit('focus')"
    >
      <span class="block truncate text-sm" :class="entry.read ? 'text-muted-foreground' : 'font-medium'">
        <span v-if="!entry.read" class="sr-only">{{ t('inbox.unread') }}: </span>{{ text }}
      </span>
      <span class="flex items-center gap-2 text-xs text-muted-foreground">
        <span class="shrink-0 font-mono">{{ entry.item.key }}</span>
        <span class="truncate">{{ entry.item.title }}</span>
      </span>
    </button>
    <time :datetime="entry.created_at" :title="formatDateTime(entry.created_at, locale)" class="hidden shrink-0 text-xs text-muted-foreground sm:block">
      {{ formatRelative(entry.created_at, locale) }}
    </time>
    <div class="flex shrink-0 items-center">
      <button type="button" :class="iconButton" :aria-label="t(entry.read ? 'inbox.markUnread' : 'inbox.markRead')" :title="t(entry.read ? 'inbox.markUnread' : 'inbox.markRead')" @click="emit('toggleRead')">
        <component :is="entry.read ? Mail : MailOpen" class="h-4 w-4" />
      </button>
      <DropdownMenu align="end">
        <template #trigger>
          <button type="button" :class="iconButton" :aria-label="t('inbox.snooze')" :title="t('inbox.snooze')" data-snooze-trigger><AlarmClock class="h-4 w-4" /></button>
        </template>
        <DropdownMenuItem v-for="option in snoozeOptions()" :key="option.id" @select="emit('snooze', option.until)">
          <span class="flex-1">{{ t(`inbox.snoozeFor.${option.id}`) }}</span>
          <span class="text-xs text-muted-foreground">{{ formatDateTime(option.until, locale) }}</span>
        </DropdownMenuItem>
      </DropdownMenu>
      <button type="button" :class="iconButton" :aria-label="t('inbox.delete')" :title="t('inbox.delete')" @click="emit('remove')"><Trash2 class="h-4 w-4" /></button>
    </div>
  </div>
</template>
