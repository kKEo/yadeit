import type { QueryCache, UseInfiniteQueryData } from '@pinia/colada'
import type { Item, ItemDetail, ItemFilters, ListResponse } from '@/api/types'
import { cleanFilters, matchesFilters } from '@/lib/viewState'
import { keys } from './keys'

export type ItemPages = UseInfiniteQueryData<ListResponse<Item>, string | null>

const ROW_FIELDS = [
  'key', 'title', 'state', 'priority', 'kind', 'assignee', 'labels', 'project', 'parent',
  'due_date', 'version', 'created_at', 'updated_at', 'completed_at',
] as const satisfies readonly (keyof Item)[]

/** The list-row part of a detail response. */
export function toRow(detail: Item): Item {
  const row: Partial<Record<keyof Item, unknown>> = {}
  for (const field of ROW_FIELDS) row[field] = detail[field]
  return row as unknown as Item
}

function listEntries(cache: QueryCache, slug: string) {
  return cache.getEntries({ key: keys.itemLists(slug) })
}

function mapLists(cache: QueryCache, slug: string, mapPage: (rows: Item[], filters: ItemFilters, pageIndex: number) => Item[]) {
  for (const entry of listEntries(cache, slug)) {
    const data = entry.state.value.data as ItemPages | undefined
    if (!data?.pages) continue
    const filters = cleanFilters((entry.key[3] ?? {}) as Record<string, unknown>)
    let changed = false
    const pages = data.pages.map((page, index) => {
      const rows = mapPage(page.data, filters, index)
      if (rows === page.data) return page
      changed = true
      return { ...page, data: rows }
    })
    if (changed) cache.setQueryData(entry.key, { ...data, pages })
  }
}

/** The freshest copy of an item the cache holds: the detail, else any list row. */
export function findCachedItem(cache: QueryCache, slug: string, key: string): Item | ItemDetail | undefined {
  const detail = cache.getQueryData<ItemDetail>(keys.itemDetail(slug, key))
  if (detail) return detail
  for (const entry of listEntries(cache, slug)) {
    const data = entry.state.value.data as ItemPages | undefined
    for (const page of data?.pages ?? []) {
      const row = page.data.find((item) => item.key === key)
      if (row) return row
    }
  }
  return undefined
}

/** Applies a change to the item wherever it is cached: every list row and the detail. */
export function patchCachedItem(cache: QueryCache, slug: string, key: string, patch: Partial<ItemDetail>) {
  const rowPatch: Partial<Item> = {}
  for (const field of ROW_FIELDS) {
    if (field in patch) Object.assign(rowPatch, { [field]: patch[field] })
  }
  if (Object.keys(rowPatch).length > 0) {
    mapLists(cache, slug, (rows) => (
      rows.some((row) => row.key === key) ? rows.map((row) => (row.key === key ? { ...row, ...rowPatch } : row)) : rows
    ))
  }
  const detailKey = keys.itemDetail(slug, key)
  const detail = cache.getQueryData<ItemDetail>(detailKey)
  if (detail) cache.setQueryData(detailKey, { ...detail, ...patch })
}

/** Stores a detail response and brings the item's list rows in line with it. */
export function writeCachedDetail(cache: QueryCache, slug: string, detail: ItemDetail) {
  cache.setQueryData(keys.itemDetail(slug, detail.key), detail)
  const row = toRow(detail)
  mapLists(cache, slug, (rows) => (
    rows.some((item) => item.key === detail.key) ? rows.map((item) => (item.key === detail.key ? row : item)) : rows
  ))
}

/** Puts a new item at the top of every cached list whose filters it matches. */
export function insertCachedItem(cache: QueryCache, slug: string, detail: ItemDetail, meId: number | null) {
  const row = toRow(detail)
  mapLists(cache, slug, (rows, filters, pageIndex) => {
    if (pageIndex !== 0 || rows.some((item) => item.key === row.key) || !matchesFilters(row, filters, meId)) return rows
    return [row, ...rows]
  })
  cache.setQueryData(keys.itemDetail(slug, detail.key), detail)
}

export function removeCachedItem(cache: QueryCache, slug: string, key: string) {
  mapLists(cache, slug, (rows) => (rows.some((row) => row.key === key) ? rows.filter((row) => row.key !== key) : rows))
}
