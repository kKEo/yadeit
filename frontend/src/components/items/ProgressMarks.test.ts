import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import i18n from '@/i18n'
import { makeItem } from '@/test/helpers'
import ItemRow from './ItemRow.vue'

function row(overrides = {}) {
  return mount(ItemRow, { props: { item: makeItem(overrides), focused: false, selected: false }, global: { plugins: [i18n] } })
}

describe('progress marks on a row', () => {
  it('shows nothing for a plain item', () => {
    expect(row().findAll('[data-mark]')).toHaveLength(0)
  })

  it('marks a blocked item and shows sub-item progress', () => {
    const wrapper = row({ blocked: true, sub_items: { total: 5, done: 2 } })

    expect(wrapper.find('[data-mark="blocked"]').text()).toBe('Blocked')
    expect(wrapper.find('[data-mark="sub-items"]').text()).toContain('2/5')
    expect(wrapper.find('[data-mark="sub-items"]').text()).toContain('2 of 5 sub-items done')
  })
})
