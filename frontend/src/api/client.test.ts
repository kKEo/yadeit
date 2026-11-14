import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, createWorkItem, listWorkItems, login, updateWorkItem } from './client'

function jsonResponse(body: unknown, init: ResponseInit = {}): Response {
  return new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
    ...init,
  })
}

const item = {
  key: 'WEB-143',
  title: 'Fix login',
  description: '',
  state: 'backlog',
  priority: 'none',
  assignee: null,
  updated_at: '2026-01-01T00:00:00Z',
}

function clearCookies() {
  for (const part of document.cookie.split('; ')) {
    const name = part.split('=')[0]
    if (name) document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`
  }
}

describe('api client', () => {
  const fetchMock = vi.fn<typeof fetch>()

  function call(index = 0) {
    const [url, init] = fetchMock.mock.calls[index]
    return { url: String(url), init: init!, headers: init!.headers as Record<string, string> }
  }

  beforeEach(() => {
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    clearCookies()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('sends the URL-decoded XSRF cookie as X-XSRF-TOKEN on POST', async () => {
    document.cookie = 'XSRF-TOKEN=abc%3D%3D; path=/'
    fetchMock.mockResolvedValue(jsonResponse({ data: item }, { status: 201 }))

    const res = await createWorkItem({ title: 'Fix login' })

    expect(res.item.key).toBe('WEB-143')
    const { url, init, headers } = call()
    expect(url).toBe('/api/v1/items')
    expect(init.method).toBe('POST')
    expect(headers['X-XSRF-TOKEN']).toBe('abc==')
    expect(headers.Accept).toBe('application/json')
    expect(headers['Content-Type']).toBe('application/json')
    expect(headers['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/)
    expect(init.body).toBe(JSON.stringify({ title: 'Fix login' }))
  })

  it('includes credentials and omits the XSRF header on GET', async () => {
    document.cookie = 'XSRF-TOKEN=abc; path=/'
    fetchMock.mockResolvedValue(jsonResponse({ data: [], next_cursor: null }))

    const res = await listWorkItems({ state: 'started', cursor: 'c1' })

    expect(res).toEqual({ data: [], next_cursor: null })
    const { url, init, headers } = call()
    expect(url).toBe('/api/v1/items?state=started&cursor=c1')
    expect(init.credentials).toBe('include')
    expect(headers.Accept).toBe('application/json')
    expect(headers).not.toHaveProperty('X-XSRF-TOKEN')
  })

  it('includes credentials on mutating requests and sends If-Match when given an etag', async () => {
    document.cookie = 'XSRF-TOKEN=tok; path=/'
    fetchMock.mockResolvedValue(jsonResponse({ data: item }, { headers: { ETag: '"v2"' } }))

    const res = await updateWorkItem('WEB-143', { state: 'started' }, '"v1"')

    expect(res.etag).toBe('"v2"')
    const { url, init, headers } = call()
    expect(url).toBe('/api/v1/items/WEB-143')
    expect(init.method).toBe('PATCH')
    expect(init.credentials).toBe('include')
    expect(headers['If-Match']).toBe('"v1"')
    expect(headers['X-XSRF-TOKEN']).toBe('tok')
  })

  it('parses the error envelope into an ApiError with field errors', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(
        {
          error: {
            code: 'validation_failed',
            message: 'The given data was invalid.',
            details: { fields: { title: ['The title field is required.'] } },
          },
        },
        { status: 422 },
      ),
    )

    const err = await createWorkItem({ title: '' }).catch((e: unknown) => e)

    expect(err).toBeInstanceOf(ApiError)
    const apiError = err as ApiError
    expect(apiError.status).toBe(422)
    expect(apiError.code).toBe('validation_failed')
    expect(apiError.message).toBe('The given data was invalid.')
    expect(apiError.fields).toEqual({ title: ['The title field is required.'] })
  })

  it('falls back to a generic ApiError when the body is not the envelope', async () => {
    fetchMock.mockResolvedValue(new Response('<html>Bad Gateway</html>', { status: 502, statusText: 'Bad Gateway' }))

    const err = (await listWorkItems().catch((e: unknown) => e)) as ApiError

    expect(err).toBeInstanceOf(ApiError)
    expect(err.status).toBe(502)
    expect(err.code).toBe('unknown_error')
    expect(err.message).toBe('Bad Gateway')
    expect(err.fields).toEqual({})
  })

  it('fetches the Sanctum CSRF cookie before posting the login', async () => {
    fetchMock.mockImplementation(async (url) => {
      if (String(url) === '/sanctum/csrf-cookie') {
        document.cookie = 'XSRF-TOKEN=fresh%2Ftoken; path=/'
        return new Response(null, { status: 204 })
      }
      return new Response(null, { status: 204 })
    })

    await login('a@example.com', 'secret')

    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(call(0).url).toBe('/sanctum/csrf-cookie')
    expect(call(0).init.credentials).toBe('include')
    expect(call(1).url).toBe('/api/v1/auth/login')
    expect(call(1).headers['X-XSRF-TOKEN']).toBe('fresh/token')
    expect(call(1).init.body).toBe(JSON.stringify({ email: 'a@example.com', password: 'secret' }))
  })
})
