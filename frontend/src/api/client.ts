import { v4 as uuidv4 } from 'uuid'
import type {
  ApiErrorBody,
  CreateWorkItemRequest,
  FieldErrors,
  ItemResponse,
  ListResponse,
  ListWorkItemsParams,
  UpdateWorkItemRequest,
  User,
  WorkItem,
  WorkItemSummary,
} from './types'

export const API_BASE_PATH = '/api/v1'

const XSRF_COOKIE = 'XSRF-TOKEN'

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly fields: FieldErrors = {},
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

// Called when an authenticated request comes back 401 (session expired).
let unauthorizedHandler: (() => void) | null = null

export function setUnauthorizedHandler(handler: (() => void) | null) {
  unauthorizedHandler = handler
}

function readCookie(name: string): string | null {
  for (const part of document.cookie.split('; ')) {
    const eq = part.indexOf('=')
    if (eq !== -1 && part.slice(0, eq) === name) {
      const raw = part.slice(eq + 1)
      try {
        return decodeURIComponent(raw)
      } catch {
        return raw
      }
    }
  }
  return null
}

async function toApiError(res: Response): Promise<ApiError> {
  let code = 'unknown_error'
  let message = res.statusText || `HTTP ${res.status}`
  let fields: FieldErrors = {}
  try {
    const body = (await res.json()) as Partial<ApiErrorBody>
    code = body.error?.code ?? code
    message = body.error?.message ?? message
    fields = body.error?.details?.fields ?? fields
  } catch {
    // ignore parse error
  }
  return new ApiError(res.status, code, message, fields)
}

interface RequestOptions {
  body?: unknown
  headers?: Record<string, string>
  /** Do not notify the unauthorized handler on 401 (session probe, login). */
  expectUnauthorized?: boolean
}

interface Result<T> {
  body: T
  etag: string | null
}

async function send<T>(method: string, url: string, opts: RequestOptions = {}): Promise<Result<T>> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    ...opts.headers,
  }
  if (opts.body !== undefined) headers['Content-Type'] = 'application/json'
  if (method !== 'GET') {
    const xsrf = readCookie(XSRF_COOKIE)
    if (xsrf) headers['X-XSRF-TOKEN'] = xsrf
  }

  const res = await fetch(url, {
    method,
    headers,
    credentials: 'include',
    body: opts.body !== undefined ? JSON.stringify(opts.body) : undefined,
  })

  if (!res.ok) {
    if (res.status === 401 && !opts.expectUnauthorized) unauthorizedHandler?.()
    throw await toApiError(res)
  }

  const etag = res.headers.get('ETag')
  if (res.status === 204) return { body: undefined as T, etag }
  return { body: (await res.json()) as T, etag }
}

async function request<T>(method: string, path: string, opts?: RequestOptions): Promise<T> {
  return (await send<T>(method, `${API_BASE_PATH}${path}`, opts)).body
}

function idempotencyKey(): string {
  return uuidv4()
}

function mutationHeaders(etag?: string | null): Record<string, string> {
  const headers: Record<string, string> = {}
  if (etag) headers['If-Match'] = etag
  return headers
}

// ── Auth (Laravel Sanctum cookie session) ────────────────────────────────────

/** Primes the XSRF-TOKEN cookie. Must be called before the first mutating request. */
export async function csrfCookie(): Promise<void> {
  await send<void>('GET', '/sanctum/csrf-cookie', { expectUnauthorized: true })
}

export async function getMe(): Promise<User> {
  return (await request<ItemResponse<User>>('GET', '/me', { expectUnauthorized: true })).data
}

export async function login(email: string, password: string): Promise<void> {
  await csrfCookie()
  await request<unknown>('POST', '/auth/login', {
    body: { email, password },
    expectUnauthorized: true,
  })
}

export async function logout(): Promise<void> {
  await request<unknown>('POST', '/auth/logout', { expectUnauthorized: true })
}

// ── Work items ───────────────────────────────────────────────────────────────

export interface WithEtag<T> {
  item: T
  /** Value of the response `ETag` header, if the server sent one. */
  etag: string | null
}

const itemPath = (key: string) => `/items/${encodeURIComponent(key)}`

export async function listWorkItems(
  params?: ListWorkItemsParams,
): Promise<ListResponse<WorkItemSummary>> {
  const qs = new URLSearchParams()
  if (params?.state) qs.set('state', params.state)
  if (params?.cursor) qs.set('cursor', params.cursor)
  if (params?.limit != null) qs.set('limit', String(params.limit))
  const query = qs.toString() ? `?${qs}` : ''
  return request<ListResponse<WorkItemSummary>>('GET', `/items${query}`)
}

export async function getWorkItem(key: string): Promise<WithEtag<WorkItem>> {
  const res = await send<ItemResponse<WorkItem>>('GET', `${API_BASE_PATH}${itemPath(key)}`)
  return { item: res.body.data, etag: res.etag }
}

export async function createWorkItem(req: CreateWorkItemRequest): Promise<WithEtag<WorkItem>> {
  const res = await send<ItemResponse<WorkItem>>('POST', `${API_BASE_PATH}/items`, {
    body: req,
    headers: { 'Idempotency-Key': idempotencyKey() },
  })
  return { item: res.body.data, etag: res.etag }
}

export async function updateWorkItem(
  key: string,
  req: UpdateWorkItemRequest,
  etag?: string | null,
): Promise<WithEtag<WorkItem>> {
  const res = await send<ItemResponse<WorkItem>>('PATCH', `${API_BASE_PATH}${itemPath(key)}`, {
    body: req,
    headers: mutationHeaders(etag),
  })
  return { item: res.body.data, etag: res.etag }
}

export async function deleteWorkItem(key: string, etag?: string | null): Promise<void> {
  await request<void>('DELETE', itemPath(key), { headers: mutationHeaders(etag) })
}
