import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { PiniaColada } from '@pinia/colada'
import { createPinia, setActivePinia } from 'pinia'
import { computed, h } from 'vue'
import type { ItemDetail, Whiteboard } from '@/api/types'
import i18n from '@/i18n'
import { jsonResponse, makeDetail, routeFetch } from '@/test/helpers'
import ItemDescription from './ItemDescription.vue'
import ItemWhiteboard from './ItemWhiteboard.vue'

vi.mock('@/queries/keys', async (original) => ({
  ...(await original<typeof import('@/queries/keys')>()),
  useSlug: () => computed(() => 'acme'),
}))
// The editor brings the drawing library; here it only has to show that it was opened.
vi.mock('./WhiteboardEditor.vue', async () => {
  const { defineComponent, h } = await import('vue')
  return {
    __esModule: true,
    default: defineComponent({ props: { item: { type: Object, required: true } }, setup: (props) => () => h('div', { 'data-editor': '' }, props.item.key) }),
  }
})

const base = '/api/v1/workspaces/acme'
const ref = { version: 2, text: 'Cart\nPay', updated_at: '2026-11-14T16:40:00Z' }
const board: Whiteboard = {
  ...ref, scene: { elements: [{ id: 'a', type: 'text', text: 'Cart' }] }, updated_by: { id: 7, name: 'Ada' },
  preview_svg: '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="10" height="10"/></svg>',
}

describe('describing an item with a whiteboard', () => {
  const fetchMock = vi.fn<typeof fetch>()
  let deleted = 0

  function mountBoard(item: ItemDetail, canEdit = true) {
    const pinia = createPinia()
    setActivePinia(pinia)
    return mount(ItemWhiteboard, { props: { item, canEdit }, global: { plugins: [pinia, PiniaColada, i18n] }, attachTo: document.body })
  }

  function mountDescription(props: { description: string | null; canEdit: boolean; hasWhiteboard?: boolean }) {
    return mount(ItemDescription, { props, slots: { whiteboard: () => h('div', { 'data-board': '' }, 'the board') }, global: { plugins: [i18n] } })
  }

  beforeEach(() => {
    deleted = 0
    fetchMock.mockReset()
    fetchMock.mockImplementation(routeFetch({
      [`GET ${base}/items/WEB-1/whiteboard`]: () => jsonResponse({ data: board }),
      [`DELETE ${base}/items/WEB-1/whiteboard`]: () => { deleted++; return new Response(null, { status: 204 }) },
    }))
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    document.body.innerHTML = ''
  })

  it('keeps text as it was and offers the whiteboard as a second way to describe', async () => {
    const wrapper = mountDescription({ description: 'Three **steps**.', canEdit: true })
    const choice = () => wrapper.findAll('[role="group"] button')

    expect(choice().map((b) => [b.text(), b.attributes('aria-pressed')])).toEqual([['Text', 'true'], ['Whiteboard', 'false']])
    expect(wrapper.text()).toContain('Three steps.')
    expect(wrapper.find('[data-board]').exists()).toBe(false)
    expect(wrapper.findAll('button').some((b) => b.text() === 'Edit description')).toBe(true)

    await choice()[1].trigger('click')
    expect(wrapper.find('[data-board]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('Three steps.')
    expect(wrapper.findAll('button').some((b) => b.text() === 'Edit description')).toBe(false)

    await choice()[0].trigger('click')
    expect(wrapper.text()).toContain('Three steps.')
  })

  it('opens on the drawing when that is all there is, and hides the choice when there is nothing to choose', () => {
    expect(mountDescription({ description: null, canEdit: false, hasWhiteboard: true }).find('[data-board]').exists()).toBe(true)
    expect(mountDescription({ description: 'Text', canEdit: false, hasWhiteboard: true }).find('[data-board]').exists()).toBe(false)
    // Someone who cannot edit an item without a whiteboard has no use for the switch.
    expect(mountDescription({ description: 'Text', canEdit: false }).find('[role="group"]').exists()).toBe(false)
  })

  it('invites to draw when there is no whiteboard yet, without loading anything', async () => {
    const wrapper = mountBoard(makeDetail())
    await flushPromises()

    expect(wrapper.text()).toContain('No whiteboard yet.')
    expect(fetchMock).not.toHaveBeenCalled()
    expect(wrapper.find('[data-editor]').exists()).toBe(false)

    await wrapper.findAll('button').find((b) => b.text() === 'Draw on a whiteboard')!.trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-editor]').text()).toBe('WEB-1')

    expect(mountBoard(makeDetail(), false).findAll('button')).toHaveLength(0)
  })

  it('shows the drawing as a picture, never as markup', async () => {
    const wrapper = mountBoard(makeDetail({ whiteboard: ref }))
    await flushPromises()

    const image = wrapper.find('img')
    expect(image.attributes('src')).toMatch(/^data:image\/svg\+xml;charset=utf-8,%3Csvg/)
    expect(image.attributes('alt')).toBe('Whiteboard of WEB-1: Cart Pay')
    expect(wrapper.find('svg script').exists()).toBe(false)
    expect(wrapper.html()).not.toContain('<script')
    expect(wrapper.text()).toContain('by Ada')

    await wrapper.find('button[aria-label="Open the whiteboard of WEB-1"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-editor]').exists()).toBe(true)
  })

  it('lets a reader look but not change, and removes the whiteboard only after a confirmation', async () => {
    const reader = mountBoard(makeDetail({ whiteboard: ref }), false)
    await flushPromises()
    expect(reader.find('img').exists()).toBe(true)
    expect(reader.findAll('button')).toHaveLength(0)
    reader.unmount()

    const wrapper = mountBoard(makeDetail({ whiteboard: ref }))
    await flushPromises()
    await wrapper.findAll('button').find((b) => b.text() === 'Remove')!.trigger('click')
    await flushPromises()
    expect(deleted).toBe(0)

    const confirm = [...document.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Remove whiteboard')!
    confirm.click()
    await flushPromises()
    expect(deleted).toBe(1)
  })
})
