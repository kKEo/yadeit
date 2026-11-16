import { useInfiniteQuery, useQuery } from '@pinia/colada'
import { computed, toValue, type MaybeRefOrGetter } from 'vue'
import * as api from '@/api/items'
import type { Comment, ListItemsParams } from '@/api/types'
import { keys, useSlug } from './keys'

export const LIST_PAGE_SIZE = 50
const MAX_COMMENT_PAGES = 10

/** The cursor-paged item list for a set of filters; `items` is every loaded page flattened. */
export function useItemList(params: MaybeRefOrGetter<ListItemsParams>) {
  const slug = useSlug()
  const query = useInfiniteQuery(() => {
    const current = { limit: LIST_PAGE_SIZE, ...toValue(params) }
    const workspace = slug.value
    return {
      key: keys.itemList(workspace, current),
      query: ({ pageParam }: { pageParam: string | null }) => api.listItems(workspace, { ...current, cursor: pageParam ?? undefined }),
      initialPageParam: null as string | null,
      getNextPageParam: (last: { next_cursor: string | null }) => last.next_cursor,
      enabled: workspace !== '',
    }
  })
  const items = computed(() => query.data.value?.pages.flatMap((page) => page.data) ?? [])
  return { ...query, items }
}

export function useItemDetail(itemKey: MaybeRefOrGetter<string | null>) {
  const slug = useSlug()
  return useQuery(() => {
    const key = toValue(itemKey) ?? ''
    const workspace = slug.value
    return {
      key: keys.itemDetail(workspace, key),
      query: () => api.getItem(workspace, key),
      enabled: key !== '' && workspace !== '',
    }
  })
}

/** All comments, oldest first. Long threads are read page by page up to a sane cap. */
export function useItemComments(itemKey: MaybeRefOrGetter<string>) {
  const slug = useSlug()
  return useQuery(() => {
    const key = toValue(itemKey)
    const workspace = slug.value
    return {
      key: keys.itemComments(workspace, key),
      query: async () => {
        const all: Comment[] = []
        let cursor: string | null = null
        for (let page = 0; page < MAX_COMMENT_PAGES; page++) {
          const res = await api.listComments(workspace, key, cursor)
          all.push(...res.data)
          cursor = res.next_cursor
          if (!cursor) break
        }
        return all
      },
      enabled: key !== '' && workspace !== '',
    }
  })
}

/** The latest history entries, newest first. */
export function useItemActivity(itemKey: MaybeRefOrGetter<string>, enabled: MaybeRefOrGetter<boolean> = true) {
  const slug = useSlug()
  return useQuery(() => {
    const key = toValue(itemKey)
    const workspace = slug.value
    return {
      key: keys.itemActivity(workspace, key),
      query: async () => (await api.listActivity(workspace, key)).data,
      enabled: toValue(enabled) && key !== '' && workspace !== '',
    }
  })
}

/** Agent runs on the item, newest first. */
export function useItemRuns(itemKey: MaybeRefOrGetter<string>, enabled: MaybeRefOrGetter<boolean> = true) {
  const slug = useSlug()
  return useQuery(() => {
    const key = toValue(itemKey)
    const workspace = slug.value
    return {
      key: keys.itemRuns(workspace, key),
      query: () => api.listRuns(workspace, key),
      enabled: toValue(enabled) && key !== '' && workspace !== '',
    }
  })
}

/** Workspace search for the command palette; `q` should already be debounced. The API wants at least two characters. */
export function useSearch(q: MaybeRefOrGetter<string>) {
  const slug = useSlug()
  return useQuery(() => {
    const term = toValue(q).trim()
    const workspace = slug.value
    return {
      key: keys.itemSearch(workspace, term),
      query: () => api.search(workspace, term),
      enabled: term.length >= 2 && workspace !== '',
    }
  })
}

/** The item's drawing. Only asked for when the item says it has one. */
export function useItemWhiteboard(itemKey: MaybeRefOrGetter<string>, enabled: MaybeRefOrGetter<boolean> = true) {
  const slug = useSlug()
  return useQuery(() => {
    const key = toValue(itemKey)
    const workspace = slug.value
    return {
      key: keys.itemWhiteboard(workspace, key),
      query: () => api.getWhiteboard(workspace, key),
      enabled: toValue(enabled) && key !== '' && workspace !== '',
    }
  })
}
