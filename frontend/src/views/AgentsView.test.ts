import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type { Router } from 'vue-router'
import type { Agent, WorkspaceRole } from '@/api/types'
import i18n from '@/i18n'
import { useAuthStore } from '@/stores/auth'
import { jsonResponse, makeMe, makeTestRouter, makeWorkspace } from '@/test/helpers'
import AgentsView from './AgentsView.vue'

// The workspace store reads the current workspace from the app router: point it at the test router.
const holder = vi.hoisted(() => ({ router: null as Router | null }))
vi.mock('@/router', () => ({
  default: new Proxy({}, { get: (_target, prop) => Reflect.get(holder.router as object, prop) }),
}))

const SECRET = '5|not-a-real-secret'

function makeAgent(overrides: Partial<Agent> = {}): Agent {
  return {
    id: 20, name: 'Coder', description: 'Writes code', owner: { id: 7, name: 'Ada' }, token_count: 0,
    created_at: '2026-01-01T00:00:00Z', ...overrides,
  }
}

describe('AgentsView', () => {
  const fetchMock = vi.fn<typeof fetch>()
  let agents: Agent[]

  async function open(role: WorkspaceRole = 'member') {
    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().user = makeMe({ workspaces: [makeWorkspace({ role })] })
    const router = makeTestRouter()
    holder.router = router
    await router.push('/w/acme/agents')
    const wrapper = mount(AgentsView, { global: { plugins: [pinia, i18n, router] }, attachTo: document.body })
    await flushPromises()
    return wrapper
  }

  function calls(method: string) {
    return fetchMock.mock.calls
      .filter(([, init]) => (init?.method ?? 'GET') === method)
      .map(([url, init]) => ({ url: String(url), body: init?.body ? JSON.parse(String(init.body)) : null }))
  }

  beforeEach(() => {
    agents = [makeAgent()]
    fetchMock.mockReset()
    fetchMock.mockImplementation(async (url, init) => {
      const path = String(url)
      const method = init?.method ?? 'GET'
      if (path.endsWith('/agents') && method === 'GET') return jsonResponse({ data: agents })
      if (path.endsWith('/projects')) return jsonResponse({ data: [{ id: 1, key: 'WEB', name: 'Website', description: null, archived: false, created_at: '' }] })
      if (path.endsWith('/agents/20/tokens') && method === 'GET') return jsonResponse({ data: [] })
      if (path.endsWith('/agents/20/tokens') && method === 'POST') {
        return jsonResponse({ data: {
          id: 5, name: 'CI', access: 'read', projects: ['WEB'], last_used_at: null, expires_at: null,
          created_at: '2026-01-01T00:00:00Z', token: SECRET,
        } }, 201)
      }
      return jsonResponse({ error: { code: 'not_found', message: 'Not found.' } }, 404)
    })
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    document.body.innerHTML = ''
  })

  it('lists agents with their owner', async () => {
    const wrapper = await open()

    expect(wrapper.text()).toContain('Coder')
    expect(wrapper.text()).toContain('Owner: Ada')
    expect(wrapper.text()).toContain('No tokens')
  })

  it('creates a limited token and shows its secret once', async () => {
    const wrapper = await open()

    await wrapper.findAll('button').find((b) => b.text() === 'Tokens')!.trigger('click')
    await flushPromises()

    await wrapper.find('#token-name-20').setValue('CI')
    await wrapper.find('#token-project-20-WEB').trigger('click')
    await wrapper.findAll('form')[1].trigger('submit')
    await flushPromises()

    expect(calls('POST')[0]).toEqual({
      url: '/api/v1/workspaces/acme/agents/20/tokens',
      body: { name: 'CI', access: 'write', projects: ['WEB'], expires_in_days: 90 },
    })

    // The secret lives in the dialog only; the token row never carries it.
    const dialog = document.body.querySelector('[role="dialog"]')!
    expect(dialog.textContent).toContain('Copy this token now')
    expect((dialog.querySelector('input') as HTMLInputElement).value).toBe(SECRET)
    expect(wrapper.text()).toContain('Limited to WEB')
    expect(wrapper.text()).not.toContain(SECRET)
    expect(wrapper.text()).toContain('1 token')
  })

  it('hides management from members who do not own the agent', async () => {
    agents = [makeAgent({ owner: { id: 99, name: 'Grace' } })]
    const wrapper = await open('member')

    expect(wrapper.findAll('button').map((b) => b.text())).not.toContain('Tokens')
    expect(wrapper.text()).toContain('Only the owner or a workspace admin can manage this agent.')
  })

  it('lets an admin manage any agent, and offers guests nothing to create', async () => {
    agents = [makeAgent({ owner: { id: 99, name: 'Grace' } })]

    const admin = await open('admin')
    expect(admin.findAll('button').map((b) => b.text())).toContain('Tokens')
    admin.unmount()

    const guest = await open('guest')
    expect(guest.find('#agent-name').exists()).toBe(false)
  })
})
