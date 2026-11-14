import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { computed } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type { Router } from 'vue-router'
import type { BillingStatus, WorkspaceRole } from '@/api/types'
import i18n from '@/i18n'
import { useAuthStore } from '@/stores/auth'
import { errorResponse, jsonResponse, makeMe, makeTestRouter, makeWorkspace } from '@/test/helpers'
import BillingSection from './BillingSection.vue'

const holder = vi.hoisted(() => ({ router: null as Router | null, left: [] as string[] }))
vi.mock('@/router', () => ({
  default: new Proxy({}, { get: (_target, prop) => Reflect.get(holder.router as object, prop) }),
}))
vi.mock('@/queries/keys', async (original) => ({ ...(await original<typeof import('@/queries/keys')>()), useSlug: () => computed(() => 'acme') }))
vi.mock('@/lib/navigate', () => ({ leaveTo: (url: string) => { holder.left.push(url) } }))

function makeStatus(overrides: Partial<BillingStatus> = {}): BillingStatus {
  return {
    plan: 'free', limits_enforced: true, billing_enabled: true,
    limits: { members: 5, projects: 3, ai_tokens: 200000 }, usage: { members: 4, projects: 3 },
    plans: [
      { key: 'free', name: 'Free', members: 5, projects: 3, ai_tokens: 200000, purchasable: false },
      { key: 'team', name: 'Team', members: 50, projects: null, ai_tokens: 2000000, purchasable: true },
    ],
    subscription: null, has_billing_account: false, ...overrides,
  }
}

describe('BillingSection', () => {
  const fetchMock = vi.fn<typeof fetch>()
  let status: BillingStatus

  async function open(role: WorkspaceRole = 'owner', path = '/w/acme/settings') {
    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().user = makeMe({ workspaces: [makeWorkspace({ role })] })
    const router = makeTestRouter()
    holder.router = router
    await router.push(path)
    const wrapper = mount(BillingSection, { global: { plugins: [pinia, i18n, router] } })
    await flushPromises()
    return wrapper
  }

  beforeEach(() => {
    status = makeStatus()
    holder.left = []
    fetchMock.mockReset()
    fetchMock.mockImplementation(async (url, init) => {
      const path = String(url)
      if (path.endsWith('/billing')) return jsonResponse({ data: status })
      if (path.endsWith('/billing/checkout')) return jsonResponse({ data: { url: `https://pay.example/${JSON.parse(String(init?.body)).plan}` } })
      if (path.endsWith('/billing/portal')) return errorResponse(502, 'billing_failed', 'The payment service did not accept the request.')
      return errorResponse(404, 'not_found', path)
    })
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => vi.unstubAllGlobals())

  it('shows the plan with its usage and takes an owner to the payment page', async () => {
    const wrapper = await open()

    expect(wrapper.find('[data-testid="plan-name"]').text()).toBe('Free')
    const usage = wrapper.findAll('[data-testid="plan-usage"]')
    expect(usage[0].text()).toContain('4 of 5')
    expect(usage[1].text()).toContain('3 of 3')
    expect(wrapper.text()).toContain('Agents are free')

    await wrapper.findAll('button').find((b) => b.text() === 'Move to Team')!.trigger('click')
    await flushPromises()
    expect(holder.left).toEqual(['https://pay.example/team'])
  })

  it('offers the portal instead of a second purchase, and shows why it failed', async () => {
    status = makeStatus({ plan: 'team', limits: { members: 50, projects: null, ai_tokens: 2000000 }, subscription: { status: 'active', ends_at: '2026-12-31T00:00:00Z' }, has_billing_account: true })
    const wrapper = await open('owner', '/w/acme/settings?billing=success')

    expect(wrapper.text()).toContain('Thank you.')
    expect(wrapper.text()).toContain('3, no limit')
    expect(wrapper.text()).toContain('runs until')
    expect(wrapper.findAll('button').map((b) => b.text())).toEqual(['Manage billing'])

    await wrapper.find('button').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('The payment service did not accept the request.')
    expect(holder.left).toEqual([])
  })

  it('gives admins the picture without the buttons, and disappears without plans', async () => {
    const wrapper = await open('admin')
    expect(wrapper.findAll('button')).toHaveLength(0)
    expect(wrapper.text()).toContain('Only an owner can change the plan.')

    status = makeStatus({ limits_enforced: false, billing_enabled: false })
    expect((await open()).html()).toBe('<!--v-if-->')
  })
})
