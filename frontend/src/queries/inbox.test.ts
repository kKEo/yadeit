import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { PiniaColada } from '@pinia/colada'
import { createPinia } from 'pinia'
import { computed } from 'vue'
import type { InboxEntry } from '@/api/types'
import AppHeader from '@/components/app/AppHeader.vue'
import i18n from '@/i18n'
import { jsonResponse, makeTestRouter, routeFetch, withApp } from '@/test/helpers'
import { useInboxList, useInboxMutations } from './inbox'

vi.mock('./keys', async (original) => ({
  ...(await original<typeof import('./keys')>()),
  useSlug: () => computed(() => 'acme'),
}))

function entry(id: number, read: boolean): InboxEntry {
  return {
    id, event: 'commented', details: null, item: { key: `WEB-${id}`, title: `Item ${id}` },
    actor: { id: 9, name: 'Bob' }, read, snoozed_until: null, created_at: '2026-01-01T00:00:00Z',
  }
}

describe('inbox', () => {
  const fetchMock = vi.fn<typeof fetch>()
  let unread: number

  beforeEach(() => {
    unread = 2
    fetchMock.mockReset()
    fetchMock.mockImplementation(routeFetch({
      'GET /api/v1/workspaces/acme/inbox': () => jsonResponse({ data: [entry(5, false), entry(4, false), entry(3, true)], next_cursor: null, unread_count: unread }),
      'PATCH /api/v1/workspaces/acme/inbox/5': (init) => {
        const read = (JSON.parse(String(init?.body)) as { read: boolean }).read
        unread += read ? -1 : 1
        return jsonResponse({ data: entry(5, read), unread_count: unread })
      },
      'POST /api/v1/workspaces/acme/inbox/read-all': () => jsonResponse({ unread_count: 0 }),
    }))
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  async function setup() {
    const pinia = createPinia()
    const router = makeTestRouter()
    await router.push('/w/acme/inbox')
    const app = withApp(() => ({ list: useInboxList(() => false), ...useInboxMutations() }), pinia, router)
    const header = mount(AppHeader, {
      props: { slug: 'acme' },
      global: { plugins: [pinia, PiniaColada, i18n, router], stubs: { WorkspaceSwitcher: true, UserMenu: true, LocaleSwitcher: true } },
    })
    await flushPromises()
    const badge = () => header.find('[data-testid="inbox-badge"]')
    return { ...app.result, badge }
  }

  it('shows the unread count on the Inbox navigation entry', async () => {
    const { badge, list } = await setup()

    expect(badge().text()).toContain('2')
    expect(list.entries.value.map((e) => [e.id, e.read])).toEqual([[5, false], [4, false], [3, true]])
  })

  it('marking an entry read updates the row and the badge; unread puts it back', async () => {
    const { badge, list, update } = await setup()

    await update.mutateAsync({ id: 5, read: true })
    await flushPromises()

    expect(list.entries.value.find((e) => e.id === 5)?.read).toBe(true)
    expect(badge().text()).toContain('1')

    await update.mutateAsync({ id: 5, read: false })
    await flushPromises()
    expect(badge().text()).toContain('2')
  })

  it('"mark all read" clears the badge and the unread styling', async () => {
    const { badge, list, markAllRead } = await setup()

    await markAllRead.mutateAsync()
    await flushPromises()

    expect(badge().exists()).toBe(false)
    expect(list.entries.value.every((e) => e.read)).toBe(true)
  })
})
