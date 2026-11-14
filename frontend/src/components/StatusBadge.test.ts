import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import i18n from '@/i18n'
import StatusBadge from '@/components/app/StatusBadge.vue'

describe('StatusBadge', () => {
  afterEach(() => {
    i18n.global.locale.value = 'en'
  })

  it('renders the English label for a state', () => {
    const wrapper = mount(StatusBadge, { props: { state: 'started' }, global: { plugins: [i18n] } })
    expect(wrapper.text()).toBe('In progress')
  })

  it('renders the translated label after switching locale', () => {
    i18n.global.locale.value = 'pl'
    const wrapper = mount(StatusBadge, { props: { state: 'completed' }, global: { plugins: [i18n] } })
    expect(wrapper.text()).toBe('Ukończone')
  })

  it('maps the state to a badge colour variant', () => {
    const wrapper = mount(StatusBadge, { props: { state: 'cancelled' }, global: { plugins: [i18n] } })
    expect(wrapper.classes()).toContain('bg-destructive')
  })
})
