import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { PiniaColada } from '@pinia/colada'
import { createPinia, setActivePinia } from 'pinia'
import type { Router } from 'vue-router'
import type { Feedback, WorkspaceRole } from '@/api/types'
import i18n from '@/i18n'
import { useAuthStore } from '@/stores/auth'
import { errorResponse, jsonResponse, makeMe, makeProject, makeTestRouter, makeWorkspace, routeFetch } from '@/test/helpers'
import FeedbackView from './FeedbackView.vue'
import PublicFeedbackView from './PublicFeedbackView.vue'

const holder = vi.hoisted(() => ({ router: null as Router | null }))
vi.mock('@/router', () => ({
  default: new Proxy({}, { get: (_target, prop) => Reflect.get(holder.router as object, prop) }),
}))

function makeFeedback(overrides: Partial<Feedback> = {}): Feedback {
  return {
    id: 11, title: 'Please add dark mode', body: 'My eyes hurt.', project: { key: 'WEB', name: 'Website' }, source: 'public', status: 'new',
    snoozed_until: null, from: { name: 'Sam', email: 'sam@example.com' }, item: null, duplicate_of: null, decline_reason: null,
    created_at: '2026-01-01T00:00:00Z', triaged_at: null, ...overrides,
  }
}

describe('FeedbackView', () => {
  const fetchMock = vi.fn<typeof fetch>()
  const base = '/api/v1/workspaces/acme'
  const { states: _s, labels: _l, current_cycle: _c, ...project } = makeProject()

  async function open(role: WorkspaceRole = 'member') {
    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().user = makeMe({ workspaces: [makeWorkspace({ role })] })
    const router = makeTestRouter()
    holder.router = router
    await router.push('/w/acme/feedback')
    const wrapper = mount(FeedbackView, { global: { plugins: [pinia, PiniaColada, i18n, router] }, attachTo: document.body })
    await flushPromises()
    return wrapper
  }

  const posts = () => fetchMock.mock.calls.filter(([, init]) => init?.method === 'POST' || init?.method === 'PUT')
    .map(([url, init]) => ({ url: String(url).replace(base, ''), body: JSON.parse(String(init!.body)) }))
  const button = (wrapper: Awaited<ReturnType<typeof open>>, text: string) => wrapper.findAll('button').find((b) => b.text().startsWith(text))

  beforeEach(() => {
    fetchMock.mockReset()
    fetchMock.mockImplementation(routeFetch({
      [`GET ${base}/feedback`]: () => jsonResponse({ data: [makeFeedback(), makeFeedback({ id: 10, title: 'Night theme', source: 'app', from: { name: 'Ada', member_id: 7 } })], next_cursor: null, waiting: 2 }),
      [`GET ${base}/projects`]: () => jsonResponse({ data: [project] }),
      [`POST ${base}/feedback/11/accept`]: () => jsonResponse({ data: makeFeedback({ status: 'accepted' }) }),
      [`POST ${base}/feedback/11/duplicate`]: () => jsonResponse({ data: makeFeedback({ status: 'duplicate' }) }),
      [`PUT ${base}/projects/WEB/feedback-form`]: () => jsonResponse({ data: { project: 'WEB', feedback_token: 'formtoken' } }),
    }))
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    document.body.innerHTML = ''
  })

  it('shows what is waiting, who sent it and how to reach them', async () => {
    const wrapper = await open()

    expect(wrapper.text()).toContain('Inbox (2)')
    expect(wrapper.findAll('[data-feedback-id]').map((row) => row.attributes('data-feedback-id'))).toEqual(['11', '10'])
    expect(wrapper.text()).toContain('From Sam')
    expect(wrapper.find('a[href="mailto:sam@example.com"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Public form')
  })

  it('accepts feedback as a new item and takes it out of the inbox', async () => {
    const wrapper = await open()

    await button(wrapper, 'Accept as new item')!.trigger('click')
    await flushPromises()

    expect(posts()).toEqual([{ url: '/feedback/11/accept', body: {} }])
    expect(wrapper.findAll('[data-feedback-id]').map((row) => row.attributes('data-feedback-id'))).toEqual(['10'])
    expect(wrapper.text()).toContain('Inbox (1)')
  })

  it('adds feedback to an existing item by key', async () => {
    const wrapper = await open()

    await button(wrapper, 'Add to an item')!.trigger('click')
    const dialog = document.body.querySelector('[role="dialog"]')!
    const input = dialog.querySelector<HTMLInputElement>('#feedback-item')!
    input.value = 'web-12'
    input.dispatchEvent(new Event('input'))
    await flushPromises()
    dialog.querySelector('form')!.dispatchEvent(new Event('submit', { cancelable: true }))
    await flushPromises()

    expect(posts()).toEqual([{ url: '/feedback/11/accept', body: { item: 'WEB-12' } }])
  })

  it('lets a guest send feedback but offers no decisions', async () => {
    const wrapper = await open('guest')

    expect(button(wrapper, 'Send feedback')).toBeDefined()
    expect(button(wrapper, 'Accept as new item')).toBeUndefined()
    expect(wrapper.find('[data-public-form]').exists()).toBe(false)
  })

  it('switches the public form on for an admin and shows its link', async () => {
    const wrapper = await open('admin')

    await wrapper.find('[data-public-form="WEB"]').findAll('button').find((b) => b.text() === 'Switch on')!.trigger('click')
    await flushPromises()

    expect(posts()).toEqual([{ url: '/projects/WEB/feedback-form', body: { enabled: true } }])
    expect((wrapper.find('[data-public-form="WEB"] input').element as HTMLInputElement).value).toBe(`${window.location.origin}/f/formtoken`)
  })
})

describe('PublicFeedbackView', () => {
  const fetchMock = vi.fn<typeof fetch>()

  afterEach(() => vi.unstubAllGlobals())

  async function open(routes: Parameters<typeof routeFetch>[0]) {
    fetchMock.mockReset()
    fetchMock.mockImplementation(routeFetch(routes))
    vi.stubGlobal('fetch', fetchMock)
    const router = makeTestRouter()
    await router.push('/f/formtoken')
    const wrapper = mount(PublicFeedbackView, { global: { plugins: [i18n, router] } })
    await flushPromises()
    return wrapper
  }

  it('sends what a visitor wrote and thanks them', async () => {
    const wrapper = await open({
      'GET /api/v1/public/feedback/formtoken': () => jsonResponse({ data: { project: 'Website' } }),
      'POST /api/v1/public/feedback/formtoken': () => jsonResponse({ data: { received: true } }, 201),
    })

    expect(wrapper.find('h1').text()).toBe('Feedback for Website')
    await wrapper.find('#pf-title').setValue('The button is hidden')
    await wrapper.find('#pf-email').setValue('sam@example.com')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    const post = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST')!
    // The hidden field goes along empty: a person never fills it in.
    expect(JSON.parse(String(post[1]!.body))).toEqual({ title: 'The button is hidden', body: null, name: null, email: 'sam@example.com', website: '' })
    expect(wrapper.text()).toContain('Thank you. Your feedback was received.')
  })

  it('keeps the text and asks to wait when sending too fast', async () => {
    const wrapper = await open({
      'GET /api/v1/public/feedback/formtoken': () => jsonResponse({ data: { project: 'Website' } }),
      'POST /api/v1/public/feedback/formtoken': () => errorResponse(429, 'rate_limited', 'Too many attempts.'),
    })

    await wrapper.find('#pf-title').setValue('Again and again')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('Please try again in a minute.')
    expect((wrapper.find('#pf-title').element as HTMLInputElement).value).toBe('Again and again')
  })

  it('says so when the form is closed', async () => {
    const wrapper = await open({})

    expect(wrapper.text()).toContain('This feedback form does not exist or is closed.')
    expect(wrapper.find('form').exists()).toBe(false)
  })
})
