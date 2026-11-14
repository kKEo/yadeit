import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { setInfiniteQueryData, useQueryCache } from '@pinia/colada'
import { computed } from 'vue'
import type { Item, ListResponse, StateRef } from '@/api/types'
import { errorResponse, jsonResponse, makeDetail, makeItem, makeMe, withApp } from '@/test/helpers'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import { findCachedItem, type ItemPages } from './itemCache'
import { useCreateItem, useUpdateItem } from './itemMutations'
import { useItemDetail } from './items'
import { keys } from './keys'

vi.mock('./keys', async (original) => ({
  ...(await original<typeof import('./keys')>()),
  useSlug: () => computed(() => 'acme'),
}))

const done: StateRef = { id: 6, name: 'Done', group: 'completed', color: '#22c55e' }
const listKey = keys.itemList('acme', { limit: 50, sort: '-updated_at' })

describe('item mutations', () => {
  const fetchMock = vi.fn<typeof fetch>()

  /** A list with WEB-1 and an open detail for it, as when the peek panel is up over the list. */
  function setup() {
    const app = withApp(() => {
      const cache = useQueryCache()
      setInfiniteQueryData<ListResponse<Item>, Error, string | null>(cache, listKey, {
        pages: [{ data: [makeItem(), makeItem({ key: 'WEB-2', title: 'Other' })], next_cursor: null }], pageParams: [null],
      })
      cache.setQueryData(keys.itemDetail('acme', 'WEB-1'), makeDetail())
      return { cache, detail: useItemDetail(() => 'WEB-1'), ...useUpdateItem(), create: useCreateItem() }
    })
    const rows = () => (app.result.cache.getQueryData<ItemPages>(listKey)?.pages ?? []).flatMap((page) => page.data)
    const row = (key = 'WEB-1') => rows().find((item) => item.key === key)
    return { ...app.result, rows, row, toasts: () => useToastStore().toasts }
  }

  beforeEach(() => {
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('changes the cached row and detail at once and sends expected_version', async () => {
    let respond!: (res: Response) => void
    fetchMock.mockReturnValue(new Promise<Response>((resolve) => { respond = resolve }))
    const { update, row, detail } = setup()

    const saving = update('WEB-1', { state: done })
    await flushPromises()

    // Still waiting for the server, yet the UI already shows the new state.
    expect(row()?.state.name).toBe('Done')
    expect(row()?.completed_at).not.toBeNull()
    expect(detail.data.value?.state.name).toBe('Done')
    expect(row('WEB-2')?.state.name).toBe('Todo')

    const [url, init] = fetchMock.mock.calls[0]
    expect(String(url)).toBe('/api/v1/workspaces/acme/items/WEB-1')
    expect(init?.method).toBe('PATCH')
    expect(JSON.parse(String(init?.body))).toEqual({ state: 6, expected_version: 3 })

    respond(jsonResponse({ data: makeDetail({ state: done, version: 4, completed_at: '2026-01-05T00:00:00Z' }) }))
    expect(await saving).toBe(true)
    expect(row()?.version).toBe(4)
    expect(detail.data.value?.version).toBe(4)
  })

  it('rolls back and shows a toast when the request fails', async () => {
    fetchMock.mockResolvedValue(errorResponse(422, 'validation_failed', 'Unknown state.'))
    const { update, row, detail, toasts } = setup()

    expect(await update('WEB-1', { state: done, priority: 'urgent' })).toBe(false)

    expect(row()?.state.name).toBe('Todo')
    expect(row()?.priority).toBe('none')
    expect(row()?.completed_at).toBeNull()
    expect(detail.data.value?.state.name).toBe('Todo')
    expect(toasts().map((toast) => [toast.kind, toast.message])).toEqual([['error', 'Unknown state.']])
  })

  it('on 412 puts the old value back, says it changed elsewhere and re-reads the item', async () => {
    const fresh = makeDetail({ title: 'Renamed by Bob', version: 5 })
    fetchMock.mockImplementation(async (_url, init) => (
      init?.method === 'PATCH'
        ? errorResponse(412, 'version_mismatch', 'This item was changed by someone else.')
        : jsonResponse({ data: fresh })
    ))
    const { update, detail, cache, toasts } = setup()

    expect(await update('WEB-1', { priority: 'high' })).toBe(false)
    await flushPromises()

    const refetched = fetchMock.mock.calls.filter(([, init]) => (init?.method ?? 'GET') === 'GET').map(([url]) => String(url))
    expect(refetched).toContain('/api/v1/workspaces/acme/items/WEB-1')
    expect(detail.data.value).toMatchObject({ title: 'Renamed by Bob', version: 5, priority: 'none' })
    expect(toasts()).toHaveLength(1)
    expect(toasts()[0].message).toContain('WEB-1')
    expect(toasts()[0].message).toContain('changed by someone else')

    // The next attempt carries the version that was just read.
    fetchMock.mockClear()
    fetchMock.mockResolvedValue(jsonResponse({ data: { ...fresh, priority: 'high', version: 6 } }))
    expect(await update('WEB-1', { priority: 'high' })).toBe(true)
    expect(JSON.parse(String(fetchMock.mock.calls[0][1]?.body))).toEqual({ priority: 'high', expected_version: 5 })
    expect(findCachedItem(cache, 'acme', 'WEB-1')?.version).toBe(6)
  })

  it('sends changes to one item in order, each with the version the previous one produced', async () => {
    fetchMock.mockImplementation(async (_url, init) => {
      const body = JSON.parse(String(init?.body)) as { expected_version: number }
      return jsonResponse({ data: makeDetail({ version: body.expected_version + 1 }) })
    })
    const { update } = setup()

    const results = await Promise.all([update('WEB-1', { priority: 'high' }), update('WEB-1', { kind: 'bug' })])

    expect(results).toEqual([true, true])
    expect(fetchMock.mock.calls.map(([, init]) => JSON.parse(String(init?.body)))).toEqual([
      { priority: 'high', expected_version: 3 },
      { kind: 'bug', expected_version: 4 },
    ])
  })

  it('shows a created item at the top of the lists it matches, with an Idempotency-Key on the request', async () => {
    const created = makeDetail({ key: 'WEB-9', title: 'Brand new', version: 1 })
    fetchMock.mockResolvedValue(jsonResponse({ data: created }, 201))
    const { create, rows } = setup()
    useAuthStore().user = makeMe()

    await create.mutateAsync({ req: { project: 'WEB', title: 'Brand new' }, idempotencyKey: 'key-1' })

    expect(rows().map((item) => item.key)).toEqual(['WEB-9', 'WEB-1', 'WEB-2'])
    const headers = fetchMock.mock.calls[0][1]?.headers as Record<string, string>
    expect(headers['Idempotency-Key']).toBe('key-1')
  })
})
