import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type { SimRunDetail } from '@/api/simulator'
import i18n from '@/i18n'
import { errorResponse, jsonResponse, makeTestRouter } from '@/test/helpers'
import SimRunView from './SimRunView.vue'
import { makeDetail, makeReport, makeRun, status } from './simFixtures'

describe('SimRunView', () => {
  const fetchMock = vi.fn<typeof fetch>()
  let router: ReturnType<typeof makeTestRouter>
  let detail: SimRunDetail

  async function open() {
    const pinia = createPinia()
    setActivePinia(pinia)
    router = makeTestRouter()
    await router.push('/admin/traffic/runs/7')
    const wrapper = mount(SimRunView, { global: { plugins: [pinia, i18n, router] }, attachTo: document.body })
    await flushPromises()
    return wrapper
  }

  const calls = (method: string) => fetchMock.mock.calls
    .filter(([, init]) => (init?.method ?? 'GET') === method)
    .map(([url, init]) => ({ url: String(url), body: init?.body ? JSON.parse(String(init.body)) : null }))

  const confirm = async (label: string) => {
    [...document.body.querySelectorAll<HTMLButtonElement>('[role="alertdialog"] button, [role="dialog"] button')].find((b) => b.textContent?.trim() === label)!.click()
    await flushPromises()
  }

  beforeEach(() => {
    detail = makeDetail()
    fetchMock.mockReset()
    fetchMock.mockImplementation(async (url, init) => {
      const path = String(url)
      const method = init?.method ?? 'GET'
      if (path.endsWith('/admin/sim')) return jsonResponse({ data: status })
      if (path.endsWith('/admin/sim/runs') && method === 'GET') {
        return jsonResponse({ data: [detail.run, makeRun({ id: 5, name: 'Last week', active: false, status: 'finished', totals: makeReport().totals })] })
      }
      if (path.endsWith('/admin/sim/runs/7') && method === 'GET') return jsonResponse({ data: detail })
      if (path.endsWith('/admin/sim/runs/7') && method === 'PATCH') {
        const body = JSON.parse(String(init?.body))
        if (body.speed) detail = { ...detail, run: { ...detail.run, speed: body.speed } }
        if (body.paused !== undefined) detail = { ...detail, run: { ...detail.run, status: body.paused ? 'paused' : 'running' } }
        return jsonResponse({ data: detail.run })
      }
      if (path.endsWith('/admin/sim/runs/7/stop')) {
        detail = { ...detail, run: makeRun({ active: false, status: 'finished', stop_reason: 'Stopped by an operator.' }) }
        return jsonResponse({ data: detail.run })
      }
      if (path.includes('/admin/sim/runs/7') && method === 'DELETE') {
        detail = { ...detail, run: { ...detail.run, purged: true }, teams: [] }
        return new Response(null, { status: 204 })
      }
      if (path.endsWith('/admin/sim/runs/7/compare/5')) {
        return jsonResponse({ data: { totals: { p95_ms: 70, baseline_p95_ms: 50 }, endpoints: [{ name: 'GET /api/v1/me', p95_ms: 200, baseline_p95_ms: 50, change_ms: 150, change_percent: 300 }] } })
      }
      return errorResponse(404, 'not_found', `No stub for ${method} ${path}`)
    })
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    document.body.innerHTML = ''
  })

  it('shows a run that is going on: verdict so far, numbers, charts, teams and what members do', async () => {
    const wrapper = await open()

    expect(wrapper.find('[data-testid="run-status"]').text()).toBe('Running')
    expect(wrapper.find('[data-testid="run-verdict"]').text()).toBe('Passing so far')
    expect(wrapper.text()).toContain('800')
    expect(wrapper.findAll('figure')).toHaveLength(4)
    expect(wrapper.find('figure svg[role="img"]').attributes('aria-label')).toContain('Requests per second')

    const teams = wrapper.findAll('[data-testid="team"]')
    expect(teams).toHaveLength(2)
    expect(teams[0]!.text()).toContain('Anna Kowalska')
    expect(teams[0]!.text()).toContain('openItems')
    expect(teams[1]!.text()).toContain('Signing up')
    expect(wrapper.findAll('[data-testid="flow-row"]')[0]!.text()).toContain('Item from creation to done')
    expect(wrapper.findAll('[data-testid="endpoint-row"]')).toHaveLength(2)
    expect(wrapper.find('[data-testid="no-runner"]').exists()).toBe(false)
  })

  it('changes the speed, pauses and adds a team', async () => {
    const wrapper = await open()

    await wrapper.find('[data-testid="speed-100"]').trigger('click')
    await flushPromises()
    expect(calls('PATCH')[0]!.body).toEqual({ speed: 100 })
    expect(wrapper.find('[data-testid="speed-100"]').attributes('aria-pressed')).toBe('true')

    await wrapper.find('[data-testid="pause"]').trigger('click')
    await flushPromises()
    expect(calls('PATCH')[1]!.body).toEqual({ paused: true })
    expect(wrapper.find('[data-testid="pause"]').text()).toBe('Resume')

    await wrapper.find('[data-testid="add-team"]').trigger('click')
    await flushPromises()
    expect(calls('PATCH')[2]!.body).toEqual({ add_teams: 1 })
  })

  it('warns when nothing plays the run, when the runner fell behind, and why it fails', async () => {
    detail = makeDetail(makeRun(), makeReport({ verdict: 'fail', runner_saturated: true, reasons: ['2 of 9 flows failed: createToSearch.'] }))
    detail.runners = []
    detail.findings = [{ id: 1, flow: 'createToSearch', step: 'find', severity: 'error', message: 'Search for "zq1" did not find WEB-4 within 10 s', details: null, created_at: '2026-11-15T10:02:00Z' }]
    const wrapper = await open()

    expect(wrapper.find('[data-testid="no-runner"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('The runner could not keep up')
    expect(wrapper.text()).toContain('2 of 9 flows failed: createToSearch.')
    expect(wrapper.find('[data-testid="run-verdict"]').text()).toBe('Failing')
    expect(wrapper.find('[data-testid="finding"]').text()).toContain('did not find WEB-4')
  })

  it('stops the run after asking, then offers the report, cleaning up and a comparison', async () => {
    const wrapper = await open()

    await wrapper.find('[data-testid="stop"]').trigger('click')
    await flushPromises()
    await confirm('Stop')

    expect(calls('POST')[0]!.url).toContain('/admin/sim/runs/7/stop')
    expect(wrapper.find('[data-testid="run-status"]').text()).toBe('Finished')
    expect(wrapper.text()).toContain('Stopped by an operator.')
    expect(wrapper.find('[data-testid="stop"]').exists()).toBe(false)

    await wrapper.find('[data-testid="purge"]').trigger('click')
    await flushPromises()
    await confirm('Remove')
    expect(calls('DELETE')[0]!.url).toMatch(/\/admin\/sim\/runs\/7$/)
    expect(wrapper.text()).toContain('Its workspaces and accounts have been removed.')
  })

  it('compares with an earlier run', async () => {
    detail = makeDetail(makeRun({ active: false, status: 'finished', verdict: 'pass' }))
    const wrapper = await open()

    // The picker is a popup in the real page; here the choice is made directly.
    await (wrapper.vm as unknown as { compare: (id: string) => Promise<void> }).compare('5')
    await flushPromises()

    const row = wrapper.find('[data-testid="compare-row"]')
    expect(row.text()).toContain('GET /api/v1/me')
    expect(row.text()).toContain('+150 ms (+300%)')
  })

  it('says so when the run does not exist', async () => {
    fetchMock.mockImplementation(async () => errorResponse(404, 'not_found', 'Not found.'))
    const wrapper = await open()

    expect(wrapper.text()).toContain('This run does not exist.')
  })
})
