import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { PiniaColada } from '@pinia/colada'
import { createPinia, setActivePinia } from 'pinia'
import { computed } from 'vue'
import type { AgentRun } from '@/api/types'
import i18n from '@/i18n'
import { jsonResponse } from '@/test/helpers'
import ItemRuns from './ItemRuns.vue'

vi.mock('@/queries/keys', async (original) => ({
  ...(await original<typeof import('@/queries/keys')>()),
  useSlug: () => computed(() => 'acme'),
}))

function makeRun(overrides: Partial<AgentRun> = {}): AgentRun {
  return {
    id: 1, agent: { id: 20, name: 'Coder' }, status: 'active', stale: false,
    started_at: '2026-01-01T10:00:00Z', last_activity_at: '2026-01-01T10:05:00Z', ended_at: null,
    entries: [
      { id: 1, type: 'thought', body: 'Plan: **read**, then fix', created_at: '2026-01-01T10:00:00Z' },
      { id: 2, type: 'action', body: 'Opened pull request #12', created_at: '2026-01-01T10:05:00Z' },
    ],
    ...overrides,
  }
}

describe('ItemRuns', () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => vi.unstubAllGlobals())

  async function open(runs: AgentRun[]) {
    fetchMock.mockResolvedValue(jsonResponse({ data: runs }))
    const pinia = createPinia()
    setActivePinia(pinia)
    const wrapper = mount(ItemRuns, { props: { itemKey: 'WEB-1' }, global: { plugins: [pinia, PiniaColada, i18n] } })
    await flushPromises()
    return wrapper
  }

  it('shows each run with its log in order, rendering Markdown', async () => {
    const wrapper = await open([makeRun()])

    expect(String(fetchMock.mock.calls[0][0])).toBe('/api/v1/workspaces/acme/items/WEB-1/runs')
    expect(wrapper.text()).toContain('Coder')
    expect(wrapper.text()).toContain('Working')
    expect(wrapper.findAll('[data-entry-type]').map((e) => e.attributes('data-entry-type'))).toEqual(['thought', 'action'])
    expect(wrapper.find('[data-entry-type="thought"] strong').text()).toBe('read')
  })

  it('never runs script from an agent\'s log', async () => {
    const wrapper = await open([makeRun({ entries: [{ id: 1, type: 'action', body: '<img src=x onerror="alert(1)"><script>alert(2)</script>ok', created_at: '2026-01-01T10:00:00Z' }] })])

    expect(wrapper.html()).not.toContain('onerror')
    expect(wrapper.html()).not.toContain('<script')
  })

  it('labels waiting, failed and silent runs', async () => {
    const wrapper = await open([
      makeRun({ id: 3, status: 'awaiting_input' }),
      makeRun({ id: 2, status: 'failed' }),
      makeRun({ id: 1, stale: true }),
    ])

    expect(wrapper.findAll('[data-run-status]').map((r) => r.attributes('data-run-status'))).toEqual(['awaiting_input', 'failed', 'stale'])
    expect(wrapper.text()).toContain('Waiting for an answer')
    expect(wrapper.text()).toContain('No sign of life')
  })

  it('says so when no agent has worked on the item', async () => {
    const wrapper = await open([])

    expect(wrapper.text()).toContain('No agent has worked on this item yet.')
  })
})
