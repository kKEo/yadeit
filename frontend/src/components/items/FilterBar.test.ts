import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import type { ItemFilters } from '@/api/types'
import i18n from '@/i18n'
import { makeProject } from '@/test/helpers'
import FilterBar from './FilterBar.vue'

function setup(filters: ItemFilters) {
  return mount(FilterBar, {
    props: {
      filters, project: null, rowLabels: ['backend'],
      projects: [makeProject()].map(({ states: _s, labels: _l, ...p }) => p),
      members: [{ id: 12, name: 'Bob', email: 'bob@example.com', type: 'human' as const, role: 'member' as const, joined_at: '' }],
    },
    global: { plugins: [i18n] },
  })
}

describe('FilterBar', () => {
  it('shows a readable chip for each active filter', () => {
    const wrapper = setup({ project: 'WEB', priority: 'high', assignee: '12', open: '1', q: 'login' })

    expect(wrapper.findAll('[data-filter]').map((chip) => chip.text())).toEqual([
      'Project: Website', 'Priority: High', 'Assignee: Bob', 'Open only',
    ])
    expect(wrapper.find<HTMLInputElement>('input[type="search"]').element.value).toBe('login')
  })

  it('removes one filter from its chip and all of them with "Clear all"', async () => {
    const wrapper = setup({ project: 'WEB', assignee: 'me' })

    await wrapper.find('[data-filter="assignee"] button[aria-label="Remove filter Assignee: Me"]').trigger('click')
    expect(wrapper.emitted('set')).toEqual([['assignee', null]])

    const clear = wrapper.findAll('button').find((button) => button.text() === 'Clear all')
    await clear!.trigger('click')
    expect(wrapper.emitted('clear')).toHaveLength(1)
  })

  it('has no chips and no "Clear all" without filters', () => {
    const wrapper = setup({})
    expect(wrapper.findAll('[data-filter]')).toHaveLength(0)
    expect(wrapper.text()).not.toContain('Clear all')
  })
})
