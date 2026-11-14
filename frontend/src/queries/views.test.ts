import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { computed } from 'vue'
import type { SavedView } from '@/api/types'
import { useViewState } from '@/composables/useViewState'
import { viewPayload, viewToState } from '@/lib/viewState'
import { jsonResponse, makeTestRouter, routeFetch, withApp } from '@/test/helpers'
import { useSavedViews, useViewMutations } from './views'

vi.mock('./keys', async (original) => ({
  ...(await original<typeof import('./keys')>()),
  useSlug: () => computed(() => 'acme'),
}))

const bugs: SavedView = {
  id: 4, name: 'My bugs', is_shared: false, owner_id: 7,
  filters: { kind: 'bug', assignee: 'me' }, layout: 'board', group_by: 'priority', sort: '-priority',
}

describe('saved views', () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    fetchMock.mockReset()
    fetchMock.mockImplementation(routeFetch({
      'GET /api/v1/workspaces/acme/views': () => jsonResponse({ data: [bugs] }),
      'POST /api/v1/workspaces/acme/views': (init) => jsonResponse({ data: { id: 5, owner_id: 7, ...JSON.parse(String(init?.body)) } }, 201),
      'PATCH /api/v1/workspaces/acme/views/4': (init) => jsonResponse({ data: { ...bugs, ...JSON.parse(String(init?.body)) } }),
    }))
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  async function setup(path: string) {
    const router = makeTestRouter()
    await router.push(path)
    const app = withApp(() => ({ views: useSavedViews(), view: useViewState(), ...useViewMutations() }), createPinia(), router)
    await flushPromises()
    return { ...app.result, router }
  }

  const sent = (method: string) => {
    const call = fetchMock.mock.calls.find(([, init]) => init?.method === method)
    return call ? JSON.parse(String(call[1]?.body)) : null
  }

  it('applying a view writes its state to the URL, keeping the open item', async () => {
    const { view, views, router } = await setup('/w/acme/items?project=WEB&item=WEB-1')
    const saved = views.data.value![0]

    await view.apply(viewToState(saved), { view: String(saved.id) })

    expect(router.currentRoute.value.query).toEqual({
      kind: 'bug', assignee: 'me', layout: 'board', group: 'priority', sort: '-priority', view: '4', item: 'WEB-1',
    })
    expect(view.state.value).toEqual(viewToState(bugs))
    expect(view.viewId.value).toBe('4')
  })

  it('saving stores the URL state under a name and adds it to the list', async () => {
    const { view, views, create } = await setup('/w/acme/items?project=WEB&open=1&group=assignee')

    await create.mutateAsync({ name: 'Web, open', is_shared: true, ...viewPayload(view.state.value) })

    expect(sent('POST')).toEqual({
      name: 'Web, open', is_shared: true, filters: { project: 'WEB', open: '1' }, layout: 'list', group_by: 'assignee', sort: '-updated_at',
    })
    expect(views.data.value?.map((v) => v.name)).toEqual(['My bugs', 'Web, open'])
  })

  it('updating the current view sends the changed state', async () => {
    const { view, views, update } = await setup('/w/acme/items?kind=bug&assignee=me&layout=board&group=priority&view=4')

    await view.setFilter('priority', 'urgent')
    await update.mutateAsync({ id: 4, ...viewPayload(view.state.value) })

    expect(sent('PATCH')).toEqual({
      filters: { priority: 'urgent', kind: 'bug', assignee: 'me' }, layout: 'board', group_by: 'priority', sort: '-updated_at',
    })
    expect(views.data.value?.[0].filters).toEqual({ priority: 'urgent', kind: 'bug', assignee: 'me' })
    // Changing a filter keeps the link to the view it started from.
    expect(view.viewId.value).toBe('4')
  })
})
