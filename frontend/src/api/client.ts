import { saveFile } from '@/lib/navigate'
import type {
  AcceptedInvitation,
  ApiErrorBody,
  CreateInvitationRequest,
  CreateWorkspaceRequest,
  FieldErrors,
  Invitation,
  InvitationPreview,
  ItemResponse,
  Me,
  Member,
  MessageResponse,
  RegisterRequest,
  ResetPasswordRequest,
  UpdateMeRequest,
  UpdateWorkspaceRequest,
  VerifyEmailParams,
  Workspace,
  WorkspaceRole,
} from './types'

export const API_BASE_PATH = '/api/v1'

const XSRF_COOKIE = 'XSRF-TOKEN'

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly fields: FieldErrors = {},
    /** Extra machine-readable context, e.g. `current_version` on a 412. */
    public readonly details: Record<string, unknown> = {},
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
  let details: Record<string, unknown> = {}
  try {
    const body = (await res.json()) as Partial<ApiErrorBody>
    code = body.error?.code ?? code
    message = body.error?.message ?? message
    fields = body.error?.details?.fields ?? fields
    details = body.error?.details ?? details
  } catch {
    // ignore parse error
  }
  return new ApiError(res.status, code, message, fields, details)
}

export interface RequestOptions {
  /** Sent as JSON, or as multipart when it is a `FormData`. */
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
  const multipart = opts.body instanceof FormData
  if (opts.body !== undefined && !multipart) headers['Content-Type'] = 'application/json'
  if (method !== 'GET') {
    const xsrf = readCookie(XSRF_COOKIE)
    if (xsrf) headers['X-XSRF-TOKEN'] = xsrf
  }

  const res = await fetch(url, {
    method,
    headers,
    credentials: 'include',
    body: opts.body === undefined ? undefined : multipart ? (opts.body as FormData) : JSON.stringify(opts.body),
  })

  if (!res.ok) {
    if (res.status === 401 && !opts.expectUnauthorized) unauthorizedHandler?.()
    throw await toApiError(res)
  }

  const etag = res.headers.get('ETag')
  if (res.status === 204) return { body: undefined as T, etag }
  return { body: (await res.json()) as T, etag }
}

export async function request<T>(method: string, path: string, opts?: RequestOptions): Promise<T> {
  return (await send<T>(method, `${API_BASE_PATH}${path}`, opts)).body
}

// ── Auth (Laravel Sanctum cookie session) ────────────────────────────────────

/** Primes the XSRF-TOKEN cookie. Must be called before the first mutating request. */
export async function csrfCookie(): Promise<void> {
  await send<void>('GET', '/sanctum/csrf-cookie', { expectUnauthorized: true })
}

export async function getMe(): Promise<Me> {
  return (await request<ItemResponse<Me>>('GET', '/me', { expectUnauthorized: true })).data
}

/**
 * Fetches a file and hands it to the browser to save, under the name the API gives it.
 * Going through fetch (and not a plain link) means a refusal shows up as an error in the app.
 */
async function download(path: string): Promise<void> {
  const res = await fetch(`${API_BASE_PATH}${path}`, { headers: { Accept: 'application/json' }, credentials: 'include' })
  if (!res.ok) {
    if (res.status === 401) unauthorizedHandler?.()
    throw await toApiError(res)
  }
  const name = /filename="?([^";]+)"?/.exec(res.headers.get('Content-Disposition') ?? '')?.[1] ?? 'export.json'
  saveFile(await res.blob(), name)
}

/** Everything the signed-in person wrote and belongs to, as one JSON file. */
export const downloadMyData = () => download('/me/export')

/** The whole workspace as one JSON file; owners only. */
export const downloadWorkspace = (slug: string) => download(`${workspacePath(slug)}/export`)

/** Deletes the signed-in account for good. The password is asked again on purpose. */
export async function deleteAccount(password: string): Promise<void> {
  await request<void>('DELETE', '/me', { body: { password } })
}

export async function updateMe(req: UpdateMeRequest): Promise<Me> {
  return (await request<ItemResponse<Me>>('PATCH', '/me', { body: req })).data
}

/** Creates the account and signs it in. */
export async function register(req: RegisterRequest): Promise<void> {
  await csrfCookie()
  await request<unknown>('POST', '/auth/register', { body: req, expectUnauthorized: true })
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

export async function forgotPassword(email: string): Promise<void> {
  await csrfCookie()
  await request<MessageResponse>('POST', '/auth/forgot-password', { body: { email }, expectUnauthorized: true })
}

export async function resetPassword(req: ResetPasswordRequest): Promise<void> {
  await csrfCookie()
  await request<MessageResponse>('POST', '/auth/reset-password', { body: req, expectUnauthorized: true })
}

/** Confirms an address from the emailed link; works signed out. */
export async function verifyEmail(params: VerifyEmailParams): Promise<void> {
  // The signature covers the query string as the API built it: `expires` first.
  const qs = new URLSearchParams({ expires: params.expires, signature: params.signature })
  const path = `/auth/verify-email/${encodeURIComponent(params.id)}/${encodeURIComponent(params.hash)}?${qs}`
  await request<MessageResponse>('GET', path, { expectUnauthorized: true })
}

export async function resendVerificationEmail(): Promise<void> {
  await request<MessageResponse>('POST', '/auth/email/resend')
}

// ── Workspaces ───────────────────────────────────────────────────────────────

export const workspacePath = (slug: string) => `/workspaces/${encodeURIComponent(slug)}`

export async function createWorkspace(req: CreateWorkspaceRequest): Promise<Workspace> {
  return (await request<ItemResponse<Workspace>>('POST', '/workspaces', { body: req })).data
}

export async function getWorkspace(slug: string): Promise<Workspace> {
  return (await request<ItemResponse<Workspace>>('GET', workspacePath(slug))).data
}

export async function updateWorkspace(slug: string, req: UpdateWorkspaceRequest): Promise<Workspace> {
  return (await request<ItemResponse<Workspace>>('PATCH', workspacePath(slug), { body: req })).data
}

export async function deleteWorkspace(slug: string): Promise<void> {
  await request<void>('DELETE', workspacePath(slug))
}

// ── Members and invitations ──────────────────────────────────────────────────

export async function listMembers(slug: string): Promise<Member[]> {
  return (await request<ItemResponse<Member[]>>('GET', `${workspacePath(slug)}/members`)).data
}

export async function updateMemberRole(slug: string, userId: number, role: WorkspaceRole): Promise<Member> {
  const path = `${workspacePath(slug)}/members/${userId}`
  return (await request<ItemResponse<Member>>('PATCH', path, { body: { role } })).data
}

/** Removes a member; with the caller's own id it means "leave". */
export async function removeMember(slug: string, userId: number): Promise<void> {
  await request<void>('DELETE', `${workspacePath(slug)}/members/${userId}`)
}

export async function listInvitations(slug: string): Promise<Invitation[]> {
  return (await request<ItemResponse<Invitation[]>>('GET', `${workspacePath(slug)}/invitations`)).data
}

export async function createInvitation(slug: string, req: CreateInvitationRequest): Promise<Invitation> {
  const path = `${workspacePath(slug)}/invitations`
  return (await request<ItemResponse<Invitation>>('POST', path, { body: req })).data
}

export async function deleteInvitation(slug: string, id: number): Promise<void> {
  await request<void>('DELETE', `${workspacePath(slug)}/invitations/${id}`)
}

export async function getInvitationPreview(token: string): Promise<InvitationPreview> {
  const path = `/invitations/${encodeURIComponent(token)}`
  return (await request<ItemResponse<InvitationPreview>>('GET', path, { expectUnauthorized: true })).data
}

export async function acceptInvitation(token: string): Promise<AcceptedInvitation> {
  const path = `/invitations/${encodeURIComponent(token)}/accept`
  return (await request<ItemResponse<AcceptedInvitation>>('POST', path)).data
}
