import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { PiniaColada } from '@pinia/colada'
import { createPinia, setActivePinia } from 'pinia'
import { computed } from 'vue'
import i18n from '@/i18n'
import { jsonResponse, makeProject, routeFetch } from '@/test/helpers'
import ProjectPlanningDialog from './ProjectPlanningDialog.vue'

vi.mock('@/queries/keys', async (original) => ({
  ...(await original<typeof import('@/queries/keys')>()),
  useSlug: () => computed(() => 'acme'),
}))

const cycle = (number: number, status: string, completed: number, total: number) => ({
  id: number, number, name: `Cycle ${number}`, status, starts_on: '2026-11-16', ends_on: '2026-11-22',
  items: { total, completed, started: 0 },
})

describe('ProjectPlanningDialog', () => {
  const fetchMock = vi.fn<typeof fetch>()
  const base = '/api/v1/workspaces/acme/projects/WEB'

  beforeEach(() => {
    fetchMock.mockReset()
    fetchMock.mockImplementation(routeFetch({
      [`GET ${base}/cycles`]: () => jsonResponse({ data: [cycle(2, 'active', 1, 4), cycle(1, 'completed', 3, 3)] }),
      [`PATCH ${base}`]: () => jsonResponse({ data: makeProject({ cycle_weeks: 2 }) }),
    }))
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    document.body.innerHTML = ''
  })

  async function open() {
    const pinia = createPinia()
    setActivePinia(pinia)
    const { states: _s, labels: _l, current_cycle: _c, ...project } = makeProject({ cycle_weeks: 1, stale_after_days: 45 })
    const wrapper = mount(ProjectPlanningDialog, {
      props: { project }, global: { plugins: [pinia, PiniaColada, i18n] }, attachTo: document.body,
    })
    await flushPromises()
    return wrapper
  }

  it('shows the cycles so far with their progress', async () => {
    await open()
    const dialog = document.body.querySelector('[role="dialog"]')!

    expect([...dialog.querySelectorAll('[data-cycle]')].map((el) => el.getAttribute('data-cycle'))).toEqual(['2', '1'])
    expect(dialog.textContent).toContain('1 of 4 done')
    expect(dialog.textContent).toContain('3 of 3 done')
    expect(dialog.textContent).toContain('Now')
  })

  it('saves the settings as they stand, keeping an unusual stale period', async () => {
    await open()
    const dialog = document.body.querySelector('[role="dialog"]')!

    dialog.querySelector('form')!.dispatchEvent(new Event('submit', { cancelable: true }))
    await flushPromises()

    const patch = fetchMock.mock.calls.find(([, init]) => init?.method === 'PATCH')!
    expect(JSON.parse(String(patch[1]!.body))).toEqual({ cycle_weeks: 1, stale_after_days: 45 })
    expect(dialog.textContent).toContain('Saved.')
  })
})
