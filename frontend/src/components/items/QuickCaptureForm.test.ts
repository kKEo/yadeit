import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { PiniaColada } from '@pinia/colada'
import { createPinia, setActivePinia } from 'pinia'
import { computed } from 'vue'
import type { ItemFilters } from '@/api/types'
import i18n from '@/i18n'
import { useAuthStore } from '@/stores/auth'
import { jsonResponse, makeDetail, makeMe, makeProject, makeTestRouter, routeFetch } from '@/test/helpers'
import QuickCaptureForm from './QuickCaptureForm.vue'

vi.mock('@/queries/keys', async (original) => ({
  ...(await original<typeof import('@/queries/keys')>()),
  useSlug: () => computed(() => 'acme'),
}))

const api = makeProject({ id: 2, key: 'API', name: 'API' })

describe('QuickCaptureForm', () => {
  const fetchMock = vi.fn<typeof fetch>()
  let created = 0

  beforeEach(() => {
    created = 0
    localStorage.clear()
    fetchMock.mockReset()
    fetchMock.mockImplementation(routeFetch({
      'GET /api/v1/workspaces/acme/projects': () => jsonResponse({ data: [api, makeProject()].map(({ states: _s, labels: _l, ...p }) => p) }),
      'GET /api/v1/workspaces/acme/projects/API': () => jsonResponse({ data: api }),
      'GET /api/v1/workspaces/acme/projects/WEB': () => jsonResponse({ data: makeProject() }),
      'GET /api/v1/workspaces/acme/members': () => jsonResponse({ data: [{ id: 7, name: 'Ada', email: 'ada@example.com', role: 'owner', joined_at: '' }] }),
      'POST /api/v1/workspaces/acme/items': () => jsonResponse({ data: makeDetail({ key: `WEB-${10 + ++created}` }) }, 201),
    }))
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  async function setup(filters: ItemFilters) {
    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().user = makeMe()
    const wrapper = mount(QuickCaptureForm, {
      props: { filters },
      global: { plugins: [pinia, PiniaColada, i18n, makeTestRouter()] },
      attachTo: document.body,
    })
    await flushPromises()
    const title = () => wrapper.find<HTMLInputElement>('[data-testid="capture-title"]')
    const submit = async (text: string) => {
      await title().setValue(text)
      await wrapper.find('form').trigger('submit')
      await flushPromises()
    }
    const posts = () => fetchMock.mock.calls.filter(([, init]) => init?.method === 'POST').map(([, init]) => ({
      body: JSON.parse(String(init?.body)) as Record<string, unknown>,
      key: (init?.headers as Record<string, string>)['Idempotency-Key'],
    }))
    return { wrapper, title, submit, posts }
  }

  it('needs only a title: the first project and the server defaults do the rest', async () => {
    const { submit, posts, wrapper } = await setup({})

    await submit('  Write docs  ')

    expect(posts()[0].body).toEqual({ project: 'API', title: 'Write docs' })
    expect(posts()[0].key).toMatch(/^[0-9a-f-]{36}$/)
    expect(wrapper.emitted('created')).toHaveLength(1)
    expect(wrapper.emitted('done')).toHaveLength(1)
  })

  it('starts from the current filters: project, state, priority, assignee and label', async () => {
    const { submit, posts } = await setup({ project: 'WEB', state_group: 'started', priority: 'high', assignee: 'me', label: 'backend', q: 'ignored' })

    await submit('Fix the queue')

    expect(posts()[0].body).toEqual({
      project: 'WEB', title: 'Fix the queue', state: 4, priority: 'high', assignee: 7, labels: ['backend'],
    })
  })

  it('falls back to the project used last time', async () => {
    localStorage.setItem('yadeit_last_project:acme', 'WEB')
    const { submit, posts } = await setup({})

    await submit('Another one')

    expect(posts()[0].body.project).toBe('WEB')
  })

  it('"Create more" keeps the form open, clears the title and uses a new idempotency key', async () => {
    const { wrapper, title, submit, posts } = await setup({ project: 'WEB', priority: 'high' })

    await wrapper.find('[data-testid="capture-more"]').trigger('click')
    await submit('First')

    expect(wrapper.emitted('created')).toHaveLength(1)
    expect(wrapper.emitted('done')).toBeUndefined()
    expect(title().element.value).toBe('')

    await submit('Second')

    expect(posts().map((post) => post.body)).toEqual([
      { project: 'WEB', title: 'First', priority: 'high' },
      { project: 'WEB', title: 'Second', priority: 'high' },
    ])
    expect(posts()[0].key).not.toBe(posts()[1].key)
    expect(wrapper.emitted('created')).toHaveLength(2)
  })

  it('does not submit without a title', async () => {
    const { submit, posts } = await setup({})
    await submit('   ')
    expect(posts()).toHaveLength(0)
  })
})
