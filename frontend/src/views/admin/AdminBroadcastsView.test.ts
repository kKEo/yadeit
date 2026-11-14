import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import i18n from '@/i18n'
import { errorResponse, jsonResponse, makeTestRouter } from '@/test/helpers'
import AdminBroadcastsView from './AdminBroadcastsView.vue'

describe('AdminBroadcastsView', () => {
  const fetchMock = vi.fn<typeof fetch>()
  let recipients: number
  let refuse: boolean

  async function open() {
    const pinia = createPinia()
    setActivePinia(pinia)
    const wrapper = mount(AdminBroadcastsView, { global: { plugins: [pinia, i18n, makeTestRouter()] }, attachTo: document.body })
    await flushPromises()
    return wrapper
  }

  const posts = (suffix: string) => fetchMock.mock.calls
    .filter(([url, init]) => init?.method === 'POST' && String(url).endsWith(suffix))
    .map(([, init]) => JSON.parse(String(init?.body)))

  const button = (wrapper: Awaited<ReturnType<typeof open>>, label: string) => wrapper.findAll('button').find((b) => b.text() === label)

  async function confirm() {
    const node = [...document.body.querySelectorAll('[role="dialog"] button, [role="alertdialog"] button')].find((b) => b.textContent?.trim() === 'Send') as HTMLButtonElement
    node.click()
    await flushPromises()
  }

  beforeEach(() => {
    recipients = 12
    refuse = false
    fetchMock.mockReset()
    fetchMock.mockImplementation(async (url, init) => {
      const path = String(url)
      const method = init?.method ?? 'GET'
      if (path.endsWith('/admin/broadcasts') && method === 'GET') return jsonResponse({ data: [] })
      if (path.endsWith('/admin/broadcasts/preview')) return jsonResponse({ data: { recipients, sample: ['ada@example.com'] } })
      if (path.endsWith('/admin/broadcasts') && method === 'POST') {
        if (refuse) return errorResponse(409, 'recipients_changed', 'This announcement would now reach 13 people, not 12. Check the preview again.')
        return jsonResponse({ data: { id: 1, subject: 'Hi', body: 'Text', audience: 'everyone', plan: null, important: false, recipients, waiting: recipients, sent: 0, failed: 0, created_by: 'Op', created_at: '2026-01-01T00:00:00Z' } }, 201)
      }
      return errorResponse(404, 'not_found', `No stub for ${method} ${path}`)
    })
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    document.body.innerHTML = ''
  })

  it('sends only after the recipients were checked and the number confirmed', async () => {
    const wrapper = await open()
    await wrapper.find('#broadcast-subject').setValue('Maintenance')
    await wrapper.find('#broadcast-body').setValue('We will be **offline**.')

    expect(wrapper.find('[data-testid="broadcast-preview"]').html()).toContain('<strong>offline</strong>')
    expect(button(wrapper, 'Send')).toBeUndefined()

    await button(wrapper, 'Check who receives it')!.trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="broadcast-count"]').text()).toContain('Reaches 12 people.')
    expect(wrapper.find('[data-testid="broadcast-count"]').text()).toContain('ada@example.com')

    await button(wrapper, 'Send')!.trigger('click')
    await flushPromises()
    expect(posts('/admin/broadcasts')).toHaveLength(0)
    expect(document.body.textContent).toContain('Send to 12 people?')

    await confirm()
    expect(posts('/admin/broadcasts')).toEqual([{
      audience: 'everyone', plan: null, important: false, subject: 'Maintenance', body: 'We will be **offline**.', confirm_recipients: 12,
    }])
    expect(wrapper.text()).toContain('Queued for 12 people.')
    expect((wrapper.find('#broadcast-subject').element as HTMLInputElement).value).toBe('')
  })

  it('asks to check again when the audience changed before sending', async () => {
    const wrapper = await open()
    await wrapper.find('#broadcast-subject').setValue('Maintenance')
    await wrapper.find('#broadcast-body').setValue('Text')
    await button(wrapper, 'Check who receives it')!.trigger('click')
    await flushPromises()

    refuse = true
    await button(wrapper, 'Send')!.trigger('click')
    await flushPromises()
    await confirm()

    expect(wrapper.text()).toContain('would now reach 13 people')
    expect(wrapper.find('[data-testid="broadcast-count"]').exists()).toBe(false)
    expect(button(wrapper, 'Send')).toBeUndefined()
    expect((wrapper.find('#broadcast-subject').element as HTMLInputElement).value).toBe('Maintenance')
  })
})
