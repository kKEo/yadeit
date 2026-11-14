import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { ApiError } from '@/api/client'
import { errorResponse, jsonResponse, makeMe, makeWorkspace } from '@/test/helpers'
import { useAuthStore } from './auth'
import { useWorkspaceStore } from './workspace'

describe('workspace store', () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    setActivePinia(createPinia())
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('lists the workspaces that came with me', () => {
    const auth = useAuthStore()
    const store = useWorkspaceStore()
    expect(store.list).toEqual([])

    auth.user = makeMe({ workspaces: [makeWorkspace()] })

    expect(store.list.map((w) => w.slug)).toEqual(['acme'])
  })

  it('create posts the workspace and adds it to the list, sorted by name', async () => {
    useAuthStore().user = makeMe({ workspaces: [makeWorkspace({ id: 1, name: 'Zeta', slug: 'zeta' })] })
    const created = makeWorkspace({ id: 2, name: 'Beta', slug: 'beta' })
    fetchMock.mockResolvedValue(jsonResponse({ data: created }, 201))
    const store = useWorkspaceStore()

    const result = await store.create({ name: 'Beta' })

    const [url, init] = fetchMock.mock.calls[0]
    expect(String(url)).toBe('/api/v1/workspaces')
    expect(init?.method).toBe('POST')
    expect(init?.body).toBe(JSON.stringify({ name: 'Beta' }))
    expect(result).toEqual(created)
    expect(store.list.map((w) => w.slug)).toEqual(['beta', 'zeta'])
    expect(useAuthStore().user?.workspaces).toHaveLength(2)
  })

  it('create rethrows the API error and leaves the list alone', async () => {
    useAuthStore().user = makeMe({ workspaces: [makeWorkspace()] })
    fetchMock.mockResolvedValue(
      errorResponse(422, 'validation_failed', 'The slug has already been taken.', { slug: ['The slug has already been taken.'] }),
    )
    const store = useWorkspaceStore()

    const err = await store.create({ name: 'Acme', slug: 'acme' }).catch((e: unknown) => e)

    expect(err).toBeInstanceOf(ApiError)
    expect((err as ApiError).fields.slug).toEqual(['The slug has already been taken.'])
    expect(store.list).toHaveLength(1)
  })

  it('load marks an unknown or foreign workspace as not found', async () => {
    useAuthStore().user = makeMe({ workspaces: [makeWorkspace()] })
    fetchMock.mockResolvedValue(errorResponse(404, 'not_found', 'Workspace not found.'))
    const store = useWorkspaceStore()

    await store.load('someone-elses')

    expect(String(fetchMock.mock.calls[0][0])).toBe('/api/v1/workspaces/someone-elses')
    expect(store.notFound).toBe(true)
    expect(store.loading).toBe(false)
    expect(store.list.map((w) => w.slug)).toEqual(['acme'])
  })
})
