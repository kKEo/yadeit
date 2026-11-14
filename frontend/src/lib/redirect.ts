import type { LocationQuery } from 'vue-router'

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
