import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type { AdminWorkspace } from '@/api/types'
import i18n from '@/i18n'
import { errorResponse, jsonResponse, makeTestRouter } from '@/test/helpers'
import AdminWorkspacesView from './AdminWorkspacesView.vue'

function makeRow(overrides: Partial<AdminWorkspace> = {}): AdminWorkspace {
  return { id: 3, name: 'Acme', slug: 'acme', plan: 'free', members: 4, items: 12, suspended_at: null, created_at: '2026-01-01T00:00:00Z', ...overrides }
}

describe('AdminWorkspacesView', () => {
  const fetchMock = vi.fn<typeof fetch>()
  let rows: AdminWorkspace[]

  async function open() {
    const pinia = createPinia()
    setActivePinia(pinia)
    const wrapper = mount(AdminWorkspacesView, { global: { plugins: [pinia, i18n, makeTestRouter()] }, attachTo: document.body })
    await flushPromises()
    return wrapper
  }

  const calls = (method: string) => fetchMock.mock.calls
    .filter(([, init]) => (init?.method ?? 'GET') === method)
    .map(([url, init]) => ({ url: String(url), body: init?.body ? JSON.parse(String(init.body)) : null }))

  beforeEach(() => {
    rows = [makeRow(), makeRow({ id: 4, name: 'Globex', slug: 'globex', suspended_at: '2026-02-01T00:00:00Z' })]
    fetchMock.mockReset()
    fetchMock.mockImplementation(async (url, init) => {
      const path = String(url)
      const method = init?.method ?? 'GET'
      if (path.endsWith('/admin/overview')) {
        return jsonResponse({ data: { workspaces: 2, suspended_workspaces: 1, people: 9, agents: 2, items: 40, signups_last_7_days: 3 } })
      }
      if (path.includes('/admin/workspaces') && method === 'GET') return jsonResponse({ data: rows, next_cursor: null })
      if (path.endsWith('/admin/workspaces/3') && method === 'PATCH') {
        return jsonResponse({ data: makeRow({ suspended_at: '2026-03-01T00:00:00Z' }) })
      }
      if (path.endsWith('/admin/workspaces/4') && method === 'PATCH') return errorResponse(409, 'conflict', 'Not now.')
      return errorResponse(404, 'not_found', `No stub for ${method} ${path}`)
    })
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    document.body.innerHTML = ''
  })

  it('shows the outline and each workspace with its state', async () => {
    const wrapper = await open()

    expect(wrapper.text()).toContain('Sign-ups, last 7 days')
    const listed = wrapper.findAll('[data-testid="workspace-row"]')
    expect(listed).toHaveLength(2)
    expect(listed[0].text()).toContain('Acme')
    expect(listed[0].text()).toContain('Active')
    expect(listed[1].text()).toContain('Suspended')
    expect(listed[1].text()).toContain('Restore')
  })

  it('asks before suspending and then shows the workspace as suspended', async () => {
    const wrapper = await open()

    await wrapper.findAll('[data-testid="workspace-row"]')[0].findAll('button').find((b) => b.text() === 'Suspend')!.trigger('click')
    await flushPromises()
    expect(calls('PATCH')).toHaveLength(0)
    expect(document.body.textContent).toContain('Suspend Acme?')

    const confirm = [...document.body.querySelectorAll('[role="dialog"] button, [role="alertdialog"] button')].find((b) => b.textContent?.trim() === 'Suspend') as HTMLButtonElement
    confirm.click()
    await flushPromises()

    expect(calls('PATCH')).toEqual([{ url: expect.stringContaining('/admin/workspaces/3'), body: { suspended: true } }])
    expect(wrapper.findAll('[data-testid="workspace-row"]')[0].text()).toContain('Suspended')
  })

  it('sends the search and the filter, and shows why a change failed', async () => {
    const wrapper = await open()

    await wrapper.find('input[type="search"]').setValue('  glob ')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(calls('GET').at(-1)!.url).toContain('/admin/workspaces?q=glob')

    await wrapper.findAll('[data-testid="workspace-row"]')[1].findAll('button').find((b) => b.text() === 'Restore')!.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Not now.')
  })
})
