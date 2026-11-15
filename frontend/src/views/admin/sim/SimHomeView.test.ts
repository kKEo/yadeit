import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type { SimRun, SimScenario } from '@/api/simulator'
import i18n from '@/i18n'
import { errorResponse, jsonResponse, makeTestRouter } from '@/test/helpers'
import SimHomeView from './SimHomeView.vue'
import { config, makeReport, makeRun, status } from './simFixtures'

describe('SimHomeView', () => {
  const fetchMock = vi.fn<typeof fetch>()
  let router: ReturnType<typeof makeTestRouter>
  let scenarios: SimScenario[]
  let runs: SimRun[]
  let enabled: boolean
  let standing: boolean

  async function open() {
    const pinia = createPinia()
    setActivePinia(pinia)
    router = makeTestRouter()
    const wrapper = mount(SimHomeView, { global: { plugins: [pinia, i18n, router] }, attachTo: document.body })
    await flushPromises()
    return wrapper
  }

  const calls = (method: string) => fetchMock.mock.calls
    .filter(([, init]) => (init?.method ?? 'GET') === method)
    .map(([url, init]) => ({ url: String(url), body: init?.body ? JSON.parse(String(init.body)) : null }))

  beforeEach(() => {
    enabled = true
    standing = false
    scenarios = [{ id: 2, name: 'Busy day', config: { ...config, teams: 8, speed: 50 }, updated_at: '2026-11-15T09:00:00Z' }]
    runs = [makeRun({ id: 6, name: 'Yesterday', status: 'finished', active: false, verdict: 'fail', totals: { ...makeReport().totals, requests: 900, p95_ms: 1200, failed_flows: 2 } })]
    fetchMock.mockReset()
    fetchMock.mockImplementation(async (url, init) => {
      const path = String(url)
      const method = init?.method ?? 'GET'
      if (path.endsWith('/admin/sim')) return enabled ? jsonResponse({ data: { ...status, standing_runner: standing } }) : errorResponse(404, 'not_found', 'Not found.')
      if (path.endsWith('/admin/sim/scenarios') && method === 'GET') return jsonResponse({ data: scenarios })
      if (path.endsWith('/admin/sim/scenarios') && method === 'POST') {
        const body = JSON.parse(String(init?.body))
        return jsonResponse({ data: { id: 3, name: body.name, config: { ...config, ...body.config }, updated_at: '2026-11-15T09:00:00Z' } }, 201)
      }
      if (path.endsWith('/admin/sim/scenarios/2') && method === 'DELETE') return new Response(null, { status: 204 })
      if (path.endsWith('/admin/sim/runs') && method === 'GET') return jsonResponse({ data: runs })
      if (path.endsWith('/admin/sim/runs') && method === 'POST') return jsonResponse({ data: { ...makeRun({ id: 8 }), runner_token: '8|secret-token' } }, 201)
      if (path.endsWith('/admin/sim/packs')) return jsonResponse({ data: [{ id: 0, name: 'Default', theme: 'A small product team', source: 'builtin', created_at: null }] })
      if (path.endsWith('/admin/sim/packs/0')) {
        return jsonResponse({ data: { theme: 'A small product team', people: ['A', 'B'], teams: ['T'], projects: [{ key: 'WEB', name: 'Web app', items: [{ title: 'Export invoices as CSV' }] }], comments: ['x'], pages: [], feedback: [] } })
      }
      return errorResponse(404, 'not_found', `No stub for ${method} ${path}`)
    })
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    document.body.innerHTML = ''
  })

  it('says so when the lab is switched off', async () => {
    enabled = false
    const wrapper = await open()

    expect(wrapper.text()).toContain('The Traffic lab is switched off')
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('lists scenarios, runs and content packs', async () => {
    const wrapper = await open()

    expect(wrapper.find('[data-testid="scenario-row"]').text()).toContain('Busy day')
    expect(wrapper.find('[data-testid="scenario-row"]').text()).toContain('50x')
    const run = wrapper.find('[data-testid="run-row"]')
    expect(run.text()).toContain('#6 Yesterday')
    expect(run.text()).toContain('Failed')
    expect(run.text()).toContain('1200 ms')
    expect(wrapper.find('[data-testid="pack-row"]').text()).toContain('Built in')
  })

  it('starts a run from the chosen scenario and shows the command for a runner once', async () => {
    const wrapper = await open()

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(calls('POST')[0]).toEqual({ url: expect.stringContaining('/admin/sim/runs'), body: { scenario_id: null } })
    expect(document.body.querySelector('[data-testid="runner-command"]')?.textContent).toContain("SIM_TOKEN='8|secret-token'")
  })

  it('goes straight to the run when a runner is standing by', async () => {
    standing = true
    const wrapper = await open()

    expect(wrapper.text()).toContain('A runner is standing by')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('admin-sim-run')
    expect(router.currentRoute.value.params.id).toBe('8')
    expect(document.body.querySelector('[data-testid="runner-command"]')).toBeNull()
  })

  it('offers no second run while one is going on', async () => {
    runs = [makeRun({ id: 9, name: 'Now' }), ...runs]
    const wrapper = await open()

    expect(wrapper.find('[data-testid="active-run"]').text()).toContain('"Now" is running')
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('saves a new scenario with the numbers typed into the form', async () => {
    const wrapper = await open()

    await wrapper.findAll('button').find((b) => b.text() === 'New scenario')!.trigger('click')
    await flushPromises()
    const input = (id: string) => document.body.querySelector<HTMLInputElement>(`#${id}`)!
    const type = (id: string, value: string) => { input(id).value = value; input(id).dispatchEvent(new Event('input')) }
    type('sim-name', 'Peak hour')
    type('sim-teams', '12')
    type('sim-real', '50')
    type('sim-errors', '2')
    await flushPromises()
    document.body.querySelector<HTMLFormElement>('[role="dialog"] form')!.dispatchEvent(new Event('submit', { cancelable: true }))
    await flushPromises()

    const saved = calls('POST')[0]!
    expect(saved.body.name).toBe('Peak hour')
    expect(saved.body.config).toMatchObject({ teams: 12, real_signup_share: 0.5, speed: 10, day_mode: 'workday', disabled_actions: [], thresholds: { p95_ms: 1000, error_rate: 0.02 } })
    expect(wrapper.findAll('[data-testid="scenario-row"]').map((r) => r.text()).join(' ')).toContain('Peak hour')
  })

  it('deletes a scenario after asking', async () => {
    const wrapper = await open()

    await wrapper.find('[data-testid="scenario-row"]').findAll('button').find((b) => b.text() === 'Delete')!.trigger('click')
    await flushPromises()
    const confirm = [...document.body.querySelectorAll<HTMLButtonElement>('[role="alertdialog"] button, [role="dialog"] button')].find((b) => b.textContent?.trim() === 'Delete')!
    confirm.click()
    await flushPromises()

    expect(calls('DELETE')[0]!.url).toContain('/admin/sim/scenarios/2')
    expect(wrapper.find('[data-testid="scenario-row"]').exists()).toBe(false)
  })

  it('previews a content pack', async () => {
    const wrapper = await open()

    await wrapper.find('[data-testid="pack-row"] button').trigger('click')
    await flushPromises()

    expect(document.body.textContent).toContain('Export invoices as CSV')
    expect(document.body.textContent).toContain('2 people')
  })
})
