import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type { Webhook } from '@/api/types'
import i18n from '@/i18n'
import { jsonResponse, makeTestRouter, routeFetch } from '@/test/helpers'
import WebhooksSection from './WebhooksSection.vue'

const SECRET = 'whsec_not-a-real-secret'
const EVENTS = ['item.created', 'item.input_requested']

function makeHook(overrides: Partial<Webhook> = {}): Webhook {
  return {
    id: 3, name: 'Runner', url: 'https://example.com/hook', events: null, is_active: true, disabled_at: null,
    created_at: '2026-01-01T00:00:00Z', ...overrides,
  }
}

describe('WebhooksSection', () => {
  const fetchMock = vi.fn<typeof fetch>()
  let hooks: Webhook[]
  const base = '/api/v1/workspaces/acme/webhooks'

  async function open() {
    const pinia = createPinia()
    setActivePinia(pinia)
    const router = makeTestRouter()
    await router.push('/w/acme/settings')
    const wrapper = mount(WebhooksSection, { global: { plugins: [pinia, i18n, router] }, attachTo: document.body })
    await flushPromises()
    return wrapper
  }

  function bodies(method: string) {
    return fetchMock.mock.calls.filter(([, init]) => init?.method === method).map(([url, init]) => ({
      url: String(url), body: init?.body ? JSON.parse(String(init.body)) : null,
    }))
  }

  beforeEach(() => {
    hooks = [makeHook()]
    fetchMock.mockReset()
    fetchMock.mockImplementation(routeFetch({
      [`GET ${base}`]: () => jsonResponse({ data: hooks, events: EVENTS }),
      [`POST ${base}`]: () => jsonResponse({ data: { ...makeHook({ id: 4, name: 'Chat', events: ['item.input_requested'] }), secret: SECRET } }, 201),
      [`PATCH ${base}/3`]: () => jsonResponse({ data: makeHook({ is_active: true }) }),
      [`GET ${base}/3/deliveries`]: () => jsonResponse({ data: [
        { id: 2, event_id: 'e1', event: 'item.created', attempt: 2, succeeded: true, status_code: 200, error: null, duration_ms: 40, created_at: '2026-01-01T10:01:00Z' },
        { id: 1, event_id: 'e1', event: 'item.created', attempt: 1, succeeded: false, status_code: 503, error: 'The receiver answered 503.', duration_ms: 35, created_at: '2026-01-01T10:00:00Z' },
      ] }),
    }))
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    document.body.innerHTML = ''
  })

  it('lists webhooks with what they receive', async () => {
    const wrapper = await open()

    expect(wrapper.text()).toContain('Runner')
    expect(wrapper.text()).toContain('https://example.com/hook')
    expect(wrapper.text()).toContain('All events')
  })

  it('adds a webhook for chosen events and shows the secret once', async () => {
    const wrapper = await open()

    await wrapper.find('#webhook-name').setValue('Chat')
    await wrapper.find('#webhook-url').setValue('https://example.com/chat')
    await wrapper.find('[id="webhook-event-item.input_requested"]').trigger('click')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(bodies('POST')[0]).toEqual({ url: base, body: { name: 'Chat', url: 'https://example.com/chat', events: ['item.input_requested'] } })

    const dialog = document.body.querySelector('[role="dialog"]')!
    expect((dialog.querySelector('input') as HTMLInputElement).value).toBe(SECRET)
    expect(wrapper.text()).toContain('Chat')
    expect(wrapper.text()).not.toContain(SECRET)
  })

  it('offers to switch a self-disabled webhook back on', async () => {
    hooks = [makeHook({ is_active: false, disabled_at: '2026-01-02T00:00:00Z' })]
    const wrapper = await open()

    expect(wrapper.text()).toContain('Switched off after repeated failures')

    await wrapper.findAll('button').find((b) => b.text() === 'Switch on')!.trigger('click')
    await flushPromises()

    expect(bodies('PATCH')[0]).toEqual({ url: `${base}/3`, body: { is_active: true } })
    expect(wrapper.text()).toContain('On')
  })

  it('shows the delivery log with failures and retries', async () => {
    const wrapper = await open()

    await wrapper.findAll('button').find((b) => b.text() === 'Deliveries')!.trigger('click')
    await flushPromises()

    expect(wrapper.findAll('[data-delivery-ok]').map((r) => r.attributes('data-delivery-ok'))).toEqual(['true', 'false'])
    expect(wrapper.text()).toContain('attempt 2')
    expect(wrapper.text()).toContain('The receiver answered 503.')
  })
})
