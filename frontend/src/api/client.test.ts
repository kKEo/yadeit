import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, login } from './client'
import { createItem, listItems, updateItem, uploadAttachment } from './items'

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

    const res = await createItem('acme', { project: 'WEB', title: 'Fix login' })

    expect(res.key).toBe('WEB-143')
    const { url, init, headers } = call()
    expect(url).toBe('/api/v1/workspaces/acme/items')
    expect(init.method).toBe('POST')
    expect(headers['X-XSRF-TOKEN']).toBe('abc==')
    expect(headers.Accept).toBe('application/json')
    expect(headers['Content-Type']).toBe('application/json')
    expect(headers['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/)
    expect(init.body).toBe(JSON.stringify({ project: 'WEB', title: 'Fix login' }))
  })

  it('includes credentials and omits the XSRF header on GET', async () => {
    document.cookie = 'XSRF-TOKEN=abc; path=/'
    fetchMock.mockResolvedValue(jsonResponse({ data: [], next_cursor: null }))

    const res = await listItems('acme', { state_group: 'started', assignee: 'me', open: '1', cursor: 'c1' })

    expect(res).toEqual({ data: [], next_cursor: null })
    const { url, init, headers } = call()
    expect(url).toBe('/api/v1/workspaces/acme/items?state_group=started&assignee=me&open=1&cursor=c1')
    expect(init.credentials).toBe('include')
    expect(headers.Accept).toBe('application/json')
    expect(headers).not.toHaveProperty('X-XSRF-TOKEN')
  })

  it('sends only the changed fields plus expected_version on PATCH', async () => {
    document.cookie = 'XSRF-TOKEN=tok; path=/'
    fetchMock.mockResolvedValue(jsonResponse({ data: { ...item, version: 4 } }))

    const res = await updateItem('acme', 'WEB-143', { priority: 'high', expected_version: 3 })

    expect(res.version).toBe(4)
    const { url, init, headers } = call()
    expect(url).toBe('/api/v1/workspaces/acme/items/WEB-143')
    expect(init.method).toBe('PATCH')
    expect(init.credentials).toBe('include')
    expect(init.body).toBe(JSON.stringify({ priority: 'high', expected_version: 3 }))
    expect(headers['X-XSRF-TOKEN']).toBe('tok')
  })

  it('uploads attachments as multipart and lets the browser set the content type', async () => {
    fetchMock.mockResolvedValue(jsonResponse({ data: { id: 1, name: 'a.txt' } }, { status: 201 }))

    await uploadAttachment('acme', 'WEB-143', new File(['hello'], 'a.txt'))

    const { url, init, headers } = call()
    expect(url).toBe('/api/v1/workspaces/acme/items/WEB-143/attachments')
    expect(init.body).toBeInstanceOf(FormData)
    expect((init.body as FormData).get('file')).toBeInstanceOf(File)
    expect(headers).not.toHaveProperty('Content-Type')
  })

  it('keeps the details of a version conflict on the ApiError', async () => {
    fetchMock.mockResolvedValue(jsonResponse(
      { error: { code: 'version_mismatch', message: 'Changed.', details: { current_version: 5, expected_version: 3 } } },
      { status: 412 },
    ))

    const err = (await updateItem('acme', 'WEB-143', { title: 'x', expected_version: 3 }).catch((e: unknown) => e)) as ApiError

    expect(err.status).toBe(412)
    expect(err.code).toBe('version_mismatch')
    expect(err.details.current_version).toBe(5)
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

    const err = await createItem('acme', { project: 'WEB', title: '' }).catch((e: unknown) => e)

    expect(err).toBeInstanceOf(ApiError)
    const apiError = err as ApiError
    expect(apiError.status).toBe(422)
    expect(apiError.code).toBe('validation_failed')
    expect(apiError.message).toBe('The given data was invalid.')
    expect(apiError.fields).toEqual({ title: ['The title field is required.'] })
  })

  it('falls back to a generic ApiError when the body is not the envelope', async () => {
    fetchMock.mockResolvedValue(new Response('<html>Bad Gateway</html>', { status: 502, statusText: 'Bad Gateway' }))

    const err = (await listItems('acme').catch((e: unknown) => e)) as ApiError

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
