<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { CheckCheck, Inbox } from '@lucide/vue'
import type { InboxEntry } from '@/api/types'
import { useItemPeek } from '@/composables/useItemPeek'
import { useShortcuts } from '@/composables/useShortcuts'
import { useInboxList, useInboxMutations, useInboxUnreadCount } from '@/queries/inbox'
import EmptyState from '@/components/app/EmptyState.vue'
import InboxRow from '@/components/inbox/InboxRow.vue'
import Alert from '@/components/ui/Alert.vue'
import Button from '@/components/ui/Button.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import Tabs from '@/components/ui/Tabs.vue'

/** Notifications about items the user follows. */
const { t } = useI18n()
const { open } = useItemPeek()

const filter = ref('all')
const inbox = useInboxList(() => filter.value === 'unread')
const unread = useInboxUnreadCount()
const { update, markAllRead, remove } = useInboxMutations()

const entries = computed(() => inbox.entries.value)
const focusedId = ref<number | null>(null)
const focused = computed(() => entries.value.find((entry) => entry.id === focusedId.value) ?? null)

function moveFocus(delta: number) {
  if (entries.value.length === 0) return
  const index = entries.value.findIndex((entry) => entry.id === focusedId.value)
  const next = index === -1 ? 0 : Math.min(Math.max(index + delta, 0), entries.value.length - 1)
  focusedId.value = entries.value[next].id
}

watch(focusedId, (id) => {
  if (id !== null) void nextTick(() => document.querySelector(`[data-inbox-id="${id}"]`)?.scrollIntoView?.({ block: 'nearest' }))
})

function openEntry(entry: InboxEntry) {
  focusedId.value = entry.id
  if (!entry.read) update.mutate({ id: entry.id, read: true })
  void open(entry.item.key)
}

/** Keeps the cursor on a neighbour when the focused entry leaves the list. */
function leave(entry: InboxEntry, action: () => void) {
  const index = entries.value.findIndex((e) => e.id === entry.id)
  const neighbour = entries.value[index + 1] ?? entries.value[index - 1] ?? null
  action()
  if (focusedId.value === entry.id) focusedId.value = neighbour?.id ?? null
}

const toggleRead = (entry: InboxEntry) => update.mutate({ id: entry.id, read: !entry.read })
const snooze = (entry: InboxEntry, until: string) => leave(entry, () => update.mutate({ id: entry.id, snoozed_until: until }))
const removeEntry = (entry: InboxEntry) => leave(entry, () => remove.mutate(entry.id))

function openSnoozeMenu() {
  if (focusedId.value === null) return
  const trigger = document.querySelector<HTMLElement>(`[data-inbox-id="${focusedId.value}"] [data-snooze-trigger]`)
  trigger?.focus()
  // Radix opens its menu on pointerdown or Enter, not on a synthetic click. Not bubbling: the shortcut registry must not see it.
  trigger?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }))
}

const hasFocus = () => focused.value !== null
const group = 'shortcuts.group.inbox'
useShortcuts([
  { keys: 'j', group, description: 'shortcuts.next', handler: () => moveFocus(1) },
  { keys: 'k', group, description: 'shortcuts.previous', handler: () => moveFocus(-1) },
  { keys: 'arrowdown', group, description: 'shortcuts.next', hidden: true, handler: () => moveFocus(1) },
  { keys: 'arrowup', group, description: 'shortcuts.previous', hidden: true, handler: () => moveFocus(-1) },
  { keys: 'enter', group, description: 'shortcuts.open', when: hasFocus, handler: () => { if (focused.value) openEntry(focused.value) } },
  { keys: 'u', group, description: 'shortcuts.inbox.toggleRead', when: hasFocus, handler: () => { if (focused.value) toggleRead(focused.value) } },
  { keys: 'h', group, description: 'shortcuts.inbox.snooze', when: hasFocus, handler: openSnoozeMenu },
  { keys: 'backspace', group, description: 'shortcuts.inbox.delete', when: hasFocus, handler: () => { if (focused.value) removeEntry(focused.value) } },
])
</script>

<template>
  <div class="mx-auto max-w-4xl space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold tracking-tight">{{ t('inbox.title') }}</h1>
        <p class="text-sm text-muted-foreground">{{ t('inbox.unreadCount', unread.data.value ?? 0) }}</p>
      </div>
      <div class="flex items-center gap-2">
        <Tabs v-model="filter" :tabs="[{ value: 'all', label: t('inbox.filter.all') }, { value: 'unread', label: t('inbox.filter.unread') }]" class="h-9" />
        <Button variant="outline" size="sm" class="gap-2" :disabled="!unread.data.value || markAllRead.isLoading.value" @click="markAllRead.mutate()">
          <CheckCheck class="h-4 w-4" />{{ t('inbox.markAllRead') }}
        </Button>
      </div>
    </div>

    <Alert v-if="inbox.error.value" variant="destructive" :title="inbox.error.value.message || t('errors.generic')" />

    <div v-if="inbox.status.value === 'pending'" class="space-y-2" aria-busy="true">
      <Skeleton v-for="i in 4" :key="i" class="h-14 w-full" />
    </div>
    <EmptyState
      v-else-if="entries.length === 0 && !inbox.error.value" :icon="Inbox"
      :title="t(filter === 'unread' ? 'inbox.empty.unreadTitle' : 'inbox.empty.title')" :description="t('inbox.empty.description')"
      :action-label="filter === 'unread' ? t('inbox.empty.showAll') : undefined" @action="filter = 'all'"
    />
    <template v-else>
      <ul class="overflow-hidden rounded-lg border bg-card" :aria-label="t('inbox.title')">
        <li v-for="entry in entries" :key="entry.id">
          <InboxRow
            :entry="entry" :focused="entry.id === focusedId"
            @open="openEntry(entry)" @focus="focusedId = entry.id" @toggle-read="toggleRead(entry)"
            @snooze="snooze(entry, $event)" @remove="removeEntry(entry)"
          />
        </li>
      </ul>
      <div v-if="inbox.hasNextPage.value" class="flex justify-center">
        <Button variant="outline" size="sm" :disabled="inbox.asyncStatus.value === 'loading'" @click="inbox.loadNextPage()">{{ t('items.loadMore') }}</Button>
      </div>
    </template>
  </div>
</template>
