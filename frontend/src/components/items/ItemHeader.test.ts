import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { PiniaColada } from '@pinia/colada'
import { createPinia, setActivePinia } from 'pinia'
import { computed } from 'vue'
import type { ItemDetail } from '@/api/types'
import i18n from '@/i18n'
import { useUiStore } from '@/stores/ui'
import { jsonResponse, makeDetail, makeProject, makeTestRouter, routeFetch } from '@/test/helpers'
import ItemHeader from './ItemHeader.vue'
import ItemProperties from './ItemProperties.vue'

vi.mock('@/queries/keys', async (original) => ({
  ...(await original<typeof import('@/queries/keys')>()),
  useSlug: () => computed(() => 'acme'),
}))

const base = '/api/v1/workspaces/acme'
const full = () => makeDetail({
  state: { id: 4, name: 'In progress', group: 'started', color: '#f59e0b' }, priority: 'high', assignee: { id: 7, name: 'Ada Lovelace' },
  due_date: '2026-11-20', labels: [{ id: 1, name: 'backend', color: '#3b82f6' }], parent: 'WEB-3', blocked: true, demand: 2,
  relations: [{ id: 1, type: 'blocked_by', item: { key: 'WEB-9', title: 'Pick a provider', state: { id: 3, name: 'Todo', group: 'unstarted', color: '#64748b' } } }],
})

describe('item header', () => {
  const fetchMock = vi.fn<typeof fetch>()
  let patches: object[]

  async function mountHeader(item: ItemDetail, options: { variant?: 'peek' | 'page'; canEdit?: boolean } = {}) {
    const pinia = createPinia()
    setActivePinia(pinia)
    const router = makeTestRouter()
    await router.push('/w/acme/items')
    const wrapper = mount(ItemHeader, {
      props: { itemKey: item.key, item, canEdit: options.canEdit ?? true, variant: options.variant ?? 'peek' },
      global: { plugins: [pinia, PiniaColada, i18n, router] }, attachTo: document.body,
    })
    await flushPromises()
    return wrapper
  }

  const chip = (wrapper: Awaited<ReturnType<typeof mountHeader>>, label: string) => (
    wrapper.findAll('[role="group"] button').find((b) => b.attributes('aria-label')?.startsWith(label))
  )

  beforeEach(() => {
    patches = []
    fetchMock.mockReset()
    fetchMock.mockImplementation(routeFetch({
      [`GET ${base}/projects/WEB`]: () => jsonResponse({ data: makeProject() }),
      [`GET ${base}/members`]: () => jsonResponse({ data: [] }),
      [`PATCH ${base}/items/WEB-1`]: (init) => {
        patches.push(JSON.parse(String(init?.body)))
        return jsonResponse({ data: makeDetail({ version: 4 }) })
      },
    }))
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    document.body.innerHTML = ''
  })

  it('shows the properties of the side panel as chips, not as a table', async () => {
    const wrapper = await mountHeader(full())

    expect(wrapper.find('dl').exists()).toBe(false)
    expect(chip(wrapper, 'State')!.attributes('aria-label')).toBe('State: In progress')
    expect(chip(wrapper, 'Priority')!.text()).toBe('High')
    expect(chip(wrapper, 'Assignee')!.text()).toContain('Ada Lovelace')
    expect(chip(wrapper, 'Due date')!.attributes('aria-label')).toMatch(/^Due date: .*20/)
    expect(chip(wrapper, 'Labels')!.text()).toContain('backend')
    expect(wrapper.find('button[aria-label="Parent: WEB-3"]').exists()).toBe(true)
    expect(wrapper.find('[data-mark="blocked"]').text()).toBe('Blocked by WEB-9')
    expect(wrapper.find('[data-mark="demand"]').exists()).toBe(true)
  })

  it('keeps an unset property down to an icon, and hides it from someone who cannot set it', async () => {
    const editor = await mountHeader(makeDetail())
    for (const label of ['Priority', 'Assignee', 'Due date', 'Labels']) {
      expect(chip(editor, label)!.attributes('data-empty')).toBe('true')
      expect(chip(editor, label)!.text()).toBe('')
    }
    editor.unmount()

    const viewer = await mountHeader(makeDetail(), { canEdit: false })
    expect(chip(viewer, 'State')!.attributes('disabled')).toBeDefined()
    for (const label of ['Priority', 'Assignee', 'Due date', 'Labels']) expect(chip(viewer, label)).toBeUndefined()
  })

  it('opens the picker a shortcut asked for and saves the choice', async () => {
    const wrapper = await mountHeader(makeDetail())
    useUiStore().requestProperty('WEB-1', 'state')
    await flushPromises()

    const options = [...document.querySelectorAll<HTMLElement>('[role="listbox"][aria-label="State"] [role="option"]')]
    expect(options.map((o) => o.textContent)).toEqual(expect.arrayContaining([expect.stringContaining('In progress')]))
    expect(useUiStore().propertyRequest).toBeNull()

    options.find((o) => o.textContent?.includes('In progress'))!.click()
    await flushPromises()
    expect(patches).toHaveLength(1)
    expect(patches[0]).toMatchObject({ state: 4 })
    wrapper.unmount()
  })

  it('offers Expand and Close in the panel, and Collapse with a way back on the full page', async () => {
    const peek = await mountHeader(makeDetail())
    await peek.find('button[aria-label="Expand to full page"]').trigger('click')
    await peek.find('button[aria-label="Close"]').trigger('click')
    expect(peek.emitted('expand')).toHaveLength(1)
    expect(peek.emitted('close')).toHaveLength(1)
    expect(peek.find('button[aria-label="Collapse to side panel"]').exists()).toBe(false)
    peek.unmount()

    const page = await mountHeader(full(), { variant: 'page' })
    await page.find('button[aria-label="Collapse to side panel"]').trigger('click')
    expect(page.emitted('collapse')).toHaveLength(1)
    expect(page.find('button[aria-label="Close"]').exists()).toBe(false)
    expect(page.find('a[href="/w/acme/items"]').text()).toBe('Items')
    // Labels live in the details column there, and that column answers the shortcut for them.
    expect(chip(page, 'Labels')).toBeUndefined()
    useUiStore().requestProperty('WEB-1', 'labels')
    await flushPromises()
    expect(useUiStore().propertyRequest).not.toBeNull()
  })

  it('lists the remaining properties in the details column of the full page', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    const wrapper = mount(ItemProperties, { props: { item: full(), canEdit: true }, global: { plugins: [pinia, PiniaColada, i18n] }, attachTo: document.body })
    await flushPromises()

    const terms = wrapper.findAll('dt').map((dt) => dt.text())
    expect(terms).toEqual(['Labels', 'Project', 'Created', 'Updated'])
    useUiStore().requestProperty('WEB-1', 'labels')
    await flushPromises()
    expect(document.querySelector('[role="listbox"][aria-label="Labels"]')).not.toBeNull()
  })
})
