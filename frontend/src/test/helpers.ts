import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import type { Me, Workspace } from '@/api/types'

export function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

export function errorResponse(status: number, code: string, message: string, fields?: Record<string, string[]>): Response {
  return jsonResponse({ error: { code, message, ...(fields ? { details: { fields } } : {}) } }, status)
}

export function makeWorkspace(overrides: Partial<Workspace> = {}): Workspace {
  return { id: 1, name: 'Acme', slug: 'acme', role: 'owner', created_at: '2026-01-01T00:00:00Z', ...overrides }
}

export function makeMe(overrides: Partial<Me> = {}): Me {
  return {
    id: 7, name: 'Ada', email: 'ada@example.com', locale: 'en', email_verified: true,
    created_at: '2026-01-01T00:00:00Z', workspaces: [], ...overrides,
  }
}

/** A router with the named routes views link to, without the app's guard or lazy views. */
export function makeTestRouter(): Router {
  const blank = { render: () => null }
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: blank },
      { path: '/login', name: 'login', component: blank },
      { path: '/register', name: 'register', component: blank },
      { path: '/forgot-password', name: 'forgot-password', component: blank },
      { path: '/invitations/:token', name: 'invitation', component: blank },
      { path: '/w/:slug', name: 'workspace', component: blank },
    ],
  })
}
