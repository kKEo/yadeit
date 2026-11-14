import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type { Router } from 'vue-router'
import type { DocPage, WorkspaceRole } from '@/api/types'
import i18n from '@/i18n'
import { useAuthStore } from '@/stores/auth'
import { errorResponse, jsonResponse, makeMe, makeTestRouter, makeWorkspace, routeFetch } from '@/test/helpers'
import DocView from './DocView.vue'
import PublicPageView from './PublicPageView.vue'

// The workspace store reads the current workspace from the app router: point it at the test router.
const holder = vi.hoisted(() => ({ router: null as Router | null }))
vi.mock('@/router', () => ({
  default: new Proxy({}, { get: (_target, prop) => Reflect.get(holder.router as object, prop) }),
}))

function makePage(overrides: Partial<DocPage> = {}): DocPage {
  return {
    id: 9, title: 'Handbook', body: '# Welcome\n\nRead **this** first.', project: null, status: 'draft', public_token: null,
    version: 3, updated_by: { id: 7, name: 'Ada' }, created_at: '2026-01-01T00:00:00Z', updated_at: '2026-01-02T00:00:00Z',
    ...overrides,
  }
}

describe('DocView', () => {
  const fetchMock = vi.fn<typeof fetch>()
  const base = '/api/v1/workspaces/acme'
  let page: DocPage
  let patchAnswer: () => Response

  async function open(role: WorkspaceRole = 'member') {
    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().user = makeMe({ workspaces: [makeWorkspace({ role })] })
    const router = makeTestRouter()
    holder.router = router
    await router.push('/w/acme/docs/9')
    const wrapper = mount(DocView, { global: { plugins: [pinia, i18n, router] }, attachTo: document.body })
    await flushPromises()
    return wrapper
  }

  function patches() {
    return fetchMock.mock.calls.filter(([, init]) => init?.method === 'PATCH').map(([, init]) => JSON.parse(String(init!.body)))
  }

  function button(wrapper: Awaited<ReturnType<typeof open>>, text: string) {
    return wrapper.findAll('button').find((b) => b.text() === text)
  }

  beforeEach(() => {
    page = makePage()
    patchAnswer = () => jsonResponse({ data: page })
    fetchMock.mockReset()
    fetchMock.mockImplementation(routeFetch({
      [`GET ${base}/pages/9`]: () => jsonResponse({ data: page }),
      [`GET ${base}/projects`]: () => jsonResponse({ data: [] }),
      [`PATCH ${base}/pages/9`]: () => patchAnswer(),
    }))
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    document.body.innerHTML = ''
  })

  it('renders the page as Markdown with its state', async () => {
    const wrapper = await open()

    expect(wrapper.find('h1').text()).toBe('Handbook')
    expect(wrapper.find('.markdown strong').text()).toBe('this')
    expect(wrapper.text()).toContain('Draft')
    // A draft cannot be shared, so the option is not offered.
    expect(button(wrapper, 'Share by link')).toBeUndefined()
  })

  it('publishes and shares with the version it has on screen', async () => {
    const wrapper = await open()

    patchAnswer = () => jsonResponse({ data: (page = makePage({ status: 'published', version: 4 })) })
    await button(wrapper, 'Publish')!.trigger('click')
    await flushPromises()

    patchAnswer = () => jsonResponse({ data: (page = makePage({ status: 'published', version: 5, public_token: 'tok123' })) })
    await button(wrapper, 'Share by link')!.trigger('click')
    await flushPromises()

    expect(patches()).toEqual([{ status: 'published', expected_version: 3 }, { public: true, expected_version: 4 }])
    expect((wrapper.find('input[readonly]').element as HTMLInputElement).value).toBe(`${window.location.origin}/p/tok123`)
  })

  it('keeps the editor open and explains when someone else saved first', async () => {
    const wrapper = await open()

    await button(wrapper, 'Edit')!.trigger('click')
    await wrapper.find('textarea').setValue('My new text')
    patchAnswer = () => errorResponse(412, 'version_mismatch', 'This page was changed by someone else.')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('Someone else changed this page while you were editing.')
    expect((wrapper.find('textarea').element as HTMLTextAreaElement).value).toBe('My new text')
  })

  it('gives a guest the text and no controls', async () => {
    page = makePage({ status: 'published' })
    const wrapper = await open('guest')

    expect(wrapper.find('.markdown').exists()).toBe(true)
    expect(wrapper.findAll('button')).toHaveLength(0)
  })
})

describe('PublicPageView', () => {
  afterEach(() => vi.unstubAllGlobals())

  async function open(response: Response) {
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(response)
    vi.stubGlobal('fetch', fetchMock)
    const router = makeTestRouter()
    await router.push('/p/tok123')
    const wrapper = mount(PublicPageView, { global: { plugins: [i18n, router] } })
    await flushPromises()
    return { wrapper, fetchMock }
  }

  it('shows a shared page to a signed-out reader', async () => {
    const { wrapper, fetchMock } = await open(jsonResponse({ data: { title: 'Handbook', body: 'Hello **there**', updated_at: '2026-01-02T00:00:00Z' } }))

    expect(String(fetchMock.mock.calls[0][0])).toBe('/api/v1/public/pages/tok123')
    expect(wrapper.find('h1').text()).toBe('Handbook')
    expect(wrapper.find('.markdown strong').text()).toBe('there')
  })

  it('says so when the link is dead', async () => {
    const { wrapper } = await open(errorResponse(404, 'not_found', 'Gone.'))

    expect(wrapper.text()).toContain('This page does not exist or is no longer shared.')
  })
})
