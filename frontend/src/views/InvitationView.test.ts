import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type { Me } from '@/api/types'
import i18n from '@/i18n'
import { useAuthStore } from '@/stores/auth'
import { errorResponse, jsonResponse, makeMe, makeTestRouter, makeWorkspace } from '@/test/helpers'
import InvitationView from './InvitationView.vue'

const preview = {
  workspace: { name: 'Acme' },
  email: 'new@example.com',
  role: 'member',
  expires_at: '2026-12-01T00:00:00Z',
  has_account: false,
}

describe('InvitationView', () => {
  const fetchMock = vi.fn<typeof fetch>()

  async function open(user: Me | null) {
    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().user = user
    const router = makeTestRouter()
    await router.push('/invitations/tok123')
    const wrapper = mount(InvitationView, { global: { plugins: [pinia, i18n, router] } })
    await flushPromises()
    return { wrapper, router }
  }

  function buttons(wrapper: Awaited<ReturnType<typeof open>>['wrapper']) {
    return wrapper.findAll('button').map((b) => b.text())
  }

  beforeEach(() => {
    fetchMock.mockReset()
    fetchMock.mockImplementation(async (url) =>
      String(url) === '/api/v1/invitations/tok123'
        ? jsonResponse({ data: preview })
        : errorResponse(404, 'not_found', 'Not found.'),
    )
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('offers a signed-out visitor to sign in or register, carrying the invitation along', async () => {
    const { wrapper, router } = await open(null)

    expect(wrapper.text()).toContain('Join Acme')
    expect(wrapper.text()).toContain('new@example.com')
    expect(buttons(wrapper)).toEqual(['Sign in', 'Create account'])

    await wrapper.findAll('button')[1].trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('register')
    expect(router.currentRoute.value.query).toEqual({ redirect: '/invitations/tok123', email: 'new@example.com' })
  })

  it('lets the invited account accept and opens the workspace', async () => {
    const joined = makeWorkspace({ id: 5, slug: 'acme', role: 'member' })
    fetchMock.mockImplementation(async (url, init) => {
      if (String(url) === '/api/v1/invitations/tok123') return jsonResponse({ data: preview })
      if (String(url) === '/api/v1/invitations/tok123/accept' && init?.method === 'POST') {
        return jsonResponse({ data: { workspace: { id: 5, name: 'Acme', slug: 'acme' } } })
      }
      if (String(url) === '/api/v1/me') return jsonResponse({ data: makeMe({ email: 'new@example.com', workspaces: [joined] }) })
      return errorResponse(404, 'not_found', 'Not found.')
    })
    // The address check ignores letter case, like the API.
    const { wrapper, router } = await open(makeMe({ email: 'New@Example.com' }))

    expect(buttons(wrapper)).toEqual(['Accept invitation'])

    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(useAuthStore().user?.workspaces).toEqual([joined])
    expect(router.currentRoute.value.path).toBe('/w/acme')
  })

  it('explains a mismatched account and offers to sign out instead of accepting', async () => {
    const { wrapper } = await open(makeMe({ email: 'someone.else@example.com' }))

    expect(wrapper.text()).toContain('You are signed in with a different account')
    expect(wrapper.text()).toContain('someone.else@example.com')
    expect(buttons(wrapper)).toEqual(['Sign out'])
  })

  it('shows the API message when accepting fails', async () => {
    fetchMock.mockImplementation(async (url) =>
      String(url) === '/api/v1/invitations/tok123'
        ? jsonResponse({ data: preview })
        : errorResponse(404, 'not_found', 'This invitation is no longer valid.'),
    )
    const { wrapper } = await open(makeMe({ email: 'new@example.com' }))

    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').text()).toContain('This invitation is no longer valid.')
  })

  it('says so when the invitation is used up or expired', async () => {
    fetchMock.mockResolvedValue(errorResponse(404, 'not_found', 'This invitation is no longer valid.'))

    const { wrapper } = await open(null)

    expect(wrapper.text()).toContain('Invitation not available')
    expect(buttons(wrapper)).toEqual([])
  })
})
