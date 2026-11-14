import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import i18n from '@/i18n'
import { makeTestRouter } from '@/test/helpers'
import LandingView from './LandingView.vue'
import LegalView from './LegalView.vue'

const plugins = () => [createPinia(), i18n, makeTestRouter()]

describe('LandingView', () => {
  it('says what the tool is and leads to sign-up, sign-in and the legal pages', () => {
    const wrapper = mount(LandingView, { global: { plugins: plugins() } })
    const links = wrapper.findAll('a').map((a) => a.attributes('href'))

    expect(wrapper.find('h1').text()).toBe('Project work that stays simple')
    expect(wrapper.findAll('[data-testid="landing-feature"]')).toHaveLength(4)
    expect(links).toEqual(expect.arrayContaining(['/register', '/login', '/terms', '/privacy', '/llms.txt']))
  })
})

describe('LegalView', () => {
  it('shows the terms as numbered sections and says that they are a template', () => {
    const wrapper = mount(LegalView, { props: { document: 'terms' }, global: { plugins: plugins() } })

    expect(wrapper.find('h1').text()).toBe('Terms of service')
    expect(wrapper.findAll('[data-testid="legal-section"]')).toHaveLength(9)
    expect(wrapper.find('[data-testid="legal-template"]').exists()).toBe(true)
    // The operator is named everywhere, and no placeholder braces are left in the text.
    expect(wrapper.text()).toContain('[operator name]')
    expect(wrapper.text()).not.toMatch(/\{(operator|email)\}/)
  })

  it('shows the privacy policy', () => {
    const wrapper = mount(LegalView, { props: { document: 'privacy' }, global: { plugins: plugins() } })

    expect(wrapper.find('h1').text()).toBe('Privacy policy')
    expect(wrapper.findAll('[data-testid="legal-section"]')).toHaveLength(7)
    expect(wrapper.text()).toContain('Download my data')
  })
})
