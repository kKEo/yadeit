import { useInfiniteQuery, useMutation, useQuery, useQueryCache, type QueryCache, type UseInfiniteQueryData } from '@pinia/colada'
import { computed, toValue, type MaybeRefOrGetter } from 'vue'
import { useI18n } from 'vue-i18n'
import * as api from '@/api/inbox'
import type { InboxEntry, InboxPage, UpdateInboxRequest } from '@/api/types'
import { useToastStore } from '@/stores/toast'
import { keys, useSlug } from './keys'

type InboxPages = UseInfiniteQueryData<InboxPage, string | null>

/** Rewrites (or with `null` drops) entries in every cached inbox list. */
function mapEntries(cache: QueryCache, slug: string, change: (entry: InboxEntry) => InboxEntry | null) {
  for (const entry of cache.getEntries({ key: keys.inboxLists(slug) })) {
    const data = entry.state.value.data as InboxPages | undefined
    if (!data?.pages) continue
    const pages = data.pages.map((page) => ({
      ...page,
      data: page.data.map(change).filter((e): e is InboxEntry => e !== null),
    }))
    cache.setQueryData(entry.key, { ...data, pages })
  }
}

export function useInboxList(unreadOnly: MaybeRefOrGetter<boolean>) {
  const slug = useSlug()
  const cache = useQueryCache()
  const query = useInfiniteQuery(() => {
    const workspace = slug.value
    const unread = toValue(unreadOnly)
    return {
      key: keys.inboxList(workspace, unread),
      query: async ({ pageParam }: { pageParam: string | null }) => {
        const page = await api.listInbox(workspace, { unread, cursor: pageParam })
        // Every page carries the current count: keep the badge in step for free.
        cache.setQueryData(keys.inboxCount(workspace), page.unread_count)
        return page
      },
      initialPageParam: null as string | null,
      getNextPageParam: (last: InboxPage) => last.next_cursor,
      enabled: workspace !== '',
    }
  })
  const entries = computed(() => query.data.value?.pages.flatMap((page) => page.data) ?? [])
  return { ...query, entries }
}

/** The number behind the badge on the Inbox navigation entry. */
export function useInboxUnreadCount() {
  const slug = useSlug()
  return useQuery(() => {
    const workspace = slug.value
    return {
      key: keys.inboxCount(workspace),
      query: async () => (await api.listInbox(workspace, { unread: true, limit: 1 })).unread_count,
      enabled: workspace !== '',
    }
  })
}

export function useInboxMutations() {
  const cache = useQueryCache()
  const slug = useSlug()
  const toast = useToastStore()
  const { t } = useI18n()

  const fail = (err: unknown) => {
    toast.error(err instanceof Error && err.message ? err.message : t('errors.generic'))
    void cache.invalidateQueries({ key: keys.inbox(slug.value) })
  }

  /** Mark read or unread, or snooze: a snoozed entry leaves the list until its time comes. */
  const update = useMutation({
    mutation: ({ id, ...req }: UpdateInboxRequest & { id: number }) => api.updateInboxEntry(slug.value, id, req),
    onMutate({ id, read, snoozed_until }) {
      mapEntries(cache, slug.value, (entry) => {
        if (entry.id !== id) return entry
        if (snoozed_until) return null
        return read === undefined ? entry : { ...entry, read }
      })
    },
    onSuccess(res) {
      cache.setQueryData(keys.inboxCount(slug.value), res.unread_count)
    },
    onError: fail,
  })

  const markAllRead = useMutation({
    mutation: () => api.markAllInboxRead(slug.value),
    onMutate() {
      mapEntries(cache, slug.value, (entry) => (entry.read ? entry : { ...entry, read: true }))
    },
    onSuccess(res) {
      cache.setQueryData(keys.inboxCount(slug.value), res.unread_count)
    },
    onError: fail,
  })

  const remove = useMutation({
    mutation: (id: number) => api.deleteInboxEntry(slug.value, id),
    onMutate(id) {
      mapEntries(cache, slug.value, (entry) => (entry.id === id ? null : entry))
    },
    onSuccess() {
      void cache.invalidateQueries({ key: keys.inboxCount(slug.value) })
    },
    onError: fail,
  })

  return { update, markAllRead, remove }
}
