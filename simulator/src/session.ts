import type { Recorder, ServerTiming } from './metrics.ts'

/** A request the server refused in a way the action did not expect. */
export class ApiFailure extends Error {
  readonly status: number
  readonly code: string | null

  constructor(method: string, path: string, status: number, code: string | null, message: string) {
    super(`${method} ${path} → ${status}${code ? ` ${code}` : ''}: ${message}`)
    this.status = status
    this.code = code
  }
}

export interface Reply<T> { status: number; data: T; headers: Headers }

export interface RequestOptions {
  body?: unknown
  /** Sent as multipart, like a file upload from the browser. */
  form?: FormData
  headers?: Record<string, string>
  /** Statuses the caller handles itself; anything else outside 2xx throws. */
  allow?: number[]
  /** What the request is counted under, when the address alone does not say (an MCP tool call). */
  name?: string
}

const API = '/api/v1'

/**
 * One simulated person's browser: a cookie jar, the web app's Origin, the
 * XSRF header on writes, and the signed header that marks the request as
 * simulated. Every request is timed and recorded under its endpoint.
 */
export class Session {
  private readonly cookies = new Map<string, string>()
  private readonly target: string
  private readonly origin: string
  private readonly simHeader: string
  private readonly recorder: Recorder
  /** Sent as a bearer token instead of cookies: how agents sign in. A client with a token is no browser and sends no Origin. */
  token: string | null = null

  constructor(target: string, origin: string, simHeader: string, recorder: Recorder) {
    this.target = target.replace(/\/$/, '')
    this.origin = origin
    this.simHeader = simHeader
    this.recorder = recorder
  }

  get<T>(path: string, options: RequestOptions = {}): Promise<Reply<T>> {
    return this.request<T>('GET', path, options)
  }

  post<T>(path: string, body?: unknown, options: RequestOptions = {}): Promise<Reply<T>> {
    return this.request<T>('POST', path, { ...options, body })
  }

  patch<T>(path: string, body?: unknown, options: RequestOptions = {}): Promise<Reply<T>> {
    return this.request<T>('PATCH', path, { ...options, body })
  }

  /** A page or file of the web app itself, not of the API. */
  page(path: string): Promise<Reply<string>> {
    return this.request<string>('GET', path, { headers: { Accept: 'text/html,*/*' } }, '')
  }

  /** The cookie that the XSRF header is read from; fetched before signing in, as the web app does. */
  async csrfCookie(): Promise<void> {
    await this.request('GET', '/sanctum/csrf-cookie', {}, '')
  }

  forget(): void {
    this.cookies.clear()
  }

  async request<T>(method: string, path: string, options: RequestOptions = {}, prefix: string = API): Promise<Reply<T>> {
    const headers: Record<string, string> = {
      Accept: 'application/json',
      ...(this.token ? {} : { Origin: this.origin, Referer: `${this.origin}/` }),
      'X-Sim-Run': this.simHeader,
      ...options.headers,
    }

    if (this.token) headers.Authorization = `Bearer ${this.token}`
    else if (this.cookies.size > 0) headers.Cookie = [...this.cookies].map(([k, v]) => `${k}=${v}`).join('; ')

    const xsrf = this.cookies.get('XSRF-TOKEN')
    if (method !== 'GET' && xsrf && !this.token) headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrf)

    let body: BodyInit | undefined
    if (options.form) body = options.form
    else if (options.body !== undefined) {
      headers['Content-Type'] = 'application/json'
      body = JSON.stringify(options.body)
    }

    const name = options.name ?? `${method} ${endpointName(prefix + path)}`
    const started = performance.now()
    let response: Response

    try {
      response = await fetch(this.target + prefix + path, { method, headers, body, redirect: 'manual', signal: AbortSignal.timeout(30_000) })
    } catch (error) {
      this.recorder.endpoint(name, performance.now() - started, false)
      throw new ApiFailure(method, path, 0, 'network', error instanceof Error ? error.message : String(error))
    }

    for (const cookie of response.headers.getSetCookie()) {
      const pair = cookie.split(';', 1)[0]!
      const at = pair.indexOf('=')
      if (at > 0) this.cookies.set(pair.slice(0, at).trim(), pair.slice(at + 1))
    }

    const text = await response.text()
    const ms = performance.now() - started
    const expected = response.ok || (options.allow ?? []).includes(response.status)
    // Only the server failing counts against the endpoint; a refusal the action expects is an answer.
    this.recorder.endpoint(name, ms, expected && response.status < 500, serverTiming(response.headers.get('Server-Timing')))

    let data: unknown = null
    if (text !== '') {
      try {
        data = JSON.parse(text)
      } catch {
        data = text
      }
    }

    if (!expected) {
      const error = (data as { error?: { code?: string; message?: string } } | null)?.error
      throw new ApiFailure(method, path, response.status, error?.code ?? null, error?.message ?? text.slice(0, 200))
    }

    return { status: response.status, data: data as T, headers: response.headers }
  }
}

/** What the server said about its own work on a simulated request: `app;dur=12.3, db;dur=4.5, queries;desc="7"`. */
export function serverTiming(header: string | null): ServerTiming | undefined {
  if (!header) return undefined
  const app = /app;dur=([\d.]+)/.exec(header)
  const db = /db;dur=([\d.]+)/.exec(header)
  const queries = /queries;desc="?(\d+)/.exec(header)
  return app ? { app: Number(app[1]), db: Number(db?.[1] ?? 0), queries: Number(queries?.[1] ?? 0) } : undefined
}

/** The address with everything that differs between teams and items taken out, so requests add up per endpoint. */
export function endpointName(path: string): string {
  return path
    .replace(/\?.*$/, '')
    .replace(/^\/assets\/.*$/, '/assets/{file}')
    .replace(/^(\/api\/v1\/workspaces\/)[^/]+/, '$1{workspace}')
    .replace(/\/[A-Z][A-Z0-9]{1,9}-\d+(?=\/|$)/g, '/{item}')
    .replace(/(\/projects\/)[A-Z][A-Z0-9]{1,9}(?=\/|$)/, '$1{project}')
    .replace(/\/\d+(?=\/|$)/g, '/{id}')
    .replace(/\/[A-Za-z0-9]{20,}(?=\/|$)/g, '/{token}')
}
