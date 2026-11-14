import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { PiniaColada } from '@pinia/colada'
import { createPinia, setActivePinia } from 'pinia'
import { computed } from 'vue'
import i18n from '@/i18n'
import { jsonResponse, makeDetail } from '@/test/helpers'
import ItemAttachments from './ItemAttachments.vue'
import ItemLinks from './ItemLinks.vue'

vi.mock('@/queries/keys', async (original) => ({
  ...(await original<typeof import('@/queries/keys')>()),
  useSlug: () => computed(() => 'acme'),
}))

function mountPart(component: typeof ItemLinks | typeof ItemAttachments, item: ReturnType<typeof makeDetail>, canEdit = true) {
  const pinia = createPinia()
  setActivePinia(pinia)
  return mount(component, { props: { item, canEdit }, global: { plugins: [pinia, PiniaColada, i18n] }, attachTo: document.body })
}

describe('design links', () => {
  const fetchMock = vi.fn<typeof fetch>()
  const figma = 'https://www.figma.com/design/abc123/Checkout'

  beforeEach(() => {
    fetchMock.mockReset()
    fetchMock.mockResolvedValue(jsonResponse({ data: { id: 3, kind: 'design', url: figma, title: null } }, 201))
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    document.body.innerHTML = ''
  })

  it('loads a design preview only when asked', async () => {
    const wrapper = mountPart(ItemLinks, makeDetail({ links: [
      { id: 1, kind: 'design', url: figma, title: 'Checkout mock-up' },
      { id: 2, kind: 'pr', url: 'https://github.com/acme/web/pull/42', title: null },
    ] }))

    // Nothing is fetched from the design tool until someone asks.
    expect(wrapper.find('iframe').exists()).toBe(false)
    expect(wrapper.findAll('button').filter((b) => b.text() === 'Show preview')).toHaveLength(1)

    await wrapper.findAll('button').find((b) => b.text() === 'Show preview')!.trigger('click')

    const frame = wrapper.find('iframe')
    expect(frame.attributes('src')).toBe(`https://www.figma.com/embed?embed_host=yadeit&url=${encodeURIComponent(figma)}`)
    expect(frame.attributes('sandbox')).toBe('allow-scripts allow-same-origin allow-popups')
    expect(frame.attributes('referrerpolicy')).toBe('no-referrer')

    await wrapper.findAll('button').find((b) => b.text() === 'Hide preview')!.trigger('click')
    expect(wrapper.find('iframe').exists()).toBe(false)
  })

  it('lets the server work out the kind of a new link', async () => {
    const wrapper = mountPart(ItemLinks, makeDetail({ links: [] }))

    await wrapper.find('input[type="url"]').setValue(figma)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    const post = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST')!
    expect(JSON.parse(String(post[1]!.body))).toEqual({ url: figma })
  })
})

describe('attachment gallery', () => {
  afterEach(() => { document.body.innerHTML = '' })

  const image = (id: number, name: string) => ({ id, name, mime_type: 'image/png', size: 2048, is_image: true, created_at: '' })
  const item = makeDetail({ attachments: [
    image(1, 'home.png'), image(2, 'cart.png'),
    { id: 3, name: 'spec.pdf', mime_type: 'application/pdf', size: 4096, is_image: false, created_at: '' },
  ] })

  it('shows images as a gallery and other files as a list', () => {
    const wrapper = mountPart(ItemAttachments, item)

    expect(wrapper.findAll('[data-gallery] img').map((img) => img.attributes('alt'))).toEqual(['home.png', 'cart.png'])
    expect(wrapper.text()).toContain('spec.pdf')
    expect(wrapper.find('[data-gallery]').text()).not.toContain('spec.pdf')
  })

  it('opens an image large and pages through the others with the arrow keys', async () => {
    const wrapper = mountPart(ItemAttachments, item)
    const shown = () => document.body.querySelector<HTMLImageElement>('[data-lightbox-image]')?.alt

    await wrapper.find('[aria-label="View home.png"]').trigger('click')
    await flushPromises()
    expect(shown()).toBe('home.png')
    expect(document.body.querySelector('[role="dialog"]')!.textContent).toContain('1 / 2')

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight' }))
    await flushPromises()
    expect(shown()).toBe('cart.png')

    // Past the last image it wraps around to the first.
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight' }))
    await flushPromises()
    expect(shown()).toBe('home.png')
  })

  it('offers no delete buttons to someone who cannot edit', () => {
    const wrapper = mountPart(ItemAttachments, item, false)

    expect(wrapper.findAll('[aria-label^="Remove"]')).toHaveLength(0)
  })
})
