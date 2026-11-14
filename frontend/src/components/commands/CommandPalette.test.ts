import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { PiniaColada } from '@pinia/colada'
import { createPinia, setActivePinia } from 'pinia'
import { computed } from 'vue'
import type { Router } from 'vue-router'
import i18n from '@/i18n'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { jsonResponse, makeMe, makeTestRouter, makeWorkspace, routeFetch } from '@/test/helpers'
import CommandPalette from './CommandPalette.vue'

const holder = vi.hoisted(() => ({ router: null as Router | null }))
vi.mock('@/router', () => ({
  default: new Proxy({}, { get: (_target, prop) => Reflect.get(holder.router as object, prop) }),
}))
vi.mock('@/queries/keys', async (original) => ({
  ...(await original<typeof import('@/queries/keys')>()),
  useSlug: () => computed(() => 'acme'),
}))

describe('CommandPalette search', () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.useFakeTimers()
    fetchMock.mockReset()
    fetchMock.mockImplementation(routeFetch({
      'GET /api/v1/workspaces/acme/search': (_init, url) => jsonResponse({ data: url.searchParams.get('q') === 'safari' ? [
        { type: 'item', key: 'WEB-1', title: 'Login fails with Safari', state: { id: 3, name: 'Todo', group: 'unstarted', color: '#64748b' }, snippet: null },
        { type: 'comment', key: 'WEB-2', title: 'Dark mode', author: 'Ada', snippet: 'I tried Safari 19 and…' },
        { type: 'page', id: 9, title: 'Browser support', status: 'published', project: null, snippet: 'We support Safari…' },
      ] : [] }),
    }))
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
    document.body.innerHTML = ''
  })

  async function open() {
    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().user = makeMe({ workspaces: [makeWorkspace()] })
    const router = makeTestRouter()
    holder.router = router
    await router.push('/w/acme/items')
    mount(CommandPalette, { global: { plugins: [pinia, PiniaColada, i18n, router] }, attachTo: document.body })
    useUiStore().paletteOpen = true
    await flushPromises()
    return router
  }

  async function type(text: string) {
    const input = document.body.querySelector<HTMLInputElement>('input[role="combobox"]')!
    input.value = text
    input.dispatchEvent(new Event('input'))
    await vi.advanceTimersByTimeAsync(250)
    await flushPromises()
    return input
  }

  const options = () => [...document.body.querySelectorAll('[role="option"]')].map((el) => el.textContent!.replace(/\s+/g, ' ').trim())

  it('lists items, comments and pages for what was typed', async () => {
    await open()
    await type('safari')

    // Text of each row as rendered: state, key, title, then the snippet.
    expect(options()).toEqual([
      'TodoWEB-1Login fails with Safari',
      'WEB-2Dark modeI tried Safari 19 and…',
      'Browser supportWe support Safari…',
    ])
    expect(document.body.textContent).toContain('Comments')
    expect(document.body.textContent).toContain('Pages')
  })

  it('opens a page hit in the docs', async () => {
    const router = await open()
    const input = await type('safari')

    for (const key of ['ArrowDown', 'ArrowDown', 'Enter']) input.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }))
    await flushPromises()

    expect(router.currentRoute.value.fullPath).toBe('/w/acme/docs/9')
  })

  it('does not ask the server for a single character', async () => {
    await open()
    await type('s')

    expect(fetchMock.mock.calls.filter(([url]) => String(url).includes('/search'))).toHaveLength(0)
  })
})
