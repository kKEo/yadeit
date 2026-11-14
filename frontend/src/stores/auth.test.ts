import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
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
})
