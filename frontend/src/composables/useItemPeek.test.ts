import { describe, expect, it } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { useItemPeek } from '@/composables/useItemPeek'
import { withApp } from '@/test/helpers'

describe('side panel and full page', () => {
  it('expands the panel to the full page and collapses back to where it was', async () => {
    const { result: peek, router } = withApp(() => useItemPeek())
    await router.push('/w/acme/items?layout=board&item=WEB-1')

    await peek.expand()
    expect(router.currentRoute.value.fullPath).toBe('/w/acme/items/WEB-1')
    expect(peek.expandedFromPeek()).toBe(true)

    void peek.collapse()
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe('/w/acme/items?layout=board&item=WEB-1')
    expect(peek.peekKey.value).toBe('WEB-1')
  })

  it('collapses a full page opened from a link to the panel over the items list', async () => {
    const { result: peek, router } = withApp(() => useItemPeek())
    await router.push('/w/acme/items/web-7')
    expect(peek.expandedFromPeek()).toBe(false)

    await peek.collapse()
    expect(router.currentRoute.value.fullPath).toBe('/w/acme/items?item=WEB-7')
  })

  it('does nothing when there is no panel to expand or page to collapse', async () => {
    const { result: peek, router } = withApp(() => useItemPeek())
    await router.push('/w/acme/items')

    await peek.expand()
    await peek.collapse()
    expect(router.currentRoute.value.fullPath).toBe('/w/acme/items')
  })
})
