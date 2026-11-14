import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { PiniaColada } from '@pinia/colada'
import { createPinia, setActivePinia } from 'pinia'
import { computed } from 'vue'
import type { Router } from 'vue-router'
import type { AiStatus, WorkspaceRole } from '@/api/types'
import i18n from '@/i18n'
import { useAuthStore } from '@/stores/auth'
import { errorResponse, jsonResponse, makeDetail, makeMe, makeProject, makeTestRouter, makeWorkspace, routeFetch } from '@/test/helpers'
import ItemAi from './ItemAi.vue'
import QuickCaptureForm from './QuickCaptureForm.vue'

const holder = vi.hoisted(() => ({ router: null as Router | null }))
vi.mock('@/router', () => ({
  default: new Proxy({}, { get: (_target, prop) => Reflect.get(holder.router as object, prop) }),
}))
vi.mock('@/queries/keys', async (original) => ({
  ...(await original<typeof import('@/queries/keys')>()),
  useSlug: () => computed(() => 'acme'),
}))

const base = '/api/v1/workspaces/acme'
const aiStatus = (overrides: Partial<AiStatus> = {}): AiStatus => ({
  enabled: true, configured: true, has_own_key: false, used_tokens: 0, monthly_token_limit: 1000, own_token_limit: null, model: 'claude-opus-5-5', ...overrides,
})

describe('AI help on items', () => {
  const fetchMock = vi.fn<typeof fetch>()
  let status: AiStatus
  let created: object[]

  async function mountWith<C>(component: C, props: object, role: WorkspaceRole = 'member') {
    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().user = makeMe({ workspaces: [makeWorkspace({ role })] })
    const router = makeTestRouter()
    holder.router = router
    await router.push('/w/acme/items')
    const wrapper = mount(component as never, { props: props as never, global: { plugins: [pinia, PiniaColada, i18n, router] }, attachTo: document.body })
    await flushPromises()
    return wrapper
  }

  beforeEach(() => {
    status = aiStatus()
    created = []
    localStorage.clear()
    fetchMock.mockReset()
    fetchMock.mockImplementation(routeFetch({
      [`GET ${base}/ai`]: () => jsonResponse({ data: status }),
      [`GET ${base}/projects`]: () => jsonResponse({ data: [makeProject()].map(({ states: _s, labels: _l, current_cycle: _c, ...p }) => p) }),
      [`GET ${base}/projects/WEB`]: () => jsonResponse({ data: makeProject() }),
      [`GET ${base}/members`]: () => jsonResponse({ data: [] }),
      [`POST ${base}/items/WEB-1/ai/summary`]: () => jsonResponse({ data: { summary: 'Login is half done.', decisions: ['Use cookies'], open_questions: ['Which provider?'] } }),
      [`POST ${base}/items/WEB-1/ai/split`]: () => jsonResponse({ data: { sub_items: [
        { title: 'Build the form', description: 'Fields and validation.' }, { title: 'Wire the API', description: 'Call the endpoint.' },
      ] } }),
      [`POST ${base}/ai/draft-item`]: () => jsonResponse({ data: {
        title: 'Add a coupon field to checkout', description: 'Let customers enter a code.', kind: 'feature', priority: 'high',
        labels: ['backend', 'not-a-label'], acceptance: ['A valid code lowers the total'],
      } }),
      [`POST ${base}/items`]: (init) => {
        created.push(JSON.parse(String(init!.body)))
        return jsonResponse({ data: makeDetail({ key: `WEB-${10 + created.length}` }) }, 201)
      },
      [`POST ${base}/items/WEB-11/checklist`]: (init) => {
        created.push({ checklist: JSON.parse(String(init!.body)) })
        return jsonResponse({ data: { id: 1, text: 'x', is_done: false } }, 201)
      },
    }))
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    document.body.innerHTML = ''
  })

  const click = (wrapper: Awaited<ReturnType<typeof mountWith>>, text: string) => wrapper.findAll('button').find((b) => b.text().startsWith(text))!.trigger('click')

  it('offers nothing while AI is switched off, unconfigured, or the viewer is a guest', async () => {
    status = aiStatus({ enabled: false })
    expect((await mountWith(ItemAi, { item: makeDetail() })).find('[data-item-ai]').exists()).toBe(false)

    status = aiStatus({ configured: false })
    expect((await mountWith(ItemAi, { item: makeDetail() })).find('[data-item-ai]').exists()).toBe(false)

    status = aiStatus()
    expect((await mountWith(ItemAi, { item: makeDetail() }, 'guest')).find('[data-item-ai]').exists()).toBe(false)

    // Not a single request for a suggestion was made.
    expect(fetchMock.mock.calls.filter(([, init]) => init?.method === 'POST')).toHaveLength(0)
  })

  it('shows a summary marked as a suggestion', async () => {
    const wrapper = await mountWith(ItemAi, { item: makeDetail() })

    await click(wrapper, 'Summarise')
    await flushPromises()

    const box = wrapper.find('[data-ai-summary]')
    expect(box.text()).toContain('Suggested by AI. Check it before you use it.')
    expect(box.text()).toContain('Login is half done.')
    expect(box.text()).toContain('Use cookies')
    expect(box.text()).toContain('Which provider?')
  })

  it('creates only the proposed sub-items the person keeps ticked', async () => {
    const wrapper = await mountWith(ItemAi, { item: makeDetail() })

    await click(wrapper, 'Suggest sub-items')
    await flushPromises()
    // Proposals alone create nothing.
    expect(created).toEqual([])

    await wrapper.find('#split-WEB-1-0').trigger('click')
    // One of the two is left ticked.
    await click(wrapper, 'Create 1 selected')
    await flushPromises()

    expect(created).toEqual([{ project: 'WEB', title: 'Wire the API', description: 'Call the endpoint.', parent: 'WEB-1' }])
    expect(wrapper.find('[data-ai-split]').exists()).toBe(false)
  })

  it('explains a failure instead of showing an empty suggestion', async () => {
    fetchMock.mockImplementation(routeFetch({
      [`GET ${base}/ai`]: () => jsonResponse({ data: status }),
      [`POST ${base}/items/WEB-1/ai/summary`]: () => errorResponse(429, 'ai_limit_reached', 'This workspace has used its AI allowance for the month.'),
    }))
    const wrapper = await mountWith(ItemAi, { item: makeDetail() })

    await click(wrapper, 'Summarise')
    await flushPromises()

    expect(wrapper.text()).toContain('This workspace has used its AI allowance for the month.')
    expect(wrapper.find('[data-ai-summary]').exists()).toBe(false)
  })

  it('drafts an item from a sentence, lets the person edit it, and creates it with its criteria', async () => {
    const wrapper = await mountWith(QuickCaptureForm, { filters: {} })

    await wrapper.find('[data-testid="capture-title"]').setValue('customers want coupon codes')
    await click(wrapper, 'Draft with AI')
    await flushPromises()

    expect((wrapper.find('[data-testid="capture-title"]').element as HTMLInputElement).value).toBe('Add a coupon field to checkout')
    expect(wrapper.find('[data-ai-draft]').text()).toContain('A valid code lowers the total')
    // Nothing exists yet: the draft only filled the form.
    expect(created).toEqual([])

    await wrapper.find('#capture-draft-description').setValue('Let customers enter a discount code.')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(created).toEqual([
      // "not-a-label" is not a label of the project, so it was dropped.
      { project: 'WEB', title: 'Add a coupon field to checkout', priority: 'high', labels: ['backend'], description: 'Let customers enter a discount code.', kind: 'feature' },
      { checklist: { text: 'A valid code lowers the total' } },
    ])
  })
})
