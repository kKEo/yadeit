import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { mount, type VueWrapper } from '@vue/test-utils'
import { PiniaColada } from '@pinia/colada'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { defineComponent } from 'vue'
import type { Item, ItemDetail, Me, ProjectDetail, Workspace } from '@/api/types'
import i18n from '@/i18n'

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
    id: 7, name: 'Ada', email: 'ada@example.com', locale: 'en', email_verified: true, email_notifications: true,
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
      { path: '/w/:slug/items', name: 'items', component: blank },
      { path: '/w/:slug/items/:key', name: 'item-detail', component: blank },
      { path: '/w/:slug/inbox', name: 'inbox', component: blank },
      { path: '/w/:slug/projects', name: 'projects', component: blank },
      { path: '/w/:slug/members', name: 'workspace-members', component: blank },
      { path: '/w/:slug/agents', name: 'agents', component: blank },
      { path: '/w/:slug/docs', name: 'docs', component: blank },
      { path: '/w/:slug/docs/:id', name: 'doc', component: blank },
      { path: '/p/:token', name: 'public-page', component: blank },
      { path: '/f/:token', name: 'public-feedback', component: blank },
      { path: '/w/:slug/feedback', name: 'feedback', component: blank },
      { path: '/w/:slug/settings', name: 'workspace-settings', component: blank },
    ],
  })
}

export function makeItem(overrides: Partial<Item> = {}): Item {
  return {
    key: 'WEB-1', title: 'Fix login', state: { id: 3, name: 'Todo', group: 'unstarted', color: '#64748b' },
    priority: 'none', kind: 'task', assignee: null, delegate: null, needs_input: false, cycle: null, demand: 0, blocked: false, sub_items: null, labels: [], project: { key: 'WEB', name: 'Website' }, parent: null,
    due_date: null, version: 3, created_at: '2026-01-01T00:00:00Z', updated_at: '2026-01-02T00:00:00Z', completed_at: null,
    ...overrides,
  }
}

export function makeDetail(overrides: Partial<ItemDetail> = {}): ItemDetail {
  return {
    ...makeItem(), description: null, checklist: [], links: [], children: [], relations: [], attachments: [],
    comment_count: 0, subscribed: false, ...overrides,
  }
}

export function makeProject(overrides: Partial<ProjectDetail> = {}): ProjectDetail {
  return {
    id: 1, key: 'WEB', name: 'Website', description: null, archived: false, cycle_weeks: null, stale_after_days: null, feedback_token: null,
    current_cycle: null, created_at: '2026-01-01T00:00:00Z',
    states: [
      { id: 2, name: 'Backlog', group: 'backlog', color: '#94a3b8', is_default: false },
      { id: 3, name: 'Todo', group: 'unstarted', color: '#64748b', is_default: true },
      { id: 4, name: 'In progress', group: 'started', color: '#f59e0b', is_default: false },
      { id: 6, name: 'Done', group: 'completed', color: '#22c55e', is_default: false },
    ],
    labels: [{ id: 1, name: 'backend', color: '#3b82f6' }],
    ...overrides,
  }
}

/**
 * Runs composables inside a mounted component with the app's plugins (Pinia,
 * Pinia Colada, i18n, a test router), the way a view would call them.
 */
export function withApp<T>(setup: () => T, pinia: Pinia = createPinia(), router: Router = makeTestRouter()): { result: T; wrapper: VueWrapper; pinia: Pinia; router: Router } {
  setActivePinia(pinia)
  let result!: T
  const wrapper = mount(defineComponent({ setup() { result = setup(); return () => null } }), {
    global: { plugins: [pinia, PiniaColada, i18n, router] },
  })
  return { result, wrapper, pinia, router }
}

/** Answers `fetch` by method and path; unmatched requests are a 404 so a missing stub shows up. */
export function routeFetch(routes: Record<string, (init: RequestInit | undefined, url: URL) => Response | Promise<Response>>) {
  return async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = new URL(String(input), 'http://localhost')
    const handler = routes[`${init?.method ?? 'GET'} ${url.pathname}`]
    return handler ? handler(init, url) : errorResponse(404, 'not_found', `No stub for ${init?.method ?? 'GET'} ${url.pathname}`)
  }
}
