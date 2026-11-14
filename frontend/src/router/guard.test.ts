import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { Me } from '@/api/types'
import { errorResponse, jsonResponse, makeMe, makeWorkspace } from '@/test/helpers'
import router from './index'

describe('router guard', () => {
  const fetchMock = vi.fn<typeof fetch>()

  /** Answers the session probe: a user, or 401 when signed out. Everything else is 404. */
  function sessionIs(me: Me | null) {
    fetchMock.mockImplementation(async (url) => {
      if (String(url) !== '/api/v1/me') return errorResponse(404, 'not_found', 'Not found.')
      return me ? jsonResponse({ data: me }) : errorResponse(401, 'unauthenticated', 'Authentication is required.')
    })
  }

  async function visit(path: string) {
    await router.push(path)
    return router.currentRoute.value
  }

  beforeEach(async () => {
    vi.stubGlobal('fetch', fetchMock)
    // Park the shared router on a neutral public page, then start each test with a fresh session probe.
    setActivePinia(createPinia())
    sessionIs(null)
    await router.replace('/forgot-password')
    setActivePinia(createPinia())
    fetchMock.mockReset()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('sends a signed-out visitor to login and remembers where they were going', async () => {
    sessionIs(null)

    const route = await visit('/w/acme/members')

    expect(route.name).toBe('login')
    expect(route.query.redirect).toBe('/w/acme/members')
  })

  it('does not add a redirect when the signed-out visitor asked for the home page', async () => {
    sessionIs(null)

    const route = await visit('/')

    expect(route.name).toBe('login')
    expect(route.query).toEqual({})
  })

  it('lets a signed-out visitor open public pages', async () => {
    sessionIs(null)

    expect((await visit('/register')).name).toBe('register')
    expect((await visit('/invitations/abc123')).name).toBe('invitation')
    expect((await visit('/verify-email?id=1&hash=h&expires=2&signature=s')).name).toBe('verify-email')
    expect((await visit('/reset-password?token=t&email=a%40example.com')).name).toBe('reset-password')
  })

  it('sends a signed-in user without workspaces from / to onboarding', async () => {
    sessionIs(makeMe({ workspaces: [] }))

    const route = await visit('/')

    expect(route.name).toBe('welcome')
    expect(route.path).toBe('/welcome')
  })

  it('sends a signed-in user from / to their first workspace', async () => {
    sessionIs(makeMe({
      workspaces: [makeWorkspace({ id: 1, slug: 'acme' }), makeWorkspace({ id: 2, name: 'Zeta', slug: 'zeta' })],
    }))

    const route = await visit('/')

    // The workspace root lands on its items page.
    expect(route.name).toBe('items')
    expect(route.path).toBe('/w/acme/items')
  })

  it('sends a signed-in user away from login and register', async () => {
    sessionIs(makeMe({ workspaces: [makeWorkspace()] }))

    expect((await visit('/login')).path).toBe('/w/acme/items')
    expect((await visit('/register?email=x%40example.com')).path).toBe('/w/acme/items')
  })

  it('serves work item pages under the workspace', async () => {
    sessionIs(makeMe({ workspaces: [makeWorkspace()] }))

    const route = await visit('/w/acme/items/WEB-1')

    expect(route.name).toBe('item-detail')
    expect(route.params).toEqual({ slug: 'acme', key: 'WEB-1' })
  })
})
