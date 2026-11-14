import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import i18n from '@/i18n'
import { useAuthStore } from './auth'

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

describe('auth store', () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    setActivePinia(createPinia())
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
  })

  it('fetchUser sets the user on success', async () => {
    const user = { id: 1, name: 'Ada', email: 'ada@example.com' }
    fetchMock.mockResolvedValue(jsonResponse({ data: user }))
    const auth = useAuthStore()

    await auth.fetchUser()

    expect(String(fetchMock.mock.calls[0][0])).toBe('/api/v1/me')
    expect(auth.user).toEqual(user)
    expect(auth.isAuthenticated).toBe(true)
    expect(auth.error).toBeNull()
  })

  it('fetchUser leaves the store signed out on 401 without reporting an error', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse({ error: { code: 'unauthenticated', message: 'Unauthenticated.' } }, 401),
    )
    const auth = useAuthStore()

    await auth.fetchUser()

    expect(auth.user).toBeNull()
    expect(auth.isAuthenticated).toBe(false)
    expect(auth.error).toBeNull()
  })

  it('ensureInitialized probes the session only once', async () => {
    fetchMock.mockImplementation(async () => jsonResponse({ error: { code: 'unauthenticated', message: 'x' } }, 401))
    const auth = useAuthStore()

    await Promise.all([auth.ensureInitialized(), auth.ensureInitialized()])
    await auth.ensureInitialized()

    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('login primes the CSRF cookie, signs in and loads the user', async () => {
    const user = { id: 1, name: 'Ada', email: 'ada@example.com' }
    fetchMock.mockImplementation(async (url) =>
      String(url) === '/api/v1/me' ? jsonResponse({ data: user }) : new Response(null, { status: 204 }),
    )
    const auth = useAuthStore()

    const ok = await auth.login('ada@example.com', 'secret')

    expect(ok).toBe(true)
    expect(fetchMock.mock.calls.map(([url]) => String(url))).toEqual([
      '/sanctum/csrf-cookie',
      '/api/v1/auth/login',
      '/api/v1/me',
    ])
    expect(auth.user).toEqual(user)
  })

  it('login failure keeps the store signed out and exposes the message', async () => {
    fetchMock.mockImplementation(async (url) =>
      String(url) === '/api/v1/auth/login'
        ? jsonResponse({ error: { code: 'invalid_credentials', message: 'These credentials do not match.' } }, 422)
        : new Response(null, { status: 204 }),
    )
    const auth = useAuthStore()

    const ok = await auth.login('ada@example.com', 'wrong')

    expect(ok).toBe(false)
    expect(auth.isAuthenticated).toBe(false)
    expect(auth.error).toBe('These credentials do not match.')
  })

  describe('with workspaces', () => {
    const account = { id: 7, name: 'Ada', email: 'ada@example.com', locale: 'en', email_verified: false, created_at: '2026-01-01T00:00:00Z' }
    const workspace = { id: 3, name: 'Acme', slug: 'acme', role: 'owner', created_at: '2026-01-01T00:00:00Z' }

    afterEach(() => {
      i18n.global.locale.value = 'en'
    })

    it('login populates the user with their workspaces', async () => {
      fetchMock.mockImplementation(async (url) => {
        if (String(url) === '/api/v1/me') return jsonResponse({ data: { ...account, workspaces: [workspace] } })
        // The login answer itself carries no workspace list.
        if (String(url) === '/api/v1/auth/login') return jsonResponse({ data: account })
        return new Response(null, { status: 204 })
      })
      const auth = useAuthStore()

      expect(await auth.login('ada@example.com', 'secret')).toBe(true)

      expect(auth.user?.workspaces).toEqual([workspace])
      expect(auth.user?.email_verified).toBe(false)
    })

    it('register creates the account, then loads me with workspaces and applies the locale', async () => {
      fetchMock.mockImplementation(async (url) => {
        if (String(url) === '/api/v1/me') return jsonResponse({ data: { ...account, locale: 'pl', workspaces: [workspace] } })
        if (String(url) === '/api/v1/auth/register') return jsonResponse({ data: account }, 201)
        return new Response(null, { status: 204 })
      })
      const auth = useAuthStore()
      const form = { name: 'Ada', email: 'ada@example.com', password: 'correct horse', password_confirmation: 'correct horse' }

      expect(await auth.register(form)).toBe(true)

      expect(fetchMock.mock.calls.map(([url]) => String(url))).toEqual([
        '/sanctum/csrf-cookie',
        '/api/v1/auth/register',
        '/api/v1/me',
      ])
      expect(fetchMock.mock.calls[1][1]?.body).toBe(JSON.stringify(form))
      expect(auth.user?.workspaces).toEqual([workspace])
      expect(i18n.global.locale.value).toBe('pl')
    })

    it('register failure exposes the field errors and stays signed out', async () => {
      fetchMock.mockImplementation(async (url) =>
        String(url) === '/api/v1/auth/register'
          ? jsonResponse({
              error: {
                code: 'validation_failed',
                message: 'The email has already been taken.',
                details: { fields: { email: ['The email has already been taken.'] } },
              },
            }, 422)
          : new Response(null, { status: 204 }),
      )
      const auth = useAuthStore()

      const ok = await auth.register({ name: 'Ada', email: 'ada@example.com', password: 'x', password_confirmation: 'x' })

      expect(ok).toBe(false)
      expect(auth.isAuthenticated).toBe(false)
      expect(auth.fieldErrors.email).toEqual(['The email has already been taken.'])
    })
  })
})
