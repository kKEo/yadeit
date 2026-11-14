import type { LocationQuery, Router } from 'vue-router'
import { leaveTo } from '@/lib/navigate'

/** The `redirect` query value, but only when it is a same-origin path. */
export function redirectTarget(query: LocationQuery, fallback = '/'): string {
  const target = query.redirect
  return typeof target === 'string' && target.startsWith('/') && !target.startsWith('//') ? target : fallback
}

/** Carries `redirect` and `email` over when moving between the sign-in and register screens. */
export function authQuery(query: LocationQuery): Record<string, string> {
  const kept: Record<string, string> = {}
  for (const key of ['redirect', 'email'] as const) {
    const value = query[key]
    if (typeof value === 'string' && value !== '') kept[key] = value
  }
  return kept
}

export function queryString(query: LocationQuery, key: string): string {
  const value = query[key]
  return typeof value === 'string' ? value : ''
}

/** The consent screen of an app connecting over OAuth is a page of the backend, not a route of this app. */
export function isOAuthReturn(target: string): boolean {
  return target.startsWith('/oauth/authorize?')
}

/** Goes where `redirect` says after signing in: a route of the app, or back to the app that asked for access. */
export async function followRedirect(router: Router, query: LocationQuery): Promise<void> {
  const target = redirectTarget(query)
  if (isOAuthReturn(target)) leaveTo(target)
  else await router.push(target)
}
