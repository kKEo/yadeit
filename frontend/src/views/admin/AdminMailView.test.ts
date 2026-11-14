import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type { OutgoingEmail } from '@/api/types'
import i18n from '@/i18n'
import { errorResponse, jsonResponse, makeTestRouter } from '@/test/helpers'
import AdminMailView from './AdminMailView.vue'

function makeMail(overrides: Partial<OutgoingEmail> = {}): OutgoingEmail {
  return {
    id: 1, kind: 'Announcement', to: { email: 'ada@example.com', name: 'Ada' }, subject: 'Maintenance', status: 'waiting',
    attempts: 0, error: null, broadcast_id: 5, sent_at: null, created_at: '2026-01-01T00:00:00Z', ...overrides,
  }
}

describe('AdminMailView', () => {
  const fetchMock = vi.fn<typeof fetch>()
  let rows: OutgoingEmail[]

  async function open(path = '/admin/mail') {
    const pinia = createPinia()
    setActivePinia(pinia)
    const router = makeTestRouter()
    await router.push(path)
    const wrapper = mount(AdminMailView, { global: { plugins: [pinia, i18n, router] }, attachTo: document.body })
    await flushPromises()
    return wrapper
  }

  const calls = (method: string) => fetchMock.mock.calls.filter(([, init]) => (init?.method ?? 'GET') === method).map(([url]) => String(url))

  beforeEach(() => {
    rows = [makeMail(), makeMail({ id: 2, kind: 'ResetPassword', subject: 'Reset your password', status: 'sent', attempts: 1, broadcast_id: null, sent_at: '2026-01-02T00:00:00Z' })]
    fetchMock.mockReset()
    fetchMock.mockImplementation(async (url, init) => {
      const path = String(url)
      const method = init?.method ?? 'GET'
      if (path.includes('/admin/mail') && method === 'GET' && !/\/mail\/\d+$/.test(path)) {
        return jsonResponse({ data: rows, next_cursor: null, counts: { waiting: 1, sending: 0, sent: 1, failed: 0 } })
      }
      if (path.endsWith('/admin/mail/2') && method === 'GET') return jsonResponse({ data: { ...rows[1], body: null } })
      if (path.endsWith('/admin/mail/1/send')) {
        rows = [makeMail({ status: 'sent', attempts: 1, sent_at: '2026-01-03T00:00:00Z' }), rows[1]]
        return jsonResponse({ data: rows[0] })
      }
      if (path.endsWith('/admin/mail/1') && method === 'DELETE') {
        rows = [rows[1]]
        return new Response(null, { status: 204 })
      }
      return errorResponse(404, 'not_found', `No stub for ${method} ${path}`)
    })
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    document.body.innerHTML = ''
  })

  it('offers sending only for announcement messages that wait', async () => {
    const wrapper = await open()
    const listed = wrapper.findAll('[data-testid="mail-row"]')

    expect(listed).toHaveLength(2)
    expect(listed[0].text()).toContain('Send now')
    expect(listed[1].text()).not.toContain('Send now')

    await listed[0].findAll('button').find((b) => b.text() === 'Send now')!.trigger('click')
    await flushPromises()
    expect(calls('POST')).toEqual([expect.stringContaining('/admin/mail/1/send')])
    expect(wrapper.findAll('[data-testid="mail-row"]')[0].text()).toContain('Sent')
  })

  it('says that the text of system mail is not kept', async () => {
    const wrapper = await open()

    await wrapper.findAll('[data-testid="mail-row"]')[1].findAll('button').find((b) => b.text() === 'Details')!.trigger('click')
    await flushPromises()
    expect(document.body.textContent).toContain('The text of this email is not kept.')
  })

  it('deletes after a confirmation and narrows to one announcement from the link', async () => {
    const wrapper = await open('/admin/mail?broadcast=5')
    expect(calls('GET')[0]).toContain('broadcast=5')

    await wrapper.findAll('[data-testid="mail-row"]')[0].findAll('button').find((b) => b.text() === 'Delete')!.trigger('click')
    await flushPromises()
    expect(document.body.textContent).toContain('It will never be sent.')
    expect(calls('DELETE')).toHaveLength(0)

    const confirm = [...document.body.querySelectorAll('[role="dialog"] button, [role="alertdialog"] button')].find((b) => b.textContent?.trim() === 'Delete') as HTMLButtonElement
    confirm.click()
    await flushPromises()
    expect(calls('DELETE')).toEqual([expect.stringContaining('/admin/mail/1')])
    expect(wrapper.findAll('[data-testid="mail-row"]')).toHaveLength(1)
  })
})
